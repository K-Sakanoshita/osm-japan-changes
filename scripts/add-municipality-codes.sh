#!/usr/bin/env bash
set -euo pipefail
repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
target="$repo_dir/public/data/municipalities.min.geojson"
temporary="$(mktemp "$repo_dir/public/data/.municipalities.XXXXXX.geojson")"
trap 'rm -f "$temporary"' EXIT
python3 "$repo_dir/scripts/add-municipality-codes.py" \
    "$repo_dir/scripts/FEA_hyoujun-20260926112545.csv" \
    "$target" \
    "$repo_dir/public/data/prefectures.min.geojson" \
    -o "$temporary"
mv "$temporary" "$target"
