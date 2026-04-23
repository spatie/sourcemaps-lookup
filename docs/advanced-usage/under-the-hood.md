---
title: Under the hood
weight: 7
---

The package trades eager parsing for on demand, cached parsing.

- The raw `mappings` string is stored verbatim at construction.
- A `LineIndex` records the byte offset of every line in `mappings` via a tight `strpos` scan.
- On the first `lookup()` for a line, `LineParser` walks from the nearest cached VLQ state to the target line, decoding segments into a packed 20 byte per segment binary string (five signed int32s: generated column, source index, source line, source column, name index).
- Within the line, `lookup()` binary searches the packed buffer by generated column, unpacking only four bytes per probe. A full segment is materialised only for the winner.
- Parsed lines and their end of line VLQ state are cached, so later lookups on the same or later lines skip the work.

The result is that you pay for the lines you touch, and you never pay for PHP object overhead on segments you don't return.
