---
title: Loading a source map
weight: 1
---

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
