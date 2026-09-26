#!/usr/bin/env bash
set -euo pipefail

if (($# != 0)); then
    printf 'Usage: %s\n' "$0" >&2
    exit 2
fi

repo_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
php_bin="${PHP_BIN:-php}"

"${php_bin}" "${repo_dir}/scripts/backfill-prefectures.php"
"${php_bin}" "${repo_dir}/scripts/backfill-municipalities.php"
