<?php

declare(strict_types=1);

// Measure the overhead of scopeAt() on top of lookup(). Run manually:
//   php -d memory_limit=1024M benchmarks/scope_at_cost.php
// (Not wired into `composer bench` — this is a perf canary, not part of the
// correctness suite.)

require __DIR__.'/../vendor/autoload.php';

use Spatie\SourcemapsLookup\SourceMapLookup;

const WARMUP = 200;
const ITERS = 20_000;
const POINTS = 20_000; // unique per iter → cold path measurement

function pickPoints(SourceMapLookup $map, string $mappings, int $want): array
{
    $points = [];
    $totalLines = max(1, substr_count($mappings, ';') + 1);
    for ($l = 1; $l <= $totalLines && count($points) < $want; $l++) {
        for ($c = 0; $c < 8000 && count($points) < $want; $c += 7) {
            if ($map->lookup($l, $c) !== null) {
                $points[] = [$l, $c];
            }
        }
    }

    return $points;
}

function benchmark(string $fixture): void
{
    $json = file_get_contents($fixture);
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    echo '=== '.basename($fixture).' ('.number_format(strlen($json))." bytes) ===\n";

    gc_collect_cycles();
    $memBefore = memory_get_usage(true);
    $peakBefore = memory_get_peak_usage(true);

    $t0 = hrtime(true);
    $map = SourceMapLookup::fromArray($data);
    $loadWall = hrtime(true) - $t0;
    $memAfterLoad = memory_get_usage(true);

    $points = pickPoints($map, $data['mappings'], POINTS);
    if ($points === []) {
        echo "  (no points)\n";

        return;
    }

    // Warmup: prime segment caches.
    for ($i = 0, $n = min(WARMUP, count($points)); $i < $n; $i++) {
        [$l, $c] = $points[$i];
        $map->lookup($l, $c);
    }

    // Pure lookup()
    $np = count($points);
    $t0 = hrtime(true);
    for ($i = 0; $i < ITERS; $i++) {
        [$l, $c] = $points[$i % $np];
        $map->lookup($l, $c);
    }
    $lookupWall = hrtime(true) - $t0;

    // scopeAt() — warm caches on walk-back.
    $t0 = hrtime(true);
    $named = 0;
    for ($i = 0; $i < ITERS; $i++) {
        [$l, $c] = $points[$i % $np];
        $scope = $map->scopeAt($l, $c);
        if ($scope?->name !== null) {
            $named++;
        }
    }
    $scopeWall = hrtime(true) - $t0;

    // Freshly load the map again so scopeAt's caches are cold for a pessimistic run.
    gc_collect_cycles();
    $mapCold = SourceMapLookup::fromArray($data);
    for ($i = 0, $n = min(WARMUP, count($points)); $i < $n; $i++) {
        [$l, $c] = $points[$i];
        $mapCold->lookup($l, $c);
    }

    $t0 = hrtime(true);
    for ($i = 0; $i < ITERS; $i++) {
        [$l, $c] = $points[$i % $np];
        $mapCold->scopeAt($l, $c);
    }
    $scopeColdWall = hrtime(true) - $t0;

    $peakFinal = memory_get_peak_usage(true);

    $perLookup = $lookupWall / ITERS;
    $perScope = $scopeWall / ITERS;
    $perScopeCold = $scopeColdWall / ITERS;

    printf("  load: %6.2f ms\n", $loadWall / 1e6);
    printf("  memory: load +%s MB  peak +%s MB\n",
        fmtMB($memAfterLoad - $memBefore),
        fmtMB($peakFinal - $peakBefore));
    printf("  per-call: lookup()=%.2f us   scopeAt(warm)=%.2f us (%+.0f%%)   scopeAt(cold caches)=%.2f us (%+.0f%%)\n",
        $perLookup / 1000,
        $perScope / 1000, $perLookup > 0 ? (($perScope / $perLookup) - 1) * 100 : 0,
        $perScopeCold / 1000, $perLookup > 0 ? (($perScopeCold / $perLookup) - 1) * 100 : 0);
    printf("  scopeAt named-Scope hit rate: %.0f%%\n\n", $named / ITERS * 100);
}

function fmtMB(int $bytes): string
{
    return number_format($bytes / 1024 / 1024, 2);
}

foreach (['small', 'medium', 'large'] as $f) {
    benchmark(__DIR__."/fixtures/$f.js.map");
}
