# Profiling

Tooling to produce flamegraphs comparing the pure-PHP and Rust-FFI parser
backends. Used for the blogpost about why the Rust port doesn't beat the
PHP implementation on the lazy hot path, and where it would.

## Layout

- `profile.php` — single-shot runner. Repeats a chosen workload N times so a
  sampling profiler has enough wall-time. No CSV / no measurement; output is
  just the captured profile.
- `run-samply.sh` — cross-stack flamegraph (PHP interpreter + Rust dylib in
  one view). Uses [samply](https://github.com/mstange/samply). This is the
  primary tool for the blogpost: it shows json_decode, FFI thunks, Rust
  parser frames, and PHP interpreter frames in the same flamegraph.
- `run-spx.sh` — PHP-only flamegraph via [SPX](https://github.com/NoiseByNorthwest/php-spx),
  for function-level attribution inside PHP. Optional; install instructions
  in the script header.
- `output/` — captured profiles (gitignored).

## Modes

- `load` — repeat `$adapter->load($data)`. Highlights JSON decode + LineIndex
  build cost.
- `lookup` — load once, then loop per-call lookups. Highlights per-lookup FFI
  cost when used with the Rust adapter.
- `batch` — load once, then loop a single `lookupMany()` call (Rust only;
  others fall back to the per-call loop). Pair with `lookup` to see what FFI
  amortization buys.
- `full` — repeat (load + 20 lookups). Closest to scenario B.

## Setup

```bash
# 1. samply (cross-stack, primary)
cargo install samply

# 2. Rebuild Rust dylib with debug symbols so frames resolve in the flamegraph.
cd ../../../sourcemaps-lookup-rust   # adjust to your local checkout
cargo build --profile profiling
cp target/profiling/libsourcemaps_lookup_rs.dylib resources/bin/local/

# 3. Build fixtures (skip if already present).
cd -
./benchmarks/fixtures/build.sh
```

## Capture

```bash
chmod +x benchmarks/profile/*.sh

# rust adapter, large fixture, per-call lookup hot path
./benchmarks/profile/run-samply.sh rust large lookup

# pure-PHP adapter, same workload
./benchmarls/profile/run-samply.sh ours large lookup

# rust adapter with batched FFI
./benchmarks/profile/run-samply.sh rust large batch

# load-only
./benchmarks/profile/run-samply.sh ours large load
./benchmarks/profile/run-samply.sh rust large load
```

Each run writes a gzipped Firefox-profiler JSON to `output/`. View with:

```bash
samply load benchmarks/profile/output/<file>.json.gz
```

…which opens the Firefox profiler in your browser. Switch the panel to
"Flame Graph" for the canonical view. Inverted call tree highlights leaf hot
spots.

## Suggested compare list for the blogpost

| File pair                              | Question answered                                |
|----------------------------------------|--------------------------------------------------|
| `ours-large-load` vs `rust-large-load` | Where does load cost go? (json_decode? parse?)   |
| `ours-large-lookup` vs `rust-large-lookup` | What does Rust win on the hot path?          |
| `rust-large-lookup` vs `rust-large-batch` | How much of "rust-tied-ours" is FFI overhead? |
| `ours-large-full` vs `rust-large-full` | The end-to-end story.                            |
