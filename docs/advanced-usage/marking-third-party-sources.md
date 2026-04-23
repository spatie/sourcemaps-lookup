---
title: Marking third-party sources
weight: 3
---

Source Map v3 maps can carry an `ignoreList` of source array indices that debuggers should step over. `isIgnored()` exposes that normative field.

```php
$position = $map->lookup(42, 17);

if ($position !== null && $map->isIgnored($position->sourceFileName)) {
    // Vendor or framework code. Skip in UI.
}
```

The lookup accepts either the raw entry from `sources[]` or its `sourceRoot` resolved form (whichever is easiest to pass). Unknown source names return `false`. A map without an `ignoreList` field returns `false` for every source.
