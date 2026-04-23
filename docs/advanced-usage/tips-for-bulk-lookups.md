---
title: Tips for bulk lookups
weight: 5
---

## Dedupe maps by URL before the frame loop

When you resolve a full stack trace, multiple frames often share the same source map (for example, two frames in the same code-split chunk). Load each unique map once.

```php
$mapsByUrl = [];
foreach ($frames as $frame) {
    $url = $frame['sourceMapUrl'];
    $mapsByUrl[$url] ??= SourceMapLookup::fromJson($fetch($url));
    $positions[] = $mapsByUrl[$url]->lookup($frame['line'], $frame['column']);
}
```

`json_decode` dominates the cost of a single cold lookup, so avoiding a redundant decode is the single biggest caller side win.

## Multiple lookups against the same map are cheap

Once a line has been looked up, its parsed segments are cached on the `SourceMapLookup` instance. Subsequent lookups on the same (or later) lines reuse the work. This is the workload the package is tuned for.
