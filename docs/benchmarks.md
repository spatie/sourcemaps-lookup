---
title: Benchmarks
weight: 7
---

Measured on an Apple M4 Pro with PHP 8.5.3. Each table reports the median of 10 runs. Each sample runs in an isolated PHP subprocess to keep peak memory numbers comparable.

`axy/sourcemap` 1.1.0 is included as a baseline because it is the main existing Source Map v3 option for PHP.

Scenarios:

- **A**: driver load + 1 lookup.
- **B**: driver load + 20 lookups across five source files.
- **C**: driver load + 20 lookups on a single line in the middle of the map. This is intentionally hard for lazy parsing because the first lookup must decode mappings up to that line, while the remaining 19 are cached.

Adapters:

- **axy**: `axy/sourcemap` v1 baseline.
- **ours**: pure-PHP driver.
- **rust**: optional Rust backend via `spatie/sourcemaps-lookup-rust`.

```text
fixture  sc     axy(wall)   ours(wall)   rust(wall)  ours vs axy  rust vs axy     axy(peak)    ours(peak)    rust(peak)
------------------------------------------------------------------------------------------------------------------------
small    A           3.43         2.00         1.50         -42%         -56%          4.00          4.00          4.00
small    B           8.48         2.09         1.58         -75%         -81%          4.00          4.00          4.00
small    C           8.52         1.97         1.44         -77%         -83%          4.00          4.00          4.00
medium   A          36.79         0.62         1.58         -98%         -96%         26.00          4.00          4.00
medium   B          36.80         1.94         1.66         -95%         -95%         26.00          4.00          4.00
medium   C          36.68        13.47         2.23         -63%         -94%         26.00          6.00          4.00
large    A         292.28         2.84         2.75         -99%         -99%        190.97         17.97         15.95
large    B         291.81         3.24         2.83         -99%         -99%        190.97         17.97         15.95
large    C         289.41        88.36         9.35         -69%         -97%        190.97         36.47         15.95
```

Wall times are in milliseconds. Peak memory is in MiB.

The pure-PHP driver is much faster than `axy/sourcemap` for stack-trace-shaped lookups because it lazily decodes only the generated lines it needs. The Rust backend is a small win for normal shallow lookups and a large win for cold lookups deep into a large map.

Run it yourself:

```bash
composer bench
```
