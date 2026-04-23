---
title: Reverse lookup
weight: 1
---

`findGenerated()` does the opposite of `lookup()`. Given an original source position, it returns where that position appears in the generated file. This is useful for editor tooling or coverage reports, not for stack trace resolution.

```php
$generated = $map->findGenerated(fileIndex: 0, sourceLine: 6, sourceColumn: 20);

echo $generated->line;    // 1-based line in the generated file
echo $generated->column;  // 0-based column
```

Exact match only. There is no nearest preceding fallback. Unknown positions return `null`.

The first call builds a full reverse index (parses every line), so callers who only use `lookup()` never pay this cost.
