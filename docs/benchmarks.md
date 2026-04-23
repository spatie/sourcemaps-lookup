---
title: Benchmarks
weight: 7
---

Measured on an Apple M1 Pro (PHP 8.5.2), median of 10 runs, each in an isolated PHP subprocess to get clean peak memory numbers. `axy/sourcemap` 1.x is included as a baseline, since it's the main existing option for Source Map v3 work in PHP.

Scenarios:

- **A**: parse + 1 lookup (cold path).
- **B**: parse + 20 lookups across about 5 distinct source files (realistic stack trace).
- **C**: parse + 20 lookups on a single line in the middle of the map (worst case for lazy parsing. The first lookup must decode everything up to that line, the remaining 19 are cached).

```
fixture  sc    axy(wall ms) ours(wall ms)  Δwall   axy(peak MiB) ours(peak MiB)  Δpeak
----------------------------------------------------------------------------------------
small    A            4.61          2.35    -49%           4.00           4.00     +0%
small    B           12.27          2.37    -81%           4.00           4.00     +0%
small    C           12.26          2.33    -81%           4.00           4.00     +0%
medium   A           49.97          0.51    -99%          26.00           4.00    -85%
medium   B           50.35          0.64    -99%          26.00           4.00    -85%
medium   C           50.11         17.82    -64%          26.00           6.00    -77%
large    A          399.11          3.74    -99%         190.97          17.97    -91%
large    B          399.28          3.84    -99%         190.97          17.97    -91%
large    C          403.11        117.19    -71%         190.97          36.47    -81%
```

Run it yourself:

```bash
composer bench
```
