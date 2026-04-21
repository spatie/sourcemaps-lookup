#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

# Reproducibly fetches public bundle source maps from unpkg.
# Pinned versions ensure deterministic benchmarks.

fetch() {
    local url="$1" out="$2"
    if [[ -f "$out" ]]; then
        echo "ok   $out (cached)"
        return
    fi
    echo "get  $url"
    curl -fsSL "$url" -o "$out"
}

# Small (~82 KB): Preact UMD source map
fetch "https://unpkg.com/preact@10.22.0/dist/preact.umd.js.map" "small.js.map"

# Medium (~549 KB): RxJS UMD source map
# (react-dom@18.3.1 does not ship a .map; using rxjs instead)
fetch "https://unpkg.com/rxjs@7.8.1/dist/bundles/rxjs.umd.js.map" "medium.js.map"

# Large (~6 MB): Ant Design full build source map
# (lodash@4.17.21 does not ship a .map; using antd instead)
fetch "https://unpkg.com/antd@5.15.3/dist/antd.js.map" "large.js.map"

ls -lh *.js.map
