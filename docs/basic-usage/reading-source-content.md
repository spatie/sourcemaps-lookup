---
title: Reading inlined source content
weight: 3
---

If the map has a `sourcesContent` array, you can retrieve the original file body by index.

```php
$position = $map->lookup(42, 17);

if ($position !== null) {
    $content = $map->sourceContent($position->sourceFileIndex);

    if ($content !== null) {
        $lines = explode("\n", $content);
        $snippet = array_slice($lines, $position->sourceLine - 6, 11); // 5 lines above and below
    }
}
```

`sourceContent()` returns `null` when the index is out of range or when that entry is `null` in the map.

## Getting a window of lines

If you only need a window of lines around the position (for example, for a stack trace snippet), use `sourceLines()` instead. It returns an array keyed by 1-based line number and clamps out of range bounds.

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
