---
title: Resolving the enclosing scope
weight: 2
---

`scopeAt()` resolves a generated position to the enclosing source language scope, modeled after the consumer semantics of the [ECMA-426 Scopes proposal](https://github.com/tc39/source-map/blob/main/proposals/scopes.md). Because no bundler currently emits the `scopes` field, the implementation today is a heuristic polyfill that walks inlined `sourcesContent` backward looking for enclosing function declarations. When bundlers start producing `scopes`, the same API will be backed natively.

```php
$scope = $map->scopeAt(line: 42, column: 17);

echo $scope->name;                  // "onClick", or null for an anonymous function boundary
echo $scope->position->sourceLine;  // where the frame actually executed
echo $scope->parent?->name;         // lexically enclosing scope (for example, the React component)
```

The walk back recognises `function NAME`, `const/let/var NAME = (…) => …`, `const NAME = async function`, and class or object method shorthand. An anonymous arrow passed as a callback yields a `Scope` with `$name === null`, signalling that there is a function boundary here but no binding to report. Single line strings, line comments, block comments, and template literals are skipped when counting brace depth. Multiline strings and block comments spanning multiple lines are a known limitation.

`scopeAt()` returns `null` when the generated position resolves to nothing at all. A fallback single level `Scope` is returned when only the mapping's `name` is available (no inlined `sourcesContent` on which to walk).

A per call `$maxLinesBack` argument bounds how far back the walker looks (default: `SourceMapLookup::DEFAULT_WALKBACK_LINES`, currently 60).

```php
$scope = $map->scopeAt(42, 17, maxLinesBack: 200);
```

## The `Scope` object

```php
readonly class Scope
{
    public ?string $name;      // identifier of the enclosing scope, or null for an anonymous function boundary
    public Position $position; // innermost: the queried position; outer: the declaration line
    public ?Scope $parent;     // lexically enclosing scope, or null at the top level
}
```

Walk `$parent` outward to display the enclosing chain. For anonymous callbacks like `arr.map(() => { … })`, `$name` is `null` but the `Scope` is still returned. The function boundary is real, the binding name just isn't recoverable from source.
