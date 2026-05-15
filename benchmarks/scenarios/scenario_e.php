<?php

declare(strict_types=1);

// Scenario E: warm load + lookup-only timing.
//
// load() is called BEFORE the timer starts, so the measurement covers only the
// 20 lookups. Pairs with scenario D to give a clean parse-vs-lookup split.
// Reuses the precomputed B points file for realistic positions.
//
// Args: <adapter-fqn> <fixture-path> <points-file>

require __DIR__.'/../../vendor/autoload.php';

[$_, $adapterClass, $fixture, $pointsFile] = $argv;

$json = file_get_contents($fixture);
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$points = json_decode(file_get_contents($pointsFile), true, 512, JSON_THROW_ON_ERROR);

$adapter = new $adapterClass;
$adapter->load($data);

memory_reset_peak_usage();
$start = hrtime(true);

foreach ($points as [$line, $col]) {
    $adapter->lookup($line, $col);
}

$wall = hrtime(true) - $start;
$peak = memory_get_peak_usage(true);

echo json_encode(['wall_ns' => $wall, 'peak_bytes' => $peak]);
