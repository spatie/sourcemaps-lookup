#!/usr/bin/env bash
# PHP-only flamegraph via SPX (https://github.com/NoiseByNorthwest/php-spx).
# SPX must be installed and enabled in the CLI php.ini. The script writes the
# profile to ~/.spx, viewable via the SPX web UI (`php -S 0.0.0.0:8000 -t /dir`)
# or exportable to flamegraph.pl.
#
# Install hint (Linux/macOS):
#   pecl install spx
#   echo 'extension=spx.so' >> "$(php-config --ini-dir)/spx.ini"
#   echo 'spx.http_enabled=1' >> "$(php-config --ini-dir)/spx.ini"
#   echo 'spx.http_key="dev"' >> "$(php-config --ini-dir)/spx.ini"
#
# Usage: same args as run-samply.sh.
set -euo pipefail

if ! php -m | grep -qi '^SPX$'; then
  echo "SPX extension not loaded — see install hint at the top of this file." >&2
  exit 3
fi

adapter="${1:?adapter required}"
fixture="${2:?fixture required}"
mode="${3:?mode required}"
iter="${4:-200}"

case "$adapter" in
  ours) class='Spatie\SourcemapsLookup\Benchmarks\Adapters\OursAdapter' ;;
  rust) class='Spatie\SourcemapsLookup\Benchmarks\Adapters\RustAdapter' ;;
  axy)  class='Spatie\SourcemapsLookup\Benchmarks\Adapters\AxyAdapter'  ;;
  *) echo "unknown adapter: $adapter" >&2; exit 2 ;;
esac

here="$(cd "$(dirname "$0")" && pwd)"
fixture_path="$here/../fixtures/$fixture.js.map"

SPX_ENABLED=1 SPX_BUILTINS=1 SPX_REPORT=full \
  php "$here/profile.php" "$class" "$fixture_path" "$mode" "$iter"

echo
echo "SPX profile written to ~/.spx — open the SPX web UI to view as flamegraph."
