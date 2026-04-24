---
title: Under the hood
weight: 7
---

The package trades eager parsing for on demand, cached parsing, and it lets
you swap the parser backend.

The parser is behind a `SourceMapParserDriver` interface. The default
`PhpParserDriver` works like this:

- The raw `mappings` string is stored verbatim at construction.
- A `LineIndex` records the byte offset of every line in `mappings` via a tight `strpos` scan.
- On the first `lookup()` for a line, `LineParser` walks from the nearest cached VLQ state to the target line, decoding segments into a packed 20 byte per segment binary string (five signed int32s: generated column, source index, source line, source column, name index).
- Within the line, `lookup()` binary searches the packed buffer by generated column, unpacking only four bytes per probe. A full segment is materialised only for the winner.
- Parsed lines and their end of line VLQ state are cached, so later lookups on the same or later lines skip the work.

The result is that you pay for the lines you touch, and you never pay for PHP object overhead on segments you don't return.

## Swapping the driver

`SourceMapLookup::fromFile()` / `fromJson()` / `fromArray()` accept an optional
`SourceMapParserDriver`:

```php
use Spatie\SourcemapsLookup\Drivers\PhpParserDriver;
use Spatie\SourcemapsLookup\SourceMapLookup;

$map = SourceMapLookup::fromFile('bundle.js.map', new PhpParserDriver());
```

When no driver is passed, the package auto-detects. If the optional
`spatie/sourcemaps-lookup-rust` package is installed and `ext-ffi` is
available, it uses the Rust-backed driver for faster parsing; otherwise it
falls back to `PhpParserDriver`.

To force a driver explicitly (useful for benchmarking or debugging), pass an
instance. Explicit requests that fail to initialise throw
`Spatie\SourcemapsLookup\Exceptions\DriverUnavailable`.
