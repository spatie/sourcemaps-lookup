---
title: Error handling
weight: 3
---

Two exception classes are available, both in `Spatie\SourcemapsLookup\Exceptions`.

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
