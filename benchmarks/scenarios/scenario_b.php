<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

// Args: <adapter-fqn> <fixture-path> <points-file>
// points-file: JSON array of [line, column] tuples (1-based line, 0-based column).
//
// The dispatcher (compare.php) pre-computes these positions ONCE per fixture so the
// subprocess measurement includes only load() + lookups — no oracle work.

[$_, $adapterClass, $fixture, $pointsFile] = $argv;

$json = file_get_contents($fixture);
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

$points = json_decode(file_get_contents($pointsFile), true, 512, JSON_THROW_ON_ERROR);

$adapter = new $adapterClass;

memory_reset_peak_usage();
$start = hrtime(true);

$adapter->load($data);
foreach ($points as [$line, $col]) {
    $adapter->lookup($line, $col);
}

$wall = hrtime(true) - $start;
$peak = memory_get_peak_usage(true);

echo json_encode(['wall_ns' => $wall, 'peak_bytes' => $peak]);
