# Changelog

All notable changes to `spatie/sourcemaps-lookup` will be documented in this file.

## Unreleased

### Added

- `SourceMapLookup::scopeAt()` resolves a generated position to the enclosing source-language scope, modeled after the ECMA-426 Scopes proposal. Today's implementation is a heuristic polyfill that walks `sourcesContent` backward; when bundlers start emitting the `scopes` field it will be backed natively behind the same API.
- `Spatie\SourcemapsLookup\Scopes\Scope` value object returned by `scopeAt()`, exposing `$name`, `$position`, and the lexically enclosing `$parent`.
- `SourceMapLookup::isIgnored()` for the normative `ignoreList` field on Source Map v3 maps.
- `Position` and `GeneratedPosition` are now class-level `readonly`.
