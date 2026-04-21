<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

const RUNS = 10;
const PER_SAMPLE_TIMEOUT_SEC = 30;

$adapters = [
    'axy' => \Spatie\SourcemapsLookup\Benchmarks\Adapters\AxyAdapter::class,
    'ours' => \Spatie\SourcemapsLookup\Benchmarks\Adapters\OursAdapter::class,
];

$fixtures = [
    'small' => __DIR__ . '/fixtures/small.js.map',
    'medium' => __DIR__ . '/fixtures/medium.js.map',
    'large' => __DIR__ . '/fixtures/large.js.map',
];

$scenarios = [
    'A' => __DIR__ . '/scenarios/scenario_a.php',
    'B' => __DIR__ . '/scenarios/scenario_b.php',
    'C' => __DIR__ . '/scenarios/scenario_c.php',
];

$grouped = [];
$totalCombos = count(array_filter($fixtures, 'is_file')) * count($scenarios) * count($adapters);
$combo = 0;
$overallStart = hrtime(true);
$pointFiles = [];

foreach ($fixtures as $fixName => $fixPath) {
    if (! is_file($fixPath)) {
        fwrite(STDERR, "skip fixture $fixName (missing; run benchmarks/fixtures/build.sh)\n");
        continue;
    }

    // Pre-compute scenario-B points ONCE per fixture via axy oracle.
    // Done here so the per-subprocess measurement in scenario_b.php is clean.
    fwrite(STDERR, sprintf("[prep] %s: picking realistic B-scenario points...", $fixName));
    $t0 = hrtime(true);
    $pointFiles[$fixName] = pickScenarioBPoints($fixPath);
    fwrite(STDERR, sprintf(" %d points in %.1fs\n",
        count(json_decode(file_get_contents($pointFiles[$fixName]), true)),
        (hrtime(true) - $t0) / 1e9
    ));

    foreach ($scenarios as $scName => $scPath) {
        $row = ['fixture' => $fixName, 'scenario' => $scName];
        foreach ($adapters as $adName => $adClass) {
            $combo++;
            $t0 = hrtime(true);
            fwrite(STDERR, sprintf(
                "[%d/%d] %s %s %s: running %d samples... ",
                $combo, $totalCombos, $fixName, $scName, $adName, RUNS
            ));
            $extraArgs = $scName === 'B' ? [$pointFiles[$fixName]] : [];
            $samples = runSubprocess($scPath, $adClass, $fixPath, RUNS, $extraArgs);
            $elapsedSec = (hrtime(true) - $t0) / 1e9;
            if ($samples['error'] !== null) {
                fwrite(STDERR, sprintf("error in %.1fs (%s)\n", $elapsedSec, $samples['error']));
            } else {
                fwrite(STDERR, sprintf(
                    "median wall=%.2fms peak=%.2fMiB in %.1fs\n",
                    $samples['wall_ms'], $samples['peak_mb'], $elapsedSec
                ));
            }
            $row[$adName] = $samples;
        }
        $grouped[] = $row;
    }
}
fwrite(STDERR, sprintf("\nAll done in %.1fs.\n\n", (hrtime(true) - $overallStart) / 1e9));

// Clean up temp point files.
foreach ($pointFiles as $pf) {
    @unlink($pf);
}

/**
 * Pre-compute 20 (line, column) positions that hit ~5 distinct source files.
 * Uses our own LineIndex + LineParser — spec-compliant and fast (lazy, early-exits
 * on first 20 hits). axy's find() was tried first but eagerly traverses the entire
 * map and timed out on the 6 MB large fixture.
 */
function pickScenarioBPoints(string $fixturePath): string
{
    $data = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
    $mappings = $data['mappings'] ?? '';
    $lineIndex = new \Spatie\SourcemapsLookup\Internal\LineIndex($mappings);
    $totalLines = $lineIndex->count();

    $maxFiles = 5;
    $maxPoints = 20;
    $chosenFiles = [];
    $points = [];
    $state = [0, 0, 0, 0];
    $oracleStart = hrtime(true);
    $deadline = microtime(true) + 10; // 10s cap on oracle prep

    for ($lineIdx = 0; $lineIdx < $totalLines; $lineIdx++) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, sprintf(
                "\n    oracle hit 10s deadline at line %d/%d with %d points — using what we have\n",
                $lineIdx, $totalLines, count($points)
            ));
            break;
        }
        [$packed, $state] = \Spatie\SourcemapsLookup\Internal\LineParser::parse(
            $mappings,
            $lineIndex->offset($lineIdx),
            $lineIndex->end($lineIdx),
            $state
        );
        $segCount = intdiv(strlen($packed), \Spatie\SourcemapsLookup\Internal\Segment::SIZE);
        for ($si = 0; $si < $segCount; $si++) {
            $seg = \Spatie\SourcemapsLookup\Internal\Segment::fromPacked($packed, $si);
            if (! $seg->isMapped()) {
                continue;
            }
            $fi = $seg->sourceIndex;
            if (! isset($chosenFiles[$fi])) {
                if (count($chosenFiles) >= $maxFiles) {
                    continue;
                }
                $chosenFiles[$fi] = true;
            }
            $points[] = [$lineIdx + 1, $seg->generatedColumn];
            if (count($points) >= $maxPoints) {
                break 2;
            }
        }
    }

    // Tiny-fixture guard: cycle to 20 if we ran out.
    while (count($points) > 0 && count($points) < $maxPoints) {
        $points[] = $points[count($points) % count($points)];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'bench-points-');
    file_put_contents($tmp, json_encode($points));
    return $tmp;
}

printTable($grouped);

function runSubprocess(string $scenario, string $adapterClass, string $fixture, int $runs, array $extraArgs = []): array
{
    $walls = [];
    $peaks = [];
    for ($i = 0; $i < $runs; $i++) {
        $extra = '';
        foreach ($extraArgs as $arg) {
            $extra .= ' ' . escapeshellarg($arg);
        }
        $cmd = sprintf(
            '%s %s %s %s%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($scenario),
            escapeshellarg($adapterClass),
            escapeshellarg($fixture),
            $extra
        );
        $sampleStart = hrtime(true);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($proc === false) {
            return ['wall_ms' => null, 'peak_mb' => null, 'error' => 'proc_open failed'];
        }

        // Non-blocking read with timeout — kills the subprocess if it hangs.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + PER_SAMPLE_TIMEOUT_SEC;
        while (true) {
            $status = proc_get_status($proc);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (! $status['running']) {
                // Drain final output.
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                $elapsed = (hrtime(true) - $sampleStart) / 1e9;
                fwrite(STDERR, sprintf("\n    sample %d/%d KILLED after %.1fs (timeout)\n", $i + 1, $runs, $elapsed));
                return ['wall_ms' => null, 'peak_mb' => null, 'error' => sprintf('sample timeout after %ds', PER_SAMPLE_TIMEOUT_SEC)];
            }
            usleep(10_000); // 10ms
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $elapsed = (hrtime(true) - $sampleStart) / 1e9;
        if ($exit !== 0) {
            fwrite(STDERR, sprintf("\n    sample %d/%d FAILED exit=%d in %.1fs: %s\n", $i + 1, $runs, $exit, $elapsed, substr(trim($stderr), 0, 200)));
            return ['wall_ms' => null, 'peak_mb' => null, 'error' => trim($stderr) ?: "exit $exit"];
        }
        $result = json_decode($stdout, true);
        if (! is_array($result) || ! isset($result['wall_ns'], $result['peak_bytes'])) {
            return ['wall_ms' => null, 'peak_mb' => null, 'error' => "bad output: " . substr($stdout, 0, 200)];
        }
        // Dot per sample — visible progress within a 10-sample batch.
        fwrite(STDERR, '.');
        $walls[] = $result['wall_ns'];
        $peaks[] = $result['peak_bytes'];
    }
    sort($walls);
    sort($peaks);
    return [
        'wall_ms' => round($walls[(int) (count($walls) / 2)] / 1_000_000, 3),
        'peak_mb' => round($peaks[(int) (count($peaks) / 2)] / 1_048_576, 2),
        'error' => null,
    ];
}

function printTable(array $rows): void
{
    printf(
        "%-8s %-3s %13s %13s %7s %14s %14s %7s\n",
        'fixture', 'sc', 'axy(wall ms)', 'ours(wall ms)', 'Δwall',
        'axy(peak MiB)', 'ours(peak MiB)', 'Δpeak'
    );
    printf("%s\n", str_repeat('-', 88));
    foreach ($rows as $r) {
        $axy = $r['axy'];
        $ours = $r['ours'];

        $axyWall = $axy['wall_ms'];
        $oursWall = $ours['error'] !== null ? 'ERROR' : ($ours['wall_ms'] ?? '-');
        $axyPeak = $axy['peak_mb'];
        $oursPeak = $ours['error'] !== null ? 'ERROR' : ($ours['peak_mb'] ?? '-');

        $dWall = deltaPct($axy['wall_ms'], $ours['wall_ms'], $axy['error'], $ours['error']);
        $dPeak = deltaPct($axy['peak_mb'], $ours['peak_mb'], $axy['error'], $ours['error']);

        printf(
            "%-8s %-3s %13s %13s %7s %14s %14s %7s\n",
            $r['fixture'],
            $r['scenario'],
            formatNum($axyWall, $axy['error']),
            formatNum($oursWall, $ours['error'], $ours['error'] !== null),
            $dWall,
            formatNum($axyPeak, $axy['error']),
            formatNum($oursPeak, $ours['error'], $ours['error'] !== null),
            $dPeak
        );
    }
}

function formatNum(mixed $val, ?string $error, bool $isErrorLiteral = false): string
{
    if ($isErrorLiteral) {
        return 'ERROR';
    }
    if ($error !== null || $val === null) {
        return '-';
    }
    return is_float($val) || is_int($val) ? number_format((float) $val, 2) : (string) $val;
}

function deltaPct(?float $axy, ?float $ours, ?string $axyErr, ?string $oursErr): string
{
    if ($axyErr !== null || $oursErr !== null || $axy === null || $ours === null || $axy == 0.0) {
        return '-';
    }
    $pct = (int) round((($ours - $axy) / $axy) * 100);
    return sprintf('%+d%%', $pct);
}
