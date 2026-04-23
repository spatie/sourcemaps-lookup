---
title: Listing the source files
weight: 4
---

```php
$map->sourceNames(); // list<?string>: the raw sources[] array, unresolved
```

This returns the original `sources` array as is (without applying `sourceRoot`). It's useful for diagnostics or surfacing a list of available files.
