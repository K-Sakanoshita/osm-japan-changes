#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
add-municipality-codes.py

e-Stat の標準地域コード CSV と、OSM 由来の行政界 GeoJSON を照合し、
municipalities.min.geojson の各 Feature に自治体コード等を付与する。

対象:
  admin_level=7 : 市区町村、東京都特別区、政令指定都市本体など
  admin_level=8 : 政令指定都市の区

入力例:
  python3 scripts/add-municipality-codes.py \
    scripts/FEA_hyoujun-20260926112545.csv \
    public/data/municipalities.min.geojson \
    public/data/prefectures.min.geojson \
    -o /tmp/municipalities.coded.geojson

必要:
  python3-shapely
"""

from __future__ import annotations

import argparse
import csv
import json
import re
import sys
from pathlib import Path
from typing import Any

from shapely.geometry import shape


# ----------------------------------------------------------------------
# 文字列処理
# ----------------------------------------------------------------------

def normalize(value: Any) -> str:
    if value is None:
        return ""

    text = str(value).strip()
    text = text.replace("\u3000", " ")
    text = re.sub(r"\s+", "", text)

    # よくある表記揺れ
    text = text.replace("ヶ", "ケ")

    return text


def normalize_header(value: Any) -> str:
    text = normalize(value)
    text = text.replace("（", "(")
    text = text.replace("）", ")")
    text = text.replace("・", "･")
    return text


# ----------------------------------------------------------------------
# CSV 列名
# ----------------------------------------------------------------------

COLUMN_CANDIDATES = {
    "code": [
        "標準地域コード",
        "市区町村コード",
        "地域コード",
        "団体コード",
        "コード",
    ],
    "prefecture": [
        "都道府県",
        "都道府県名",
    ],
    "municipality": [
        "市区町村",
        "市区町村名",
        "市区町村名称",
        "団体名",
        "地域名",
    ],
    "parent": [
        "政令市･郡･支庁･振興局等",
        "政令市・郡・支庁・振興局等",
        "政令指定都市名",
        "指定都市名",
        "政令市名",
    ],
}


def detect_encoding(path: str | Path) -> str:
    for encoding in (
        "utf-8-sig",
        "utf-8",
        "cp932",
        "shift_jis",
    ):
        try:
            with open(path, encoding=encoding) as f:
                f.read(8192)
            return encoding
        except UnicodeDecodeError:
            pass

    raise RuntimeError(
        f"CSV encoding could not be detected: {path}"
    )


def detect_column(
    headers: list[str],
    kind: str,
    required: bool = True,
) -> str | None:
    normalized_headers = {
        normalize_header(header): header
        for header in headers
    }

    for candidate in COLUMN_CANDIDATES[kind]:
        key = normalize_header(candidate)
        if key in normalized_headers:
            return normalized_headers[key]

    if required:
        raise RuntimeError(
            f"{kind} column could not be detected.\n"
            f"CSV columns: {', '.join(headers)}"
        )

    return None


def extract_code5(value: Any) -> str | None:
    digits = re.sub(r"\D", "", str(value or ""))

    if len(digits) < 5:
        return None

    return digits[:5]


def extract_code6(value: Any) -> str | None:
    """
    OSM ref 等から6桁の全国地方公共団体コードを取り出す。
    6桁ちょうどの数値だけを有効とする。
    """

    digits = re.sub(r"\D", "", str(value or ""))

    if len(digits) != 6:
        return None

    return digits


# ----------------------------------------------------------------------
# 5桁標準地域コード -> 6桁全国地方公共団体コード
# ----------------------------------------------------------------------

def add_check_digit(code5: str) -> str:
    """
    5桁の標準地域コードから、6桁の全国地方公共団体コードを作る。

    第1～5桁に 6,5,4,3,2 を掛け、その合計を11で割った余りを r とし、
    (11 - r) の下1桁を検査数字とする。

    例:
      27207 -> 272078
      01403 -> 014036
      02412 -> 024121
    """

    if not re.fullmatch(r"\d{5}", code5):
        raise ValueError(f"Invalid 5 digit code: {code5}")

    weights = (6, 5, 4, 3, 2)

    total = sum(
        int(digit) * weight
        for digit, weight in zip(code5, weights)
    )

    check = (11 - (total % 11)) % 10

    return f"{code5}{check}"

# ----------------------------------------------------------------------
# e-Stat CSV
# ----------------------------------------------------------------------

def load_codes(
    path: str | Path,
    code_column: str | None = None,
    prefecture_column: str | None = None,
    municipality_column: str | None = None,
    parent_column: str | None = None,
) -> list[dict[str, Any]]:
    encoding = detect_encoding(path)

    print(
        f"CSV encoding: {encoding}",
        file=sys.stderr,
    )

    with open(
        path,
        encoding=encoding,
        newline="",
    ) as f:
        reader = csv.DictReader(f)

        if not reader.fieldnames:
            raise RuntimeError("CSV header not found")

        headers = list(reader.fieldnames)

        code_column = (
            code_column
            or detect_column(headers, "code")
        )

        prefecture_column = (
            prefecture_column
            or detect_column(headers, "prefecture")
        )

        municipality_column = (
            municipality_column
            or detect_column(headers, "municipality")
        )

        if parent_column is None:
            parent_column = detect_column(
                headers,
                "parent",
                required=False,
            )

        print("CSV columns:", file=sys.stderr)
        print(
            f"  code         = {code_column}",
            file=sys.stderr,
        )
        print(
            f"  prefecture   = {prefecture_column}",
            file=sys.stderr,
        )
        print(
            f"  municipality = {municipality_column}",
            file=sys.stderr,
        )
        print(
            f"  parent       = {parent_column or '(none)'}",
            file=sys.stderr,
        )

        rows: list[dict[str, Any]] = []

        for row in reader:
            code5 = extract_code5(
                row.get(code_column)
            )

            if not code5:
                continue

            prefecture = normalize(
                row.get(prefecture_column)
            )

            municipality = normalize(
                row.get(municipality_column)
            )

            parent = ""

            if parent_column:
                parent = normalize(
                    row.get(parent_column)
                )

            # e-Stat CSV では政令指定都市本体の行が、
            #
            #   27100,大阪府,大阪市,...,""
            #
            # のように「政令市･郡･支庁･振興局等」列へ入り、
            # 「市区町村」列が空になる。
            #
            # 今回のCSVではこの形式が20政令市に使われている。
            if not municipality and parent:
                municipality = parent
                parent = ""

            if not prefecture or not municipality:
                continue

            rows.append(
                {
                    "code5": code5,
                    "code6": add_check_digit(code5),
                    "prefecture": prefecture,
                    "municipality": municipality,
                    "parent": parent,
                    "raw": row,
                }
            )

    # コード重複を検査
    code_index: dict[str, list[dict[str, Any]]] = {}

    for row in rows:
        code_index.setdefault(
            row["code5"],
            [],
        ).append(row)

    duplicates = {
        code: values
        for code, values in code_index.items()
        if len(values) > 1
    }

    if duplicates:
        print(
            "WARNING: duplicated standard area codes found:",
            file=sys.stderr,
        )

        for code, values in sorted(
            duplicates.items()
        ):
            names = ", ".join(
                f'{item["prefecture"]}/{item["parent"]}/{item["municipality"]}'
                for item in values
            )

            print(
                f"  {code}: {names}",
                file=sys.stderr,
            )

    return rows


# ----------------------------------------------------------------------
# GeoJSON
# ----------------------------------------------------------------------

def load_geojson(
    path: str | Path,
) -> dict[str, Any]:
    with open(
        path,
        encoding="utf-8",
    ) as f:
        data = json.load(f)

    if data.get("type") != "FeatureCollection":
        raise RuntimeError(
            f"Not a GeoJSON FeatureCollection: {path}"
        )

    return data


def feature_name(
    feature: dict[str, Any],
) -> str:
    props = feature.get("properties") or {}

    return normalize(
        props.get("name_ja")
        or props.get("name")
        or props.get("name:ja")
        or ""
    )


def admin_level(
    feature: dict[str, Any],
) -> int | None:
    props = feature.get("properties") or {}

    try:
        return int(
            props.get("admin_level")
        )
    except (ValueError, TypeError):
        return None


def osm_id(
    feature: dict[str, Any],
) -> int | None:
    props = feature.get("properties") or {}

    value = props.get("osm_id")

    if value is not None:
        try:
            return int(value)
        except (ValueError, TypeError):
            pass

    value = str(
        feature.get("id")
        or ""
    )

    if value.startswith("relation/"):
        try:
            return int(
                value.split("/", 1)[1]
            )
        except ValueError:
            pass

    return None


def prepare_features(
    collection: dict[str, Any],
) -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []

    for feature in collection.get(
        "features",
        [],
    ):
        geometry_data = feature.get("geometry")

        if not geometry_data:
            continue

        try:
            geometry = shape(geometry_data)
        except Exception as exc:
            print(
                "WARNING: invalid geometry: "
                f"{feature_name(feature)}: {exc}",
                file=sys.stderr,
            )
            continue

        if geometry.is_empty:
            continue

        result.append(
            {
                "feature": feature,
                "geometry": geometry,
                "bbox": geometry.bounds,
                "point": geometry.representative_point(),
                "name": feature_name(feature),
                "level": admin_level(feature),
                "osm_id": osm_id(feature),
            }
        )

    return result


# ----------------------------------------------------------------------
# 空間判定
# ----------------------------------------------------------------------

def bbox_contains(
    bounds: tuple[float, float, float, float],
    point: Any,
) -> bool:
    minx, miny, maxx, maxy = bounds

    return (
        minx <= point.x <= maxx
        and miny <= point.y <= maxy
    )


def detect_prefecture(
    item: dict[str, Any],
    prefectures: list[dict[str, Any]],
) -> dict[str, Any] | None:
    point = item["point"]
    candidates: list[dict[str, Any]] = []

    for prefecture in prefectures:
        if not bbox_contains(
            prefecture["bbox"],
            point,
        ):
            continue

        if prefecture["geometry"].covers(
            point
        ):
            candidates.append(
                prefecture
            )

    if not candidates:
        return None

    # 通常は1件。重複時は小さいgeometryを優先。
    candidates.sort(
        key=lambda candidate:
            candidate["geometry"].area
    )

    return candidates[0]


def detect_parent_level7(
    item: dict[str, Any],
    level7_features: list[dict[str, Any]],
) -> dict[str, Any] | None:
    """
    admin_level=8 の代表点を含む admin_level=7 を探す。
    政令指定都市の区 -> 親の政令指定都市を取得する。
    """

    point = item["point"]
    candidates: list[dict[str, Any]] = []

    for parent in level7_features:
        if not bbox_contains(
            parent["bbox"],
            point,
        ):
            continue

        if parent["geometry"].covers(
            point
        ):
            candidates.append(parent)

    if not candidates:
        return None

    # ネストがある場合は最小面積側を採用
    candidates.sort(
        key=lambda candidate:
            candidate["geometry"].area
    )

    return candidates[0]


# ----------------------------------------------------------------------
# CSV照合
# ----------------------------------------------------------------------

def match_level7(
    rows: list[dict[str, Any]],
    prefecture: str,
    name: str,
) -> list[dict[str, Any]]:
    prefecture_n = normalize(prefecture)
    name_n = normalize(name)

    return [
        row
        for row in rows
        if (
            normalize(row["prefecture"])
            == prefecture_n
            and normalize(row["municipality"])
            == name_n
        )
    ]


def match_level8(
    rows: list[dict[str, Any]],
    prefecture: str,
    parent_name: str,
    ward_name: str,
) -> list[dict[str, Any]]:
    """
    政令指定都市の区を照合する。

    e-Stat CSV:
      都道府県 = 大阪府
      parent   = 大阪市
      municipality = 北区

    のような構造を優先する。
    """

    prefecture_n = normalize(prefecture)
    parent_n = normalize(parent_name)
    ward_n = normalize(ward_name)

    strong: list[dict[str, Any]] = []

    for row in rows:
        if (
            normalize(row["prefecture"])
            != prefecture_n
        ):
            continue

        if (
            normalize(row["parent"])
            == parent_n
            and normalize(row["municipality"])
            == ward_n
        ):
            strong.append(row)

    if strong:
        return strong

    # CSV形式が異なる場合への保険。
    # 「大阪市北区」が municipality 列に入っている場合。
    combined_n = normalize(
        parent_name + ward_name
    )

    combined = [
        row
        for row in rows
        if (
            normalize(row["prefecture"])
            == prefecture_n
            and normalize(row["municipality"])
            == combined_n
        )
    ]

    if combined:
        return combined

    # 最終フォールバック。
    # 同一都道府県に同名区が複数ある場合は ambiguous のまま残す。
    return [
        row
        for row in rows
        if (
            normalize(row["prefecture"])
            == prefecture_n
            and normalize(row["municipality"])
            == ward_n
        )
    ]


# ----------------------------------------------------------------------
# メイン
# ----------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Add Japanese municipality codes "
            "to OSM municipality GeoJSON"
        )
    )

    parser.add_argument(
        "codes_csv",
        help="e-Stat municipality code CSV",
    )

    parser.add_argument(
        "municipalities_geojson",
        help="municipalities.min.geojson",
    )

    parser.add_argument(
        "prefectures_geojson",
        help="prefectures.min.geojson",
    )

    parser.add_argument(
        "-o",
        "--output",
        required=True,
        help="output GeoJSON",
    )

    parser.add_argument(
        "--report",
        default="municipality-code-report.tsv",
        help=(
            "unmatched / ambiguous report "
            "(default: municipality-code-report.tsv)"
        ),
    )

    parser.add_argument(
        "--code-column",
        default=None,
    )

    parser.add_argument(
        "--prefecture-column",
        default=None,
    )

    parser.add_argument(
        "--municipality-column",
        default=None,
    )

    parser.add_argument(
        "--parent-column",
        default=None,
    )

    parser.add_argument(
        "--strict",
        action="store_true",
        help=(
            "exit status 2 if unmatched "
            "or ambiguous features remain"
        ),
    )

    args = parser.parse_args()

    # ------------------------------------------------------------------
    # CSV
    # ------------------------------------------------------------------

    rows = load_codes(
        args.codes_csv,
        code_column=args.code_column,
        prefecture_column=args.prefecture_column,
        municipality_column=args.municipality_column,
        parent_column=args.parent_column,
    )

    print(
        f"CSV records: {len(rows)}",
        file=sys.stderr,
    )

    # 6桁コード索引。
    # OSM行政界の ref に全国地方公共団体コードが入っている場合は、
    # 名前照合よりこちらを優先する。
    rows_by_code6: dict[str, list[dict[str, Any]]] = {}

    for row in rows:
        rows_by_code6.setdefault(
            row["code6"],
            [],
        ).append(row)

    # ------------------------------------------------------------------
    # GeoJSON
    # ------------------------------------------------------------------

    municipalities_json = load_geojson(
        args.municipalities_geojson
    )

    prefectures_json = load_geojson(
        args.prefectures_geojson
    )

    municipalities = prepare_features(
        municipalities_json
    )

    prefectures = prepare_features(
        prefectures_json
    )

    level7 = [
        item
        for item in municipalities
        if item["level"] == 7
    ]

    level8 = [
        item
        for item in municipalities
        if item["level"] == 8
    ]

    print(
        f"Municipality features: {len(municipalities)}",
        file=sys.stderr,
    )
    print(
        f"  level 7: {len(level7)}",
        file=sys.stderr,
    )
    print(
        f"  level 8: {len(level8)}",
        file=sys.stderr,
    )

    # ------------------------------------------------------------------
    # 空間関係を先に計算
    # ------------------------------------------------------------------

    prefecture_map: dict[int, dict[str, Any] | None] = {}

    for item in municipalities:
        item_osm_id = item["osm_id"]

        if item_osm_id is None:
            continue

        prefecture_map[item_osm_id] = (
            detect_prefecture(
                item,
                prefectures,
            )
        )

    parent_map: dict[int, dict[str, Any] | None] = {}

    for item in level8:
        item_osm_id = item["osm_id"]

        if item_osm_id is None:
            continue

        parent_map[item_osm_id] = (
            detect_parent_level7(
                item,
                level7,
            )
        )

    # ------------------------------------------------------------------
    # コード照合
    # ------------------------------------------------------------------

    match_map: dict[int, list[dict[str, Any]]] = {}
    match_method_map: dict[int, str] = {}

    for item in municipalities:
        item_osm_id = item["osm_id"]

        if item_osm_id is None:
            continue

        props = item["feature"].get("properties") or {}

        # OSM relation の ref に6桁の全国地方公共団体コードがあり、
        # e-Stat CSV 側にも存在する場合は最優先で利用する。
        #
        # 例:
        #   泊村   ref=014036
        #   飛驒市 ref=212172
        #
        # 同名自治体や異体字による曖昧さを避けられる。
        ref_code6 = extract_code6(
            props.get("ref")
        )

        if (
            ref_code6
            and ref_code6 in rows_by_code6
        ):
            match_map[item_osm_id] = (
                rows_by_code6[ref_code6]
            )
            match_method_map[item_osm_id] = (
                "osm_ref"
            )
            continue

        pref = prefecture_map.get(
            item_osm_id
        )

        prefecture_name = (
            pref["name"]
            if pref
            else ""
        )

        if item["level"] == 7:
            candidates = match_level7(
                rows,
                prefecture_name,
                item["name"],
            )

        elif item["level"] == 8:
            parent = parent_map.get(
                item_osm_id
            )

            parent_name = (
                parent["name"]
                if parent
                else ""
            )

            candidates = match_level8(
                rows,
                prefecture_name,
                parent_name,
                item["name"],
            )

        else:
            candidates = []

        match_map[item_osm_id] = candidates
        match_method_map[item_osm_id] = "name"

    # ------------------------------------------------------------------
    # Featureへ反映
    # ------------------------------------------------------------------

    matched = 0
    unmatched = 0
    ambiguous = 0

    report: list[dict[str, Any]] = []

    for item in municipalities:
        feature = item["feature"]
        props = feature.setdefault(
            "properties",
            {},
        )

        item_osm_id = item["osm_id"]
        level = item["level"]
        name = item["name"]

        if item_osm_id is None:
            continue

        pref = prefecture_map.get(
            item_osm_id
        )

        prefecture_name = (
            pref["name"]
            if pref
            else ""
        )

        parent = None

        if level == 8:
            parent = parent_map.get(
                item_osm_id
            )

            props["parent_name"] = (
                parent["name"]
                if parent
                else None
            )

            props["parent_osm_id"] = (
                parent["osm_id"]
                if parent
                else None
            )

        candidates = match_map.get(
            item_osm_id,
            [],
        )

        props["prefecture_name"] = (
            prefecture_name
            or None
        )

        # level 8 は、自身に自治体コードが存在しない場合でも、
        # 親の基礎自治体コードを付与する。
        # 例: 南鳥島 -> 小笠原村
        if level == 8 and parent:
            parent_osm_id = parent["osm_id"]

            if parent_osm_id is not None:
                parent_candidates = match_map.get(
                    parent_osm_id,
                    [],
                )

                if len(parent_candidates) == 1:
                    parent_row = parent_candidates[0]

                    props[
                        "parent_standard_area_code"
                    ] = parent_row["code5"]

                    props[
                        "parent_local_government_code"
                    ] = parent_row["code6"]

        # --------------------------------------------------------------
        # 一意に一致
        # --------------------------------------------------------------

        if len(candidates) == 1:
            row = candidates[0]

            props["standard_area_code"] = (
                row["code5"]
            )

            props["local_government_code"] = (
                row["code6"]
            )

            props["prefecture_code"] = (
                row["code5"][:2]
            )

            props["code_match_method"] = (
                match_method_map.get(
                    item_osm_id,
                    "name",
                )
            )

            matched += 1
            continue

        # --------------------------------------------------------------
        # 不一致
        # --------------------------------------------------------------

        props["standard_area_code"] = None
        props["local_government_code"] = None

        parent_name = (
            parent["name"]
            if parent
            else ""
        )

        if len(candidates) == 0:
            status = "unmatched"
            unmatched += 1
        else:
            status = "ambiguous"
            ambiguous += 1

        report.append(
            {
                "status": status,
                "osm_id": item_osm_id,
                "admin_level": level,
                "prefecture": prefecture_name,
                "parent": parent_name,
                "name": name,
                "candidates": ",".join(
                    (
                        f'{row["code5"]}:'
                        f'{row["prefecture"]}/'
                        f'{row["parent"]}/'
                        f'{row["municipality"]}'
                    )
                    for row in candidates
                ),
            }
        )

    # ------------------------------------------------------------------
    # GeoJSON出力
    # ------------------------------------------------------------------

    output_path = Path(args.output)

    output_path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    with open(
        output_path,
        "w",
        encoding="utf-8",
    ) as f:
        json.dump(
            municipalities_json,
            f,
            ensure_ascii=False,
            separators=(",", ":"),
        )
        f.write("\n")

    # ------------------------------------------------------------------
    # レポート出力
    # ------------------------------------------------------------------

    report_path = Path(args.report)

    report_path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    with open(
        report_path,
        "w",
        encoding="utf-8",
        newline="",
    ) as f:
        fieldnames = [
            "status",
            "osm_id",
            "admin_level",
            "prefecture",
            "parent",
            "name",
            "candidates",
        ]

        writer = csv.DictWriter(
            f,
            fieldnames=fieldnames,
            delimiter="\t",
        )

        writer.writeheader()
        writer.writerows(report)

    # ------------------------------------------------------------------
    # 結果表示
    # ------------------------------------------------------------------

    print("", file=sys.stderr)
    print("Result:", file=sys.stderr)
    print(
        f"  matched   : {matched}",
        file=sys.stderr,
    )
    print(
        f"  unmatched : {unmatched}",
        file=sys.stderr,
    )
    print(
        f"  ambiguous : {ambiguous}",
        file=sys.stderr,
    )
    print(
        f"  output    : {args.output}",
        file=sys.stderr,
    )
    print(
        f"  report    : {args.report}",
        file=sys.stderr,
    )

    if args.strict and (
        unmatched > 0
        or ambiguous > 0
    ):
        return 2

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
