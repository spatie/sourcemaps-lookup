---
title: Supported source map features
weight: 6
---

## Supported

- Source Map v3 "regular" maps (the default format produced by bundlers).
- 1-field (unmapped), 4-field (mapped), and 5-field (mapped with name) segments.
- `sourceRoot` prefixing.
- `null` entries in the `sources` array (returned as `sourceFileName === null`).
- Inlined `sourcesContent`.
- `ignoreList` for third-party sources (exposed via `isIgnored()`).
- Enclosing scope resolution via `scopeAt()`. Heuristic polyfill today, natively backed by the [ECMA-426 Scopes proposal](https://github.com/tc39/source-map/blob/main/proposals/scopes.md) when bundlers begin emitting it.

## Not supported (by design)

- Writing, generating, merging, or transforming source maps.
- Indexed and sectioned source maps (throws `UnsupportedSourceMap`. File an issue if you need this).
- Source Map v4 or legacy v2.
- External source fetching. Only inlined `sourcesContent` is read.
