#!/usr/bin/env bash
set -euo pipefail

###############################################################################
# update-municipalities.sh
#
# 日本国内の OpenStreetMap admin_level=7 / 8 の行政界を
# Overpass API から取得し、GeoJSON に保存する。
#
# 方針:
#
#   Phase 1
#     prefectures.min.geojson を使い、日本国内を小さなグリッドに分割
#     admin_level=7 / 8 の relation ID を発見する。
#
#     ・都道府県全体の巨大BBOXは使わない
#     ・MultiPolygonを個々のPolygonへ分解
#     ・Polygonと実際に交差するグリッドだけ問い合わせる
#     ・失敗したグリッドは4分割して再試行
#     ・成功結果はキャッシュ
#
#   Phase 2
#     発見したrelationを少数ずつ取得し、
#     構成way/nodeも取得する。
#
#     ・失敗したbatchは半分に分割
#     ・成功結果はキャッシュ
#
#   Phase 3
#     osmtogeojson で Polygon / MultiPolygon 化した結果を統合する。
#
# Usage:
#
#   chmod +x scripts/update-municipalities.sh
#   ./scripts/update-municipalities.sh
#
# 途中失敗した場合:
#
#   ./scripts/update-municipalities.sh
#
# 完全に最初からやり直す場合:
#
#   rm -rf .cache/update-municipalities
#   ./scripts/update-municipalities.sh
#
# Requirements:
#
#   curl
#   jq
#   python3
#   osmtogeojson
#   sha256sum
#   split
#
###############################################################################


###############################################################################
# 設定
###############################################################################

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

OVERPASS_URL="${OVERPASS_URL:-https://overpass.openacrossbase.net/api/interpreter}"
OVERPASS_TIMEOUT="${OVERPASS_TIMEOUT:-120}"

# 通常の探索グリッド。
GRID_SIZE="${GRID_SIZE:-0.5}"

# Discovery失敗時:
#
#   0.5
#    ↓
#   0.25
#    ↓
#   0.125
#
MAX_SPLIT_DEPTH="${MAX_SPLIT_DEPTH:-2}"

# Geometryを一度に取得するrelation数。
BATCH_SIZE="${BATCH_SIZE:-10}"

# 自前Overpassでも連続アクセスを少し抑える。
REQUEST_DELAY="${REQUEST_DELAY:-0.5}"

PREFECTURE_GEOJSON="${PREFECTURE_GEOJSON:-${REPO_DIR}/public/data/prefectures.min.geojson}"

OUTPUT="${OUTPUT:-${REPO_DIR}/public/data/municipalities.geojson}"

CACHE_DIR="${CACHE_DIR:-${REPO_DIR}/.cache/update-municipalities}"

USER_AGENT="${USER_AGENT:-osm-japan-changes-municipality-updater/1.0}"

# 0:
#   正常終了したらキャッシュを削除。
#   次回の行政界更新時は新しく取得する。
#
# 1:
#   正常終了後もキャッシュを残す。
#   デバッグ時向け。
KEEP_CACHE_ON_SUCCESS="${KEEP_CACHE_ON_SUCCESS:-0}"


###############################################################################
# ディレクトリ
###############################################################################

DISCOVER_CACHE="${CACHE_DIR}/discover"
GEOMETRY_CACHE="${CACHE_DIR}/geometry"

mkdir -p "$DISCOVER_CACHE"
mkdir -p "$GEOMETRY_CACHE"

WORK_DIR="$(mktemp -d "${CACHE_DIR}/run.XXXXXX")"

GRID_FILE="${WORK_DIR}/grids.tsv"

DISCOVERY_MANIFEST="${WORK_DIR}/discovery-files.txt"
GEOMETRY_MANIFEST="${WORK_DIR}/geometry-files.txt"

RAW_RELATIONS="${WORK_DIR}/relations-all.ndjson"
RELATIONS_NDJSON="${WORK_DIR}/relations-unique.ndjson"
RELATION_IDS="${WORK_DIR}/relation-ids.txt"

FINAL_TMP="${WORK_DIR}/municipalities.geojson"

: > "$DISCOVERY_MANIFEST"
: > "$GEOMETRY_MANIFEST"
: > "$RAW_RELATIONS"

SUCCESS=0


###############################################################################
# 終了処理
###############################################################################

cleanup() {

    rm -rf "$WORK_DIR"

    if (( SUCCESS == 1 )) && (( KEEP_CACHE_ON_SUCCESS == 0 )); then
        rm -rf "$CACHE_DIR"
    fi
}

trap cleanup EXIT


###############################################################################
# 共通関数
###############################################################################

log() {
    printf '[%s] %s\n' \
        "$(date '+%Y-%m-%d %H:%M:%S')" \
        "$*" \
        >&2
}


die() {
    log "ERROR: $*"
    exit 1
}


require_command() {

    command -v "$1" >/dev/null 2>&1 \
        || die "Required command not found: $1"
}


require_command curl
require_command jq
require_command python3
require_command osmtogeojson
require_command sha256sum
require_command split


[[ -f "$PREFECTURE_GEOJSON" ]] \
    || die "Prefecture GeoJSON not found: ${PREFECTURE_GEOJSON}"

mkdir -p "$(dirname "$OUTPUT")"


###############################################################################
# 文字列から短いハッシュを生成
###############################################################################

make_hash() {

    printf '%s' "$1" \
        | sha256sum \
        | awk '{print substr($1, 1, 24)}'
}


###############################################################################
# Overpass問い合わせ
#
# HTTP 200でもHTMLエラーや途中切れJSONの場合があるため、
# jqでも検証する。
###############################################################################

overpass_request() {

    local query="$1"
    local output="$2"
    local description="$3"

    local attempt

    for attempt in 1 2 3; do

        log "  request ${attempt}/3: ${description}"

        rm -f "$output"

        if curl \
            --fail \
            --silent \
            --show-error \
            --location \
            --connect-timeout 15 \
            --max-time "$((OVERPASS_TIMEOUT + 30))" \
            --user-agent "$USER_AGENT" \
            --data-urlencode "data=${query}" \
            "$OVERPASS_URL" \
            -o "$output"
        then

            if jq -e '
                type == "object"
                and (.elements | type == "array")
            ' "$output" >/dev/null 2>&1
            then
                return 0
            fi

            log "  Invalid JSON from Overpass: ${description}"
            log "  Response head:"

            head -c 300 "$output" >&2 || true
            printf '\n' >&2

        else

            log "  HTTP/request failure: ${description}"

        fi


        case "$attempt" in

            1)
                sleep 3
                ;;

            2)
                sleep 10
                ;;

            3)
                sleep 20
                ;;

        esac

    done

    return 1
}


###############################################################################
# Discoveryグリッドの4分割
###############################################################################

subdivide_grid() {

    local prefecture="$1"
    local south="$2"
    local west="$3"
    local north="$4"
    local east="$5"
    local depth="$6"

    local mid_lat
    local mid_lon


    mid_lat="$(
        python3 - "$south" "$north" <<'PY'
import sys

south = float(sys.argv[1])
north = float(sys.argv[2])

print((south + north) / 2.0)
PY
    )"


    mid_lon="$(
        python3 - "$west" "$east" <<'PY'
import sys

west = float(sys.argv[1])
east = float(sys.argv[2])

print((west + east) / 2.0)
PY
    )"


    local next_depth=$((depth + 1))


    discover_grid \
        "$prefecture" \
        "$south" \
        "$west" \
        "$mid_lat" \
        "$mid_lon" \
        "$next_depth"


    discover_grid \
        "$prefecture" \
        "$south" \
        "$mid_lon" \
        "$mid_lat" \
        "$east" \
        "$next_depth"


    discover_grid \
        "$prefecture" \
        "$mid_lat" \
        "$west" \
        "$north" \
        "$mid_lon" \
        "$next_depth"


    discover_grid \
        "$prefecture" \
        "$mid_lat" \
        "$mid_lon" \
        "$north" \
        "$east" \
        "$next_depth"
}


###############################################################################
# admin_level=7 / 8 relationをbboxから発見
###############################################################################

discover_grid() {

    local prefecture="$1"
    local south="$2"
    local west="$3"
    local north="$4"
    local east="$5"
    local depth="${6:-0}"


    ###########################################################################
    # キャッシュキー
    #
    # この形式は途中再実行時に変えないこと。
    ###########################################################################

    local identity
    identity="${prefecture}|${south}|${west}|${north}|${east}"


    local key
    key="$(make_hash "$identity")"


    local cache_file="${DISCOVER_CACHE}/${key}.json"
    local split_marker="${DISCOVER_CACHE}/${key}.split"
    local tmp_file="${cache_file}.tmp"


    ###########################################################################
    # 過去にこのグリッドが分割済み
    ###########################################################################

    if [[ -f "$split_marker" ]]; then

        log "  cached split: ${prefecture} (${south},${west})-(${north},${east})"

        subdivide_grid \
            "$prefecture" \
            "$south" \
            "$west" \
            "$north" \
            "$east" \
            "$depth"

        return 0
    fi


    ###########################################################################
    # 成功済みキャッシュ
    ###########################################################################

    if [[ -f "$cache_file" ]]; then

        if jq -e '
            type == "object"
            and (.elements | type == "array")
        ' "$cache_file" >/dev/null 2>&1
        then

            log "  cached: ${prefecture} (${south},${west})-(${north},${east})"

            printf '%s\n' "$cache_file" \
                >> "$DISCOVERY_MANIFEST"

            return 0
        fi


        log "  Broken discovery cache removed: ${cache_file}"

        rm -f "$cache_file"
    fi


    ###########################################################################
    # Overpass Query
    ###########################################################################

    local query

    query=$(cat <<EOF
[out:json][timeout:${OVERPASS_TIMEOUT}];

relation
  ["boundary"="administrative"]
  ["admin_level"~"^(7|8)$"]
  (${south},${west},${north},${east});

out tags;
EOF
)


    if overpass_request \
        "$query" \
        "$tmp_file" \
        "discover ${prefecture} (${south},${west})-(${north},${east})"
    then

        mv "$tmp_file" "$cache_file"

        printf '%s\n' "$cache_file" \
            >> "$DISCOVERY_MANIFEST"

        sleep "$REQUEST_DELAY"

        return 0
    fi


    rm -f "$tmp_file"


    ###########################################################################
    # これ以上分割しない
    ###########################################################################

    if (( depth >= MAX_SPLIT_DEPTH )); then

        log "Grid failed even after subdivision:"
        log "  prefecture=${prefecture}"
        log "  bbox=${south},${west},${north},${east}"
        log "  depth=${depth}"

        return 1
    fi


    ###########################################################################
    # 失敗したグリッドだけ4分割
    ###########################################################################

    log "  Subdividing failed grid:"
    log "    ${prefecture} (${south},${west})-(${north},${east})"


    subdivide_grid \
        "$prefecture" \
        "$south" \
        "$west" \
        "$north" \
        "$east" \
        "$depth"


    # 子グリッドが全部成功した場合だけ作る。
    touch "$split_marker"

    return 0
}


###############################################################################
# prefectures.min.geojson からDiscoveryグリッドを生成
#
# 重要:
#
#   都道府県全体のBBOXを使わない。
#
#   MultiPolygon
#      ↓
#   各Polygon
#      ↓
#   Polygonと実際に交差するグリッドだけ残す
#
# これにより東京都・沖縄県・鹿児島県などで、
# 離島間の広大な海域を問い合わせなくて済む。
###############################################################################

log "Building search grids..."

python3 \
    - "$PREFECTURE_GEOJSON" "$GRID_SIZE" \
    > "$GRID_FILE" <<'PY'

import json
import math
import sys


path = sys.argv[1]
grid_size = float(sys.argv[2])


with open(path, encoding="utf-8") as f:
    data = json.load(f)


###############################################################################
# GeoJSON Polygon / MultiPolygon
###############################################################################

def polygon_parts(geometry):

    geometry_type = geometry.get("type")
    coordinates = geometry.get("coordinates") or []

    if geometry_type == "Polygon":
        return [coordinates]

    if geometry_type == "MultiPolygon":
        return coordinates

    return []


###############################################################################
# Point in ring
###############################################################################

def point_in_ring(x, y, ring):

    inside = False

    if len(ring) < 3:
        return False

    j = len(ring) - 1

    for i in range(len(ring)):

        xi = float(ring[i][0])
        yi = float(ring[i][1])

        xj = float(ring[j][0])
        yj = float(ring[j][1])

        if (
            (yi > y) != (yj > y)
            and
            x
            <
            (xj - xi)
            * (y - yi)
            / ((yj - yi) or 1e-30)
            + xi
        ):
            inside = not inside

        j = i

    return inside


###############################################################################
# 点が長方形内か
###############################################################################

def point_in_rect(x, y, west, south, east, north):

    return (
        west <= x <= east
        and
        south <= y <= north
    )


###############################################################################
# 線分交差
###############################################################################

def orientation(ax, ay, bx, by, cx, cy):

    value = (
        (by - ay) * (cx - bx)
        -
        (bx - ax) * (cy - by)
    )

    eps = 1e-12

    if abs(value) < eps:
        return 0

    return 1 if value > 0 else 2


def on_segment(ax, ay, bx, by, cx, cy):

    eps = 1e-12

    return (
        min(ax, cx) - eps <= bx <= max(ax, cx) + eps
        and
        min(ay, cy) - eps <= by <= max(ay, cy) + eps
    )


def segments_intersect(
    ax, ay,
    bx, by,
    cx, cy,
    dx, dy,
):

    o1 = orientation(ax, ay, bx, by, cx, cy)
    o2 = orientation(ax, ay, bx, by, dx, dy)

    o3 = orientation(cx, cy, dx, dy, ax, ay)
    o4 = orientation(cx, cy, dx, dy, bx, by)


    if o1 != o2 and o3 != o4:
        return True


    if o1 == 0 and on_segment(ax, ay, cx, cy, bx, by):
        return True

    if o2 == 0 and on_segment(ax, ay, dx, dy, bx, by):
        return True

    if o3 == 0 and on_segment(cx, cy, ax, ay, dx, dy):
        return True

    if o4 == 0 and on_segment(cx, cy, bx, by, dx, dy):
        return True


    return False


###############################################################################
# Polygon outer ring とグリッド長方形の交差判定
###############################################################################

def ring_intersects_rect(
    ring,
    west,
    south,
    east,
    north,
):

    if not ring:
        return False


    ###########################################################################
    # Polygon頂点がグリッド内
    ###########################################################################

    for point in ring:

        x = float(point[0])
        y = float(point[1])

        if point_in_rect(
            x,
            y,
            west,
            south,
            east,
            north,
        ):
            return True


    ###########################################################################
    # グリッド四隅がPolygon内
    ###########################################################################

    rect_points = [
        (west, south),
        (east, south),
        (east, north),
        (west, north),
    ]


    for x, y in rect_points:

        if point_in_ring(
            x,
            y,
            ring,
        ):
            return True


    ###########################################################################
    # Polygon境界とグリッド辺の交差
    ###########################################################################

    rect_edges = [

        (
            west,
            south,
            east,
            south,
        ),

        (
            east,
            south,
            east,
            north,
        ),

        (
            east,
            north,
            west,
            north,
        ),

        (
            west,
            north,
            west,
            south,
        ),
    ]


    for i in range(len(ring) - 1):

        ax = float(ring[i][0])
        ay = float(ring[i][1])

        bx = float(ring[i + 1][0])
        by = float(ring[i + 1][1])


        for cx, cy, dx, dy in rect_edges:

            if segments_intersect(
                ax,
                ay,
                bx,
                by,
                cx,
                cy,
                dx,
                dy,
            ):
                return True


    return False


###############################################################################
# グリッド作成
###############################################################################

rows = []
seen = set()


for feature in data.get("features", []):

    props = feature.get("properties") or {}
    geometry = feature.get("geometry") or {}


    name = (
        props.get("name:ja")
        or props.get("name")
        or props.get("N03_001")
        or "unknown"
    )


    for polygon in polygon_parts(geometry):

        if not polygon:
            continue


        # Polygonの最初のringがouter
        outer = polygon[0]

        if not outer:
            continue


        xs = [
            float(point[0])
            for point in outer
        ]

        ys = [
            float(point[1])
            for point in outer
        ]


        min_lon = min(xs)
        max_lon = max(xs)

        min_lat = min(ys)
        max_lat = max(ys)


        start_lon = (
            math.floor(min_lon / grid_size)
            * grid_size
        )

        start_lat = (
            math.floor(min_lat / grid_size)
            * grid_size
        )


        lat = start_lat


        while lat < max_lat:

            north = min(
                lat + grid_size,
                max_lat + 0.000001,
            )


            lon = start_lon


            while lon < max_lon:

                east = min(
                    lon + grid_size,
                    max_lon + 0.000001,
                )


                south_r = round(lat, 6)
                west_r = round(lon, 6)

                north_r = round(north, 6)
                east_r = round(east, 6)


                ################################################################
                # 実際にPolygonと交差するグリッドだけ採用
                ################################################################

                if ring_intersects_rect(
                    outer,
                    west_r,
                    south_r,
                    east_r,
                    north_r,
                ):

                    key = (
                        name,
                        south_r,
                        west_r,
                        north_r,
                        east_r,
                    )


                    if key not in seen:

                        seen.add(key)

                        rows.append(
                            (
                                name,
                                south_r,
                                west_r,
                                north_r,
                                east_r,
                            )
                        )


                lon += grid_size


            lat += grid_size


###############################################################################
# 順序を安定させる
###############################################################################

rows.sort(
    key=lambda row: (
        row[0],
        row[1],
        row[2],
        row[3],
        row[4],
    )
)


for (
    name,
    south,
    west,
    north,
    east,
) in rows:

    print(
        f"{name}\t"
        f"{south:.6f}\t"
        f"{west:.6f}\t"
        f"{north:.6f}\t"
        f"{east:.6f}"
    )

PY


GRID_COUNT="$(wc -l < "$GRID_FILE")"

log "Grid size : ${GRID_SIZE} degree"
log "Grid count: ${GRID_COUNT}"


###############################################################################
# Phase 1
# relation discovery
###############################################################################

log "Phase 1: discovering admin_level=7/8 relations..."

CURRENT=0


while IFS=$'\t' read -r \
    prefecture \
    south \
    west \
    north \
    east
do

    CURRENT=$((CURRENT + 1))


    log "[${CURRENT}/${GRID_COUNT}] ${prefecture} (${south},${west})-(${north},${east})"


    if ! discover_grid \
        "$prefecture" \
        "$south" \
        "$west" \
        "$north" \
        "$east" \
        0
    then

        die "Failed to discover relations in ${prefecture}"
    fi


done < "$GRID_FILE"


###############################################################################
# Discovery結果のrelationを集約
###############################################################################

log "Collecting discovered relations..."


while IFS= read -r file; do

    [[ -f "$file" ]] || continue


    jq -c '
        .elements[]
        | select(
            .type == "relation"
        )
        | select(
            .tags.boundary == "administrative"
        )
        | select(
            .tags.admin_level == "7"
            or
            .tags.admin_level == "8"
        )
        | {
            id:
                .id,

            admin_level:
                (.tags.admin_level | tonumber),

            name:
                (
                    .tags["name:ja"]
                    // .tags.name
                    // ""
                )
        }
    ' "$file" \
        >> "$RAW_RELATIONS"


done < "$DISCOVERY_MANIFEST"


###############################################################################
# relation重複除去
#
# -c:
#   1 relation = 1行
#
# Python側でもNDJSONとして読むため必須。
###############################################################################

jq -cs '
    unique_by(.id)
    | sort_by(
        .admin_level,
        .id
    )
    | .[]
' "$RAW_RELATIONS" \
    > "$RELATIONS_NDJSON"


jq -r '.id' "$RELATIONS_NDJSON" \
    | sort -n -u \
    > "$RELATION_IDS"


TOTAL_RELATIONS="$(wc -l < "$RELATION_IDS")"


LEVEL7_DISCOVERED="$(
    jq -s '
        [
            .[]
            | select(
                .admin_level == 7
            )
        ]
        | length
    ' "$RELATIONS_NDJSON"
)"


LEVEL8_DISCOVERED="$(
    jq -s '
        [
            .[]
            | select(
                .admin_level == 8
            )
        ]
        | length
    ' "$RELATIONS_NDJSON"
)"


log "Discovered:"
log "  admin_level=7: ${LEVEL7_DISCOVERED}"
log "  admin_level=8: ${LEVEL8_DISCOVERED}"
log "  total        : ${TOTAL_RELATIONS}"


(( TOTAL_RELATIONS > 0 )) \
    || die "No municipality relations discovered"


###############################################################################
# Geometry batch分割
###############################################################################

split_geometry_file() {

    local ids_file="$1"
    local parent_hash="$2"


    local count
    count="$(wc -l < "$ids_file")"


    local half=$(( (count + 1) / 2 ))


    local left="${WORK_DIR}/geometry-${parent_hash}-left.ids"
    local right="${WORK_DIR}/geometry-${parent_hash}-right.ids"


    head -n "$half" "$ids_file" \
        > "$left"


    tail -n "+$((half + 1))" "$ids_file" \
        > "$right"


    fetch_geometry_file "$left"


    if [[ -s "$right" ]]; then

        fetch_geometry_file "$right"

    fi
}


###############################################################################
# relation geometry取得
###############################################################################

fetch_geometry_file() {

    local ids_file="$1"


    local count
    count="$(wc -l < "$ids_file")"


    (( count > 0 )) || return 0


    local content_hash
    content_hash="$(
        sha256sum "$ids_file" \
            | awk '{print substr($1, 1, 24)}'
    )"


    local cache_json="${GEOMETRY_CACHE}/${content_hash}.json"
    local cache_geojson="${GEOMETRY_CACHE}/${content_hash}.geojson"

    local split_marker="${GEOMETRY_CACHE}/${content_hash}.split"


    ###########################################################################
    # 過去に分割済み
    ###########################################################################

    if [[ -f "$split_marker" ]]; then

        log "  cached split geometry batch (${count} relations)"

        split_geometry_file \
            "$ids_file" \
            "$content_hash"

        return 0
    fi


    ###########################################################################
    # GeoJSONキャッシュ
    ###########################################################################

    if [[ -f "$cache_geojson" ]]; then

        if jq -e '
            .type == "FeatureCollection"
            and (.features | type == "array")
        ' "$cache_geojson" >/dev/null 2>&1
        then

            log "  cached geometry (${count} relations)"

            printf '%s\n' "$cache_geojson" \
                >> "$GEOMETRY_MANIFEST"

            return 0
        fi


        log "  Broken geometry cache removed: ${cache_geojson}"

        rm -f "$cache_geojson"
    fi


    ###########################################################################
    # Relation IDs
    ###########################################################################

    local ids
    ids="$(paste -sd, "$ids_file")"


    log "  fetching geometry (${count} relations): ${ids}"


    local query

    query=$(cat <<EOF
[out:json][timeout:${OVERPASS_TIMEOUT}];

relation(id:${ids});

out body;
>;
out skel qt;
EOF
)


    local tmp_json="${cache_json}.tmp"


    if ! overpass_request \
        "$query" \
        "$tmp_json" \
        "geometry ${ids}"
    then

        rm -f "$tmp_json"


        #######################################################################
        # 1 relationでも失敗
        #######################################################################

        if (( count <= 1 )); then

            local failed_id
            failed_id="$(cat "$ids_file")"

            die "Failed to fetch geometry for relation/${failed_id}"
        fi


        #######################################################################
        # batchを半分に分割
        #######################################################################

        log "  Splitting geometry batch (${count} relations)"


        split_geometry_file \
            "$ids_file" \
            "$content_hash"


        touch "$split_marker"

        return 0
    fi


    mv "$tmp_json" "$cache_json"


    ###########################################################################
    # OSM JSON → GeoJSON
    ###########################################################################

    local tmp_geojson="${cache_geojson}.tmp"


    if ! osmtogeojson "$cache_json" \
        > "$tmp_geojson"
    then

        rm -f "$tmp_geojson"

        die "osmtogeojson failed for geometry batch"
    fi


    if ! jq -e '
        .type == "FeatureCollection"
        and (.features | type == "array")
    ' "$tmp_geojson" >/dev/null
    then

        rm -f "$tmp_geojson"

        die "Invalid GeoJSON generated by osmtogeojson"
    fi


    mv "$tmp_geojson" "$cache_geojson"


    printf '%s\n' "$cache_geojson" \
        >> "$GEOMETRY_MANIFEST"


    sleep "$REQUEST_DELAY"
}


###############################################################################
# Phase 2
###############################################################################

log "Phase 2: fetching relation geometry..."


split \
    -l "$BATCH_SIZE" \
    -d \
    -a 5 \
    "$RELATION_IDS" \
    "${WORK_DIR}/batch-"


BATCH_TOTAL="$(
    find "$WORK_DIR" \
        -maxdepth 1 \
        -name 'batch-*' \
        -type f \
        | wc -l
)"


BATCH_CURRENT=0


for ids_file in "${WORK_DIR}"/batch-*; do

    [[ -f "$ids_file" ]] || continue


    BATCH_CURRENT=$((BATCH_CURRENT + 1))


    log "[${BATCH_CURRENT}/${BATCH_TOTAL}] geometry batch"


    fetch_geometry_file "$ids_file"

done


###############################################################################
# Phase 3
# GeoJSON統合
###############################################################################

log "Phase 3: merging GeoJSON..."


python3 \
    - "$GEOMETRY_MANIFEST" \
      "$RELATIONS_NDJSON" \
      "$FINAL_TMP" \
      "$OVERPASS_URL" <<'PY'

import json
import sys

from datetime import datetime, timezone


manifest_path = sys.argv[1]
relations_path = sys.argv[2]
output_path = sys.argv[3]
overpass_url = sys.argv[4]


###############################################################################
# Discoveryで見つかったrelation
###############################################################################

expected = {}


with open(
    relations_path,
    encoding="utf-8",
) as f:

    for line in f:

        line = line.strip()

        if not line:
            continue

        item = json.loads(line)

        expected[int(item["id"])] = item


###############################################################################
# Geometry bbox
###############################################################################

def geometry_bbox(geometry):

    min_lon = float("inf")
    min_lat = float("inf")

    max_lon = float("-inf")
    max_lat = float("-inf")


    def walk(value):

        nonlocal \
            min_lon, \
            min_lat, \
            max_lon, \
            max_lat


        if (
            isinstance(value, list)
            and len(value) >= 2
            and isinstance(value[0], (int, float))
            and isinstance(value[1], (int, float))
        ):

            lon = float(value[0])
            lat = float(value[1])

            min_lon = min(
                min_lon,
                lon,
            )

            min_lat = min(
                min_lat,
                lat,
            )

            max_lon = max(
                max_lon,
                lon,
            )

            max_lat = max(
                max_lat,
                lat,
            )

            return


        if isinstance(value, list):

            for child in value:
                walk(child)


    walk(
        geometry.get(
            "coordinates",
            [],
        )
    )


    if min_lon == float("inf"):
        return None


    return [
        round(min_lon, 7),
        round(min_lat, 7),
        round(max_lon, 7),
        round(max_lat, 7),
    ]


###############################################################################
# FeatureからOSM ID取得
###############################################################################

def feature_osm_id(feature):

    value = str(
        feature.get("id")
        or ""
    )


    if "/" in value:

        prefix, number = value.split(
            "/",
            1,
        )

        try:
            return prefix, int(number)

        except ValueError:
            pass


    props = feature.get("properties") or {}


    feature_type = props.get("type")
    feature_id = props.get("id")


    if (
        feature_type in (
            "node",
            "way",
            "relation",
        )
        and
        feature_id is not None
    ):

        try:
            return (
                str(feature_type),
                int(feature_id),
            )

        except (ValueError, TypeError):
            pass


    return None, None


###############################################################################
# Featureのtags取得
#
# osmtogeojson標準形式:
#
# properties:
#   type: relation
#   id: 123
#   tags:
#     boundary: administrative
#     admin_level: 7
#
# flatProperties形式への保険としてproperties直下も扱う。
###############################################################################

def feature_tags(feature):

    props = feature.get("properties") or {}

    nested = props.get("tags")


    if isinstance(nested, dict):
        return nested


    return props


###############################################################################
# 正規化Featureを保存
###############################################################################

features = {}


def save_feature(
    osm_id,
    tags,
    geometry,
):

    if osm_id not in expected:
        return


    if tags.get("boundary") != "administrative":
        return


    level = str(
        tags.get("admin_level")
        or ""
    )


    if level not in (
        "7",
        "8",
    ):
        return


    geometry_type = geometry.get(
        "type"
    )


    if geometry_type not in (
        "Polygon",
        "MultiPolygon",
    ):
        return


    bbox = geometry_bbox(
        geometry
    )


    if bbox is None:
        return


    name = (
        tags.get("name:ja")
        or tags.get("name")
        or expected[osm_id].get("name")
        or ""
    )


    features[osm_id] = {

        "type":
            "Feature",

        "id":
            f"relation/{osm_id}",

        "bbox":
            bbox,

        "properties": {

            "osm_type":
                "relation",

            "osm_id":
                osm_id,

            "admin_level":
                int(level),

            "name":
                name,

            "name_ja":
                tags.get("name:ja"),

            "name_en":
                tags.get("name:en"),

            "official_name":
                tags.get("official_name"),

            "place":
                tags.get("place"),

            "wikidata":
                tags.get("wikidata"),

            "wikipedia":
                tags.get("wikipedia"),

            "ref":
                tags.get("ref"),
        },

        "geometry":
            geometry,
    }


###############################################################################
# Geometryファイル一覧
###############################################################################

with open(
    manifest_path,
    encoding="utf-8",
) as manifest:

    geometry_files = [
        line.strip()
        for line in manifest
        if line.strip()
    ]


###############################################################################
# Pass 1:
# 通常のrelation Feature
###############################################################################

collections = []


for path in geometry_files:

    with open(
        path,
        encoding="utf-8",
    ) as f:

        collection = json.load(f)

    collections.append(
        collection
    )


    for feature in collection.get(
        "features",
        [],
    ):

        osm_type, osm_id = feature_osm_id(
            feature
        )


        if osm_type != "relation":
            continue


        if osm_id is None:
            continue


        tags = feature_tags(
            feature
        )


        geometry = feature.get(
            "geometry"
        ) or {}


        save_feature(
            osm_id,
            tags,
            geometry,
        )


###############################################################################
# Pass 2:
#
# osmtogeojsonでは「単純なmultipolygon」はrelation自身ではなく、
# outer wayがFeatureとして出る場合がある。
#
# properties.relations[] の reltags を使って、
# expected relation のgeometryとして回収する。
###############################################################################

missing_after_relation_pass = (
    set(expected.keys())
    -
    set(features.keys())
)


if missing_after_relation_pass:

    for collection in collections:

        for feature in collection.get(
            "features",
            [],
        ):

            props = feature.get(
                "properties"
            ) or {}


            relations = props.get(
                "relations"
            )


            if not isinstance(
                relations,
                list,
            ):
                continue


            geometry = feature.get(
                "geometry"
            ) or {}


            if geometry.get("type") not in (
                "Polygon",
                "MultiPolygon",
            ):
                continue


            for relation in relations:

                try:
                    rel_id = int(
                        relation.get("rel")
                    )

                except (
                    ValueError,
                    TypeError,
                ):
                    continue


                if rel_id not in missing_after_relation_pass:
                    continue


                role = relation.get(
                    "role"
                )


                if role not in (
                    "",
                    None,
                    "outer",
                ):
                    continue


                reltags = relation.get(
                    "reltags"
                )


                if not isinstance(
                    reltags,
                    dict,
                ):
                    continue


                save_feature(
                    rel_id,
                    reltags,
                    geometry,
                )


###############################################################################
# 最終確認
###############################################################################

expected_ids = set(
    expected.keys()
)

actual_ids = set(
    features.keys()
)

missing = sorted(
    expected_ids
    -
    actual_ids
)


if missing:

    print(
        "ERROR: Relations that could not be converted to Polygon/MultiPolygon:",
        file=sys.stderr,
    )


    for osm_id in missing[:50]:

        item = expected[osm_id]

        print(
            f"  relation/{osm_id} "
            f"admin_level={item.get('admin_level')} "
            f"name={item.get('name')}",
            file=sys.stderr,
        )


    if len(missing) > 50:

        print(
            f"  ... and {len(missing) - 50} more",
            file=sys.stderr,
        )


    raise RuntimeError(
        f"{len(missing)} administrative relations could not be converted"
    )


###############################################################################
# ソート
###############################################################################

items = list(
    features.values()
)


items.sort(
    key=lambda feature: (
        feature["properties"]["admin_level"],
        feature["properties"]["name"],
        feature["properties"]["osm_id"],
    )
)


###############################################################################
# 件数
###############################################################################

level7_count = sum(
    1
    for feature in items
    if feature["properties"]["admin_level"] == 7
)


level8_count = sum(
    1
    for feature in items
    if feature["properties"]["admin_level"] == 8
)


###############################################################################
# 最終GeoJSON
###############################################################################

result = {

    "type":
        "FeatureCollection",

    "generated_at":
        datetime.now(timezone.utc)
        .replace(microsecond=0)
        .isoformat()
        .replace("+00:00", "Z"),

    "source":
        "OpenStreetMap",

    "overpass_url":
        overpass_url,

    "admin_levels":
        [
            7,
            8,
        ],

    "feature_counts": {

        "admin_level_7":
            level7_count,

        "admin_level_8":
            level8_count,

        "total":
            len(items),
    },

    "features":
        items,
}


with open(
    output_path,
    "w",
    encoding="utf-8",
) as f:

    json.dump(
        result,
        f,
        ensure_ascii=False,
        separators=(",", ":"),
    )

    f.write("\n")


print(
    f"admin_level=7: {level7_count}",
    file=sys.stderr,
)

print(
    f"admin_level=8: {level8_count}",
    file=sys.stderr,
)

print(
    f"total: {len(items)}",
    file=sys.stderr,
)

PY


###############################################################################
# 最終GeoJSON検証
###############################################################################

log "Validating final GeoJSON..."


jq -e '
    .type == "FeatureCollection"

    and
    (.features | type == "array")

    and
    (.features | length > 0)

    and
    .feature_counts.admin_level_7 > 0
' "$FINAL_TMP" >/dev/null \
    || die "Final GeoJSON validation failed"


NEW_7="$(
    jq '.feature_counts.admin_level_7' \
        "$FINAL_TMP"
)"


NEW_8="$(
    jq '.feature_counts.admin_level_8' \
        "$FINAL_TMP"
)"


NEW_TOTAL="$(
    jq '.feature_counts.total' \
        "$FINAL_TMP"
)"

###############################################################################
# 既存ファイルとの比較
###############################################################################

if [[ -f "$OUTPUT" ]]; then

    OLD_7="$(
        jq '
            .feature_counts.admin_level_7
            //
            (
                [
                    .features[]
                    | select(
                        .properties.admin_level == 7
                    )
                ]
                | length
            )
        ' "$OUTPUT" 2>/dev/null \
        || echo 0
    )"

    OLD_8="$(
        jq '
            .feature_counts.admin_level_8
            //
            (
                [
                    .features[]
                    | select(
                        .properties.admin_level == 8
                    )
                ]
                | length
            )
        ' "$OUTPUT" 2>/dev/null \
        || echo 0
    )"

    log "Previous:"
    log "  level7=${OLD_7}"
    log "  level8=${OLD_8}"

    log "New:"
    log "  level7=${NEW_7}"
    log "  level8=${NEW_8}"

    ###########################################################################
    # 10%以上減ったら安全側で停止
    ###########################################################################

    if (( OLD_7 > 0 && NEW_7 * 100 < OLD_7 * 90 )); then
        die "admin_level=7 count dropped by more than 10%"
    fi

    if (( OLD_8 > 0 && NEW_8 * 100 < OLD_8 * 90 )); then
        die "admin_level=8 count dropped by more than 10%"
    fi

fi


###############################################################################
# 完成ファイルへ置換
###############################################################################

mv "$FINAL_TMP" "$OUTPUT"

SUCCESS=1


###############################################################################
# 完了
###############################################################################

log "Updated: ${OUTPUT}"
log "admin_level=7: ${NEW_7}"
log "admin_level=8: ${NEW_8}"
log "total        : ${NEW_TOTAL}"
log "Done."
