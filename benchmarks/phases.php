<?php

declare(strict_types=1);

// Phase-timing dispatcher. For each (adapter, fixture), spawns N subprocess
// samples, takes median per phase, and prints a markdown table. Designed for
// the blogpost — answers "where does the time go on the hot path?".
//
// Reuses the scenario_b oracle in compare.php to pick realistic points.

use Spatie\SourcemapsLookup\Benchmarks\Adapters\AxyAdapter;
use Spatie\SourcemapsLookup\Benchmarks\Adapters\OursAdapter;
use Spatie\SourcemapsLookup\Benchmarks\Adapters\RustAdapter;
use Spatie\SourcemapsLookup\Benchmarks\BenchmarkPoints;

require __DIR__.'/../vendor/autoload.php';

const RUNS = 10;
const TIMEOUT_SEC = 30;

$adapters = [
    'axy' => AxyAdapter::class,
    'ours' => OursAdapter::class,
    'rust' => RustAdapter::class,
];

$fixtures = [
    'small' => __DIR__.'/fixtures/small.js.map',
    'medium' => __DIR__.'/fixtures/medium.js.map',
    'large' => __DIR__.'/fixtures/large.js.map',
];

$rows = [];

foreach ($fixtures as $fixName => $fixPath) {
    if (! is_file($fixPath)) {
        fwrite(STDERR, "skip $fixName (missing)\n");
        continue;
    }

    fwrite(STDERR, "[prep] $fixName: picking points... ");
    $pointsFile = pickPoints($fixPath);
    fwrite(STDERR, "ok\n");

    foreach ($adapters as $adName => $adClass) {
        fwrite(STDERR, "[$fixName/$adName] ");
        $samples = [];
        for ($i = 0; $i < RUNS; $i++) {
            $r = runOne($adClass, $fixPath, $pointsFile);
            if ($r === null) {
                fwrite(STDERR, "x");
                continue;
            }
            fwrite(STDERR, ".");
            $samples[] = $r;
        }
        fwrite(STDERR, "\n");
        if (empty($samples)) {
            continue;
        }
        $rows[] = ['fixture' => $fixName, 'adapter' => $adName] + medianPhases($samples);
    }
    @unlink($pointsFile);
}

printMarkdown($rows);

function runOne(string $class, string $fix, string $points): ?array
{
    $cmd = sprintf('%s %s %s %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__.'/phases_runner.php'),
        escapeshellarg($class),
        escapeshellarg($fix),
        escapeshellarg($points),
    );
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($p === false) return null;
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $deadline = microtime(true) + TIMEOUT_SEC;
    while (true) {
        $st = proc_get_status($p);
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        if (! $st['running']) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($p, 9);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
            return null;
        }
        usleep(10_000);
    }
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p) !== 0) {
        fwrite(STDERR, "\n  err: ".substr(trim($err), 0, 200)."\n");
        return null;
    }
    $j = json_decode($out, true);
    return is_array($j) ? $j : null;
}

function medianPhases(array $samples): array
{
    $keys = ['file_read_ns', 'json_decode_ns', 'load_ns', 'first_lookup_ns',
        'warm_lookups_ns', 'batch_lookups_ns', 'ffi_overhead_per_call_ns', 'peak_bytes'];
    $out = ['warm_lookup_count' => $samples[0]['warm_lookup_count'] ?? 0];
    foreach ($keys as $k) {
        $vals = array_filter(array_column($samples, $k), fn ($v) => $v !== null);
        if (empty($vals)) {
            $out[$k] = null;
            continue;
        }
        sort($vals);
        $out[$k] = $vals[(int) (count($vals) / 2)];
    }
    return $out;
}

function printMarkdown(array $rows): void
{
    $cols = [
        'fixture' => 'fix',
        'adapter' => 'adp',
        'file_read_ns' => 'read',
        'json_decode_ns' => 'jsonDecode',
        'load_ns' => 'load',
        'first_lookup_ns' => 'lookup1',
        'warm_lookups_ns' => 'warm(N-1)',
        'batch_lookups_ns' => 'batch(N-1)',
        'ffi_overhead_per_call_ns' => 'ffiCall',
        'peak_bytes' => 'peak',
    ];

    $header = '| '.implode(' | ', array_values($cols)).' |';
    $sep = '|'.str_repeat('---|', count($cols));
    echo "\n$header\n$sep\n";
    foreach ($rows as $r) {
        $cells = [];
        foreach ($cols as $key => $_label) {
            $v = $r[$key] ?? null;
            $cells[] = formatCell($key, $v);
        }
        echo '| '.implode(' | ', $cells)." |\n";
    }
    echo "\nTimes are median of ".RUNS." samples. Durations in ms unless suffixed (ffiCall in µs, peak in MiB).\n";
}

function formatCell(string $key, mixed $v): string
{
    if ($v === null) return '-';
    return match ($key) {
        'fixture', 'adapter' => (string) $v,
        'peak_bytes' => number_format($v / 1_048_576, 1),
        'ffi_overhead_per_call_ns' => number_format($v / 1000, 2).'µs',
        default => number_format($v / 1_000_000, 3),
    };
}

function pickPoints(string $fixturePath): string
{
    return BenchmarkPoints::writeTemp(BenchmarkPoints::pick($fixturePath), 'phases-points-');
}
