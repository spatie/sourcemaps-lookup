<?php

declare(strict_types=1);

/**
 * Head-to-head: PHP json_decode vs Rust JSON parsing.
 *
 * Runs scenario G (full pipeline: file read + parse + 20 lookups)
 * for ours, rust, and rust-json adapters.
 *
 * Usage: php benchmarks/compare_json.php
 */

use Spatie\SourcemapsLookup\Benchmarks\Adapters\OursAdapter;
use Spatie\SourcemapsLookup\Benchmarks\Adapters\RustAdapter;
use Spatie\SourcemapsLookup\Benchmarks\Adapters\RustJsonAdapter;
use Spatie\SourcemapsLookup\Benchmarks\BenchmarkPoints;

require __DIR__.'/../vendor/autoload.php';

const RUNS = 10;
const TIMEOUT = 30;

$adapters = [
    'ours' => OursAdapter::class,
    'rust' => RustAdapter::class,
    'rust-json' => RustJsonAdapter::class,
];

$fixtures = [
    'small' => __DIR__.'/fixtures/small.js.map',
    'medium' => __DIR__.'/fixtures/medium.js.map',
    'large' => __DIR__.'/fixtures/large.js.map',
];

$scenario = __DIR__.'/scenarios/scenario_g.php';
$rows = [];
$pointFiles = [];

foreach ($fixtures as $fixName => $fixPath) {
    if (! is_file($fixPath)) {
        fwrite(STDERR, "skip $fixName (missing)\n");

        continue;
    }

    fwrite(STDERR, "[prep] $fixName: picking points... ");
    $pointFiles[$fixName] = pickPoints($fixPath);
    fwrite(STDERR, "ok\n");

    foreach ($adapters as $adName => $adClass) {
        assertAdapterMatchesOurs($adClass, $fixPath, $pointFiles[$fixName]);

        fwrite(STDERR, "[$fixName/$adName] ");
        $walls = [];
        $peaks = [];
        for ($i = 0; $i < RUNS; $i++) {
            $cmd = sprintf(
                '%s %s %s %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($scenario),
                escapeshellarg($adClass),
                escapeshellarg($fixPath),
                escapeshellarg($pointFiles[$fixName]),
            );
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if ($proc === false) {
                fwrite(STDERR, "proc_open failed\n");

                continue 2;
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $out = '';
            $err = '';
            $deadline = microtime(true) + TIMEOUT;
            while (true) {
                $st = proc_get_status($proc);
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                if (! $st['running']) {
                    $out .= (string) stream_get_contents($pipes[1]);
                    $err .= (string) stream_get_contents($pipes[2]);
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($proc, 9);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($proc);
                    fwrite(STDERR, "timeout\n");

                    continue 3;
                }
                usleep(10_000);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            if ($exit !== 0) {
                fwrite(STDERR, "x(exit=$exit: ".substr(trim($err), 0, 120).')');

                continue;
            }
            $r = json_decode($out, true);
            if (! is_array($r)) {
                fwrite(STDERR, 'x(bad output)');

                continue;
            }
            fwrite(STDERR, '.');
            $walls[] = $r['wall_ns'];
            $peaks[] = $r['peak_bytes'];
        }
        fwrite(STDERR, "\n");
        if (empty($walls)) {
            continue;
        }
        sort($walls);
        sort($peaks);
        $rows[] = [
            'fixture' => $fixName,
            'adapter' => $adName,
            'wall_ms' => round($walls[(int) (count($walls) / 2)] / 1_000_000, 3),
            'peak_mb' => round($peaks[(int) (count($peaks) / 2)] / 1_048_576, 2),
        ];
    }
    @unlink($pointFiles[$fixName]);
}

echo "\n";
printf("%-10s %-12s %12s %12s\n", 'fixture', 'adapter', 'wall (ms)', 'peak (MiB)');
printf("%s\n", str_repeat('-', 50));
foreach ($rows as $r) {
    printf("%-10s %-12s %12s %12s\n",
        $r['fixture'], $r['adapter'],
        number_format($r['wall_ms'], 3),
        number_format($r['peak_mb'], 2),
    );
}
echo "\nMedian of ".RUNS." samples. Scenario G: file_get_contents + parse + 20 lookups.\n";
echo "rust-json skips PHP json_decode — Rust parses the JSON directly.\n";

function pickPoints(string $fixturePath): string
{
    return BenchmarkPoints::writeTemp(BenchmarkPoints::pick($fixturePath), 'json-bench-');
}

function assertAdapterMatchesOurs(string $adapterClass, string $fixturePath, string $pointsFile): void
{
    $points = json_decode(file_get_contents($pointsFile), true, 512, JSON_THROW_ON_ERROR);
    $json = file_get_contents($fixturePath);
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    $expected = new OursAdapter;
    $expected->load($data);

    $actual = new $adapterClass;
    if ($actual instanceof RustJsonAdapter) {
        $actual->loadJson($json);
    } else {
        $actual->load($data);
    }

    foreach ($points as $index => [$line, $column]) {
        if ($actual->lookup($line, $column) != $expected->lookup($line, $column)) {
            throw new RuntimeException("Adapter $adapterClass returned a different lookup result at point $index.");
        }
    }
}
