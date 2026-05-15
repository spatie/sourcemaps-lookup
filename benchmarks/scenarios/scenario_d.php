<?php

declare(strict_types=1);

// Scenario D: parse / load only — no lookup.
//
// Used to isolate the cost of building the LineIndex / FFI parser handle from
// the cost of decoding mappings on first lookup. A "load-only" baseline.

require __DIR__.'/../../vendor/autoload.php';

[$_, $adapterClass, $fixture] = $argv;

$json = file_get_contents($fixture);
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

$adapter = new $adapterClass;

memory_reset_peak_usage();
$start = hrtime(true);

$adapter->load($data);

$wall = hrtime(true) - $start;
$peak = memory_get_peak_usage(true);

echo json_encode(['wall_ns' => $wall, 'peak_bytes' => $peak]);
