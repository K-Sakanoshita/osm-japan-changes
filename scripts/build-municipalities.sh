#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

command -v mapshaper >/dev/null || {
    echo "mapshaper is required (install the mapshaper npm package)." >&2
    exit 1
}
csv="${1:-$repo_dir/scripts/FEA_hyoujun-20260926112545.csv}"
[[ -f "$csv" ]] || { echo "Missing e-Stat CSV: $csv" >&2; exit 1; }

OUTPUT="$work_dir/municipalities.raw.geojson" "$repo_dir/scripts/update-municipalities.sh"
mapshaper "$work_dir/municipalities.raw.geojson" -simplify 5% keep-shapes -o format=geojson "$work_dir/municipalities.simplified.geojson"
python3 "$repo_dir/scripts/add-geojson-bbox.py" "$work_dir/municipalities.simplified.geojson" "$work_dir/municipalities.bbox.geojson"
python3 "$repo_dir/scripts/add-municipality-codes.py" "$csv" "$work_dir/municipalities.bbox.geojson" "$repo_dir/public/data/prefectures.min.geojson" -o "$work_dir/municipalities.final.geojson"
mv "$work_dir/municipalities.final.geojson" "$repo_dir/public/data/municipalities.min.geojson"
echo "Updated public/data/municipalities.min.geojson"
