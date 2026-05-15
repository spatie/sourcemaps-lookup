#!/usr/bin/env bash
# Capture a cross-stack flamegraph (PHP interp + libsourcemaps_lookup_rs.dylib)
# using samply. Outputs a Firefox profiler JSON next to this script.
#
# Usage:
#   ./run-samply.sh <adapter> <fixture> <mode> [iterations]
#     adapter   = ours | rust | axy
#     fixture   = small | medium | large
#     mode      = load | lookup | batch | full
#     iterations defaults to 200
#
# Example:
#   ./run-samply.sh rust large lookup
#   ./run-samply.sh ours large lookup
set -euo pipefail

adapter="${1:?adapter required (ours|rust|axy)}"
fixture="${2:?fixture required (small|medium|large)}"
mode="${3:?mode required (load|lookup|batch|full)}"
iter="${4:-200}"

case "$adapter" in
  ours) class='Spatie\SourcemapsLookup\Benchmarks\Adapters\OursAdapter' ;;
  rust) class='Spatie\SourcemapsLookup\Benchmarks\Adapters\RustAdapter' ;;
  axy)  class='Spatie\SourcemapsLookup\Benchmarks\Adapters\AxyAdapter'  ;;
  *) echo "unknown adapter: $adapter" >&2; exit 2 ;;
esac

here="$(cd "$(dirname "$0")" && pwd)"
fixture_path="$here/../fixtures/$fixture.js.map"
out_dir="$here/output"
mkdir -p "$out_dir"
stamp="$(date +%Y%m%d-%H%M%S)"
out_file="$out_dir/${adapter}-${fixture}-${mode}-${stamp}.json.gz"

# samply auto-opens the Firefox profiler in a browser by default. --save-only
# keeps it headless and writes the profile to disk for later upload.
samply record \
  --save-only \
  --output "$out_file" \
  --rate 1999 \
  -- php "$here/profile.php" "$class" "$fixture_path" "$mode" "$iter"

echo
echo "Profile saved: $out_file"
echo "View with:    samply load \"$out_file\""
