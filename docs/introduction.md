---
title: Introduction
weight: 1
---

`spatie/sourcemaps-lookup` resolves JavaScript stack frame positions against a [Source Map v3](https://tc39.es/ecma426/) file. It returns the original source file, line, column, symbol name, and enclosing scope. The package is tuned for stack frame resolution (for example, symbolicating JavaScript errors using an uploaded source map), and is narrowly focused on the read path. It does not write, merge, or transform maps.

```php
use Spatie\SourcemapsLookup\SourceMapLookup;

$map = SourceMapLookup::fromFile('bundle.js.map');

// Resolve a generated position to its original source location.
$position = $map->lookup(42, 17);
echo $position->sourceFileName;  // "src/app.tsx"
echo $position->sourceLine;      // 1-based
echo $position->sourceColumn;    // 0-based

// Resolve the enclosing source-language scope.
$scope = $map->scopeAt(42, 17);
echo $scope->name;               // "onClick"
echo $scope->parent?->name;      // "DeeplyNestedTrigger"
```

Resolving 20 stack frames against a 6 MB production source map takes around 3.8 ms and uses about 18 MiB of memory on an Apple M1 Pro. See [Benchmarks](/docs/sourcemaps-lookup/v1/benchmarks) for the full picture.
