# Fast & memory-efficient JS source map lookup for PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/spatie/sourcemaps-lookup.svg?style=flat-square)](https://packagist.org/packages/spatie/sourcemaps-lookup)
[![Tests](https://img.shields.io/github/actions/workflow/status/spatie/sourcemaps-lookup/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/spatie/sourcemaps-lookup/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/spatie/sourcemaps-lookup.svg?style=flat-square)](https://packagist.org/packages/spatie/sourcemaps-lookup)

`spatie/sourcemaps-lookup` resolves JavaScript stack-frame positions against a [Source Map v3](https://tc39.es/ecma426/) file and returns the original source file, line, column, and symbol name. It is tuned for stack-frame resolution (e.g. symbolicating JavaScript errors from an uploaded source map) and is narrowly focused on the read path: no writing, merging, or transforming of maps.

```php
use Spatie\SourcemapsLookup\SourceMapLookup;

$map = SourceMapLookup::fromFile('bundle.js.map');
$position = $map->lookup(42, 17);

echo $position->sourceFileName;  // "src/app.ts"
echo $position->sourceLine;      // 1-based
echo $position->sourceColumn;    // 0-based
echo $position->name;            // symbol name or null
```

Resolving 20 stack frames against a 6 MB production source map takes around 3.8 ms and uses about 18 MiB of memory on an Apple M1 Pro. See [Benchmarks](#benchmarks) for the full picture.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/sourcemaps-lookup.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/sourcemaps-lookup)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Requirements

- PHP 8.3 or higher
- No runtime dependencies

## Installation

Install the package via Composer:

```bash
composer require spatie/sourcemaps-lookup
```

## Usage

### Loading a source map

You can construct a `SourceMapLookup` from a file path, a raw JSON string, or an already-decoded array.

```php
use Spatie\SourcemapsLookup\SourceMapLookup;

// From a file path (reads + decodes)
$map = SourceMapLookup::fromFile('/path/to/bundle.js.map');

// From a JSON string (e.g. contents already in memory)
$map = SourceMapLookup::fromJson($json);

// From an array (e.g. when you already decoded the JSON)
$map = SourceMapLookup::fromArray($data);
```

Construction is cheap: the raw `mappings` string is stored but not parsed until you actually call `lookup()`. Only the lines you touch are decoded.

### Looking up a position

```php
$position = $map->lookup(line: 42, column: 17);
```

- `$line` is **1-based** (matches stack trace conventions).
- `$column` is **0-based** (matches the Source Map v3 spec and browsers).
- Returns a `Position` object, or `null` if no mapping applies for that coordinate (see below).

The lookup returns the last mapping on the given line whose generated column is `<= $column`, following the standard Source Map v3 lookup semantics.

### The `Position` object

```php
final readonly class Position
{
    public int $sourceLine;          // 1-based
    public int $sourceColumn;        // 0-based
    public ?string $sourceFileName;  // resolved with sourceRoot when present; null if spec-null
    public int $sourceFileIndex;     // index into sources[] / sourcesContent[]
    public ?string $name;            // symbol name, or null for 4-field mappings
}
```

When the source map has a `sourceRoot`, `sourceFileName` is returned as `sourceRoot . sources[i]`. We do not further resolve relative paths; if you need absolute URLs, do that in your caller.

### When `lookup()` returns `null`

`lookup()` returns `null` in three cases, all of which mean "no original source for this position":

1. No mappings exist for the requested line.
2. No mapping on the line has `generatedColumn <= $column`.
3. The best-matching mapping is a 1-field (unmapped) segment. Per the spec, this explicitly marks the generated column as intentionally unmapped.

You should treat all three the same way: there is simply no known original source for that frame.

### Reading inlined source content

If the map has a `sourcesContent` array, you can retrieve the original file body by index:

```php
$position = $map->lookup(42, 17);

if ($position !== null) {
    $content = $map->sourceContent($position->sourceFileIndex);

    if ($content !== null) {
        $lines = explode("\n", $content);
        $snippet = array_slice($lines, $position->sourceLine - 6, 11); // 5 lines above + below
    }
}
```

`sourceContent()` returns `null` when the index is out of range or when that entry is `null` in the map.

If you only need a window of lines around the position (e.g. for a stack-trace snippet), use `sourceLines()` instead. It returns an array keyed by 1-based line number and clamps out-of-range bounds:

```php
$position = $map->lookup(42, 17);

$snippet = $map->sourceLines(
    fileIndex: $position->sourceFileIndex,
    fromLine: $position->sourceLine - 15,
    toLine: $position->sourceLine + 15,
);

// $snippet === [27 => '...', 28 => '...', ..., 57 => '...']
```

`sourceLines()` returns `null` when no inlined content is available (same rule as `sourceContent()`), and an empty array when the clamped range is empty.

### Reverse lookup: original to generated

`findGenerated()` does the opposite of `lookup()`: given an original source position, it returns where that position appears in the generated file. This is useful for editor tooling or coverage reports, not for stack-trace resolution.

```php
$generated = $map->findGenerated(fileIndex: 0, sourceLine: 6, sourceColumn: 20);

echo $generated->line;    // 1-based line in the generated file
echo $generated->column;  // 0-based column
```

- Exact match only, no nearest-preceding fallback. Unknown positions return `null`.
- The first call builds a full reverse index (parses every line), so callers who only use `lookup()` never pay this cost.

### Resolving the enclosing scope (preview)

`scopeAt()` resolves a generated position to the enclosing source-language scope, modeled after the consumer semantics of the [ECMA-426 Scopes proposal](https://github.com/tc39/source-map/blob/main/proposals/scopes.md). Because no bundler currently emits the `scopes` field, the implementation today is a heuristic polyfill that walks inlined `sourcesContent` backward looking for enclosing function declarations. When bundlers start producing `scopes`, the same API will be backed natively.

```php
$scope = $map->scopeAt(line: 42, column: 17);

echo $scope->name;                  // "onClick", or null for an anonymous function boundary
echo $scope->position->sourceLine;  // where the frame actually executed
echo $scope->parent?->name;         // lexically enclosing scope (e.g. the React component)
```

The walk-back recognises `function NAME`, `const/let/var NAME = (…) => …`, `const NAME = async function`, and class/object method shorthand. An anonymous arrow passed as a callback yields a `Scope` with `$name === null`, signalling that there is a function boundary here but no binding to report. Single-line strings, line comments, block comments, and template literals are skipped when counting brace depth; multiline strings and block comments spanning multiple lines are a known limitation.

`scopeAt()` returns `null` when the generated position resolves to nothing at all; a fallback single-level `Scope` is returned when only the mapping's `name` is available (no inlined `sourcesContent` on which to walk).

A per-call `$maxLinesBack` argument bounds how far back the walker looks (default: `SourceMapLookup::DEFAULT_WALKBACK_LINES`, currently 60):

```php
$scope = $map->scopeAt(42, 17, maxLinesBack: 200);
```

### Marking third-party sources

Source Map v3 maps can carry an `ignoreList` of source-array indices that debuggers should step over. `isIgnored()` exposes that normative field:

```php
$position = $map->lookup(42, 17);

if ($position !== null && $map->isIgnored($position->sourceFileName)) {
    // vendor / framework code — skip in UI
}
```

The lookup accepts either the raw entry from `sources[]` or its `sourceRoot`-resolved form (whichever is easiest to pass). Unknown source names return `false`; a map without an `ignoreList` field returns `false` for every source.

### Listing the source files

```php
$map->sourceNames(); // list<?string>: the raw sources[] array, unresolved
```

This returns the original `sources` array as-is (without applying `sourceRoot`). Useful for diagnostics or surfacing a list of available files.

### Error handling

Two exception classes, both in `Spatie\SourcemapsLookup\Exceptions`:

```php
use Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap;
use Spatie\SourcemapsLookup\Exceptions\UnsupportedSourceMap;

try {
    $map = SourceMapLookup::fromJson($json);
} catch (UnsupportedSourceMap $e) {
    // Indexed (sectioned) maps, or anything else structurally valid but out of scope.
} catch (InvalidSourceMap $e) {
    // Bad JSON, missing required keys, wrong version, etc.
}
```

`UnsupportedSourceMap` extends `InvalidSourceMap`, so catching the latter catches both.

### Complete example: resolving a stack frame

```php
use Spatie\SourcemapsLookup\SourceMapLookup;

$map = SourceMapLookup::fromFile('bundle.js.map');

// Say we got this from a JavaScript error:
//   at bundle.js:42:17
$position = $map->lookup(42, 17);

if ($position === null) {
    echo "No original source for bundle.js:42:17\n";
    return;
}

echo "Original: {$position->sourceFileName}:{$position->sourceLine}:{$position->sourceColumn}\n";

if ($position->name !== null) {
    echo "In function: {$position->name}\n";
}

// Show the surrounding code, if the map has inlined source content
$content = $map->sourceContent($position->sourceFileIndex);
if ($content !== null) {
    $lines = explode("\n", $content);
    $start = max(0, $position->sourceLine - 6);
    $end = min(count($lines) - 1, $position->sourceLine + 4);

    for ($i = $start; $i <= $end; $i++) {
        $marker = ($i + 1 === $position->sourceLine) ? '>' : ' ';
        echo sprintf("%s %4d | %s\n", $marker, $i + 1, $lines[$i]);
    }
}
```

## Tips for bulk lookups

### Dedupe maps by URL before the frame loop

When you resolve a full stack trace, multiple frames often share the same source map (e.g. two frames in the same code-split chunk). Load each unique map once:

```php
$mapsByUrl = [];
foreach ($frames as $frame) {
    $url = $frame['sourceMapUrl'];
    $mapsByUrl[$url] ??= SourceMapLookup::fromJson($fetch($url));
    $positions[] = $mapsByUrl[$url]->lookup($frame['line'], $frame['column']);
}
```

`json_decode` dominates the cost of a single cold lookup, so avoiding a redundant decode is the single biggest caller-side win.

### Multiple lookups against the same map are cheap

Once a line has been looked up, its parsed segments are cached on the `SourceMapLookup` instance. Subsequent lookups on the same (or later) lines reuse the work. This is the workload the package is tuned for.

## Supported source map features

- Source Map v3 "regular" maps (the default format produced by bundlers).
- 1-field (unmapped), 4-field (mapped), and 5-field (mapped-with-name) segments.
- `sourceRoot` prefixing.
- `null` entries in the `sources` array (returned as `sourceFileName === null`).
- Inlined `sourcesContent`.

**Not supported** (by design):

- Writing, generating, merging, or transforming source maps.
- Indexed/sectioned source maps (throws `UnsupportedSourceMap`; file an issue if you need this).
- Source Map v4 or legacy v2.
- External source fetching: only inlined `sourcesContent` is read.

## Benchmarks

Measured on an Apple M1 Pro (PHP 8.5.2), median of 10 runs, each in an isolated PHP subprocess to get clean peak-memory numbers. `axy/sourcemap` 1.x is included as a baseline, since it's the main existing option for Source Map v3 work in PHP.

Scenarios:
- **A**: parse + 1 lookup (cold path)
- **B**: parse + 20 lookups across ~5 distinct source files (realistic stack trace)
- **C**: parse + 20 lookups on a single line in the middle of the map (worst case for lazy parsing: the first lookup must decode everything up to that line; the remaining 19 are cached)

```
fixture  sc    axy(wall ms) ours(wall ms)  Δwall   axy(peak MiB) ours(peak MiB)  Δpeak
----------------------------------------------------------------------------------------
small    A            4.61          2.35    -49%           4.00           4.00     +0%
small    B           12.27          2.37    -81%           4.00           4.00     +0%
small    C           12.26          2.33    -81%           4.00           4.00     +0%
medium   A           49.97          0.51    -99%          26.00           4.00    -85%
medium   B           50.35          0.64    -99%          26.00           4.00    -85%
medium   C           50.11         17.82    -64%          26.00           6.00    -77%
large    A          399.11          3.74    -99%         190.97          17.97    -91%
large    B          399.28          3.84    -99%         190.97          17.97    -91%
large    C          403.11        117.19    -71%         190.97          36.47    -81%
```

Run it yourself:

```bash
composer bench
```

## How it works

The package trades eager parsing for on-demand, cached parsing:

- The raw `mappings` string is stored verbatim at construction.
- A `LineIndex` records the byte offset of every line in `mappings` via a tight `strpos` scan.
- On the first `lookup()` for a line, `LineParser` walks from the nearest cached VLQ state to the target line, decoding segments into a packed 20-byte-per-segment binary string (five signed int32s: generated column, source index, source line, source column, name index).
- Within the line, `lookup()` binary-searches the packed buffer by generated column, unpacking only four bytes per probe. A full segment is materialised only for the winner.
- Parsed lines and their end-of-line VLQ state are cached, so later lookups on the same or later lines skip the work.

The result: you pay for the lines you touch, and you never pay for PHP-object overhead on segments you don't return.

## Testing

```bash
composer test
```

The test suite includes:

- Unit tests for each internal component (VLQ decoder, line index, line parser, segment).
- End-to-end tests for `SourceMapLookup::lookup()` on hand-crafted maps.
- Spec-conformance tests against reference fixtures.
- A property-based differential test that compares our output against a reference implementation on randomly generated valid maps.
- A Flare-shaped integration test covering the stack-trace-resolution flow end to end.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](https://github.com/spatie/.github/blob/main/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Alex Vanderbist](https://github.com/AlexVanderbist)
- [All Contributors](../../contributors)

Correctness test fixtures in `tests/fixtures/axy/` are copied verbatim from [`axy/sourcemap`](https://github.com/axypro/sourcemap) under its MIT licence, and used as reference data for spec conformance.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
