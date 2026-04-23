---
title: Looking up a position
weight: 1
---

## Loading a source map

You can construct a `SourceMapLookup` from a file path, a raw JSON string, or an already decoded array.

```php
use Spatie\SourcemapsLookup\SourceMapLookup;

// From a file path (reads and decodes).
$map = SourceMapLookup::fromFile('/path/to/bundle.js.map');

// From a JSON string (for example, contents already in memory).
$map = SourceMapLookup::fromJson($json);

// From an array (for example, when you already decoded the JSON).
$map = SourceMapLookup::fromArray($data);
```

Construction is cheap. The raw `mappings` string is stored but not parsed until you actually call `lookup()`. Only the lines you touch are decoded.

## Looking up a position

```php
$position = $map->lookup(line: 42, column: 17);
```

`$line` is **1-based** (matches stack trace conventions). `$column` is **0-based** (matches the Source Map v3 spec and browsers).

The call returns a `Position` object, or `null` if no mapping applies for that coordinate (see below).

The lookup returns the last mapping on the given line whose generated column is less than or equal to `$column`, following the standard Source Map v3 lookup semantics.

## The `Position` object

```php
readonly class Position
{
    public int $sourceLine;          // 1-based
    public int $sourceColumn;        // 0-based
    public ?string $sourceFileName;  // resolved with sourceRoot when present, null if spec-null
    public int $sourceFileIndex;     // index into sources[] / sourcesContent[]
    public ?string $name;            // symbol name, or null for 4-field mappings
}
```

When the source map has a `sourceRoot`, `sourceFileName` is returned as `sourceRoot . sources[i]`. The package does not further resolve relative paths. If you need absolute URLs, do that in your caller.

## When `lookup()` returns `null`

`lookup()` returns `null` in three cases, all of which mean "no original source for this position":

1. No mappings exist for the requested line.
2. No mapping on the line has `generatedColumn <= $column`.
3. The best matching mapping is a 1-field (unmapped) segment. Per the spec, this explicitly marks the generated column as intentionally unmapped.

You should treat all three the same way. There is simply no known original source for that frame.
