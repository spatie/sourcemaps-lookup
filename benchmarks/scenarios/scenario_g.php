<?php

declare(strict_types=1);

// Scenario G: Full pipeline — file_get_contents + parse + 20 lookups.
//
// For RustJsonAdapter: Rust parses JSON directly (no json_decode).
// For other adapters:  PHP json_decode + driver load + lookups.
//
// This isolates the json_decode cost that Rust can eliminate.
//
// Args: <adapter-fqn> <fixture-path> <points-file>

require __DIR__.'/../../vendor/autoload.php';

use Spatie\SourcemapsLookup\Benchmarks\Adapters\RustJsonAdapter;

[$_, $adapterClass, $fixture, $pointsFile] = $argv;

$points = json_decode(file_get_contents($pointsFile), true, 512, JSON_THROW_ON_ERROR);

$adapter = new $adapterClass;
$isJsonAdapter = $adapter instanceof RustJsonAdapter;

memory_reset_peak_usage();
$start = hrtime(true);

$json = file_get_contents($fixture);

if ($isJsonAdapter) {
    $adapter->loadJson($json);
} else {
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $adapter->load($data);
}

foreach ($points as [$line, $col]) {
    $adapter->lookup($line, $col);
}

$wall = hrtime(true) - $start;
$peak = memory_get_peak_usage(true);

echo json_encode(['wall_ns' => $wall, 'peak_bytes' => $peak]);
