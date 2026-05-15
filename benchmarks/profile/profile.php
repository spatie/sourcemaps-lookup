<?php

declare(strict_types=1);

// Long-running single-shot profile target.
//
// Repeats (load + lookup batch) ITERATIONS times so a sampling profiler has
// enough wall time to resolve hot paths. Output is irrelevant — what matters
// is the externally captured flamegraph.
//
// Args: <adapter-fqn> <fixture-path> <mode> [iterations]
//   mode = load | lookup | batch | full
//     load    → repeat $adapter->load($data)
//     lookup  → load once, then loop per-call lookups
//     batch   → load once, then loop $adapter->lookupMany() (rust only)
//     full    → repeat (load + 20 lookups), the scenario_b shape

require __DIR__.'/../../vendor/autoload.php';

use Spatie\SourcemapsLookup\Benchmarks\BenchmarkPoints;

[$_, $adapterClass, $fixture, $mode] = $argv;
$iterations = (int) ($argv[4] ?? 200);

$json = file_get_contents($fixture);
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

// Pick 20 realistic points the same way compare.php does.
$points = BenchmarkPoints::pick($fixture);

fwrite(STDERR, sprintf("[profile] adapter=%s fixture=%s mode=%s iter=%d points=%d\n",
    $adapterClass, basename($fixture), $mode, $iterations, count($points)));

$start = hrtime(true);

switch ($mode) {
    case 'load':
        for ($i = 0; $i < $iterations; $i++) {
            $a = new $adapterClass;
            $a->load($data);
        }
        break;
    case 'lookup':
        $a = new $adapterClass;
        $a->load($data);
        for ($i = 0; $i < $iterations; $i++) {
            foreach ($points as [$l, $c]) {
                $a->lookup($l, $c);
            }
        }
        break;
    case 'batch':
        $a = new $adapterClass;
        $a->load($data);
        if (! method_exists($a, 'lookupMany')) {
            fwrite(STDERR, "[profile] adapter has no lookupMany — falling back to per-call.\n");
            for ($i = 0; $i < $iterations; $i++) {
                foreach ($points as [$l, $c]) {
                    $a->lookup($l, $c);
                }
            }
            break;
        }
        for ($i = 0; $i < $iterations; $i++) {
            $a->lookupMany($points);
        }
        break;
    case 'full':
        for ($i = 0; $i < $iterations; $i++) {
            $a = new $adapterClass;
            $a->load($data);
            foreach ($points as [$l, $c]) {
                $a->lookup($l, $c);
            }
        }
        break;
    default:
        fwrite(STDERR, "[profile] unknown mode: $mode\n");
        exit(2);
}

$elapsed = (hrtime(true) - $start) / 1e9;
fwrite(STDERR, sprintf("[profile] done in %.2fs\n", $elapsed));
