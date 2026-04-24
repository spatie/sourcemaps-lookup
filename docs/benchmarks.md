---
title: Benchmarks
weight: 7
---

Measured on an Apple M1 Pro (PHP 8.5.2), median of 10 runs, each in an isolated PHP subprocess to get clean peak memory numbers. `axy/sourcemap` 1.x is included as a baseline, since it's the main existing option for Source Map v3 work in PHP.

Scenarios:

- **A**: parse + 1 lookup (cold path).
- **B**: parse + 20 lookups across about 5 distinct source files (realistic stack trace).
- **C**: parse + 20 lookups on a single line in the middle of the map (worst case for lazy parsing. The first lookup must decode everything up to that line, the remaining 19 are cached).

Adapters:

- **axy**: `axy/sourcemap` v1 baseline.
- **ours**: pure-PHP default driver.
- **rust**: optional Rust backend via `spatie/sourcemaps-lookup-rust`.

```
fixture  sc     axy(wall)   ours(wall)   rust(wall)  Δours  Δrust     axy(peak)    ours(peak)    rust(peak)  Δours  Δrust
---------------------------------------------------------------------------------------------------------------------------
small    A           3.02         1.23         1.20    -59%    -60%          4.00          4.00          4.00     +0%     +0%
small    B           7.72         1.33         1.28    -83%    -83%          4.00          4.00          4.00     +0%     +0%
small    C           7.85         1.28         1.21    -84%    -85%          4.00          4.00          4.00     +0%     +0%
medium   A          34.36         1.33         1.32    -96%    -96%         26.00          4.00          4.00    -85%    -85%
medium   B          34.37         1.37         1.38    -96%    -96%         26.00          4.00          4.00    -85%    -85%
medium   C          34.65         1.95         1.97    -94%    -94%         26.00          4.00          4.00    -85%    -85%
large    A         274.16         2.41         2.39    -99%    -99%        190.97         15.95         15.95    -92%    -92%
large    B         281.06         2.53         2.44    -99%    -99%        190.97         15.95         15.95    -92%    -92%
large    C         282.24         8.68         8.54    -97%    -97%        190.97         15.95         15.95    -92%    -92%
```

Wall times are in ms, peak memory in MiB. `Δours` and `Δrust` compare against the `axy` baseline.

Both `ours` and `rust` beat the axy baseline by one-to-two orders of magnitude. The Rust backend currently matches `ours` within noise on these workloads: the pure-PHP driver's lazy per-line decoder already avoids the hot path's bottleneck (full-map parsing), so the FFI version gains little on these scenarios. The Rust backend remains useful when full-map traversal is needed (reverse indexes, exhaustive iteration) — workloads not covered by this suite.

Run it yourself:

```bash
composer bench
```
