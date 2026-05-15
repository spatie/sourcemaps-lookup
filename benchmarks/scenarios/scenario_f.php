<?php

declare(strict_types=1);

// Scenario F: warm load + batch lookup of all 20 points in a single call.
//
// For RustAdapter: routes through parser_lookup_many → one FFI crossing.
// For other adapters: falls back to per-call lookups in a tight PHP loop, so
// the comparison shows whether amortizing FFI overhead is what closes the gap.
//
// Args: <adapter-fqn> <fixture-path> <points-file>

require __DIR__.'/../../vendor/autoload.php';

[$_, $adapterClass, $fixture, $pointsFile] = $argv;

$json = file_get_contents($fixture);
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$points = json_decode(file_get_contents($pointsFile), true, 512, JSON_THROW_ON_ERROR);

$adapter = new $adapterClass;
$adapter->load($data);

$hasBatch = method_exists($adapter, 'lookupMany');

memory_reset_peak_usage();
$start = hrtime(true);

if ($hasBatch) {
    $adapter->lookupMany($points);
} else {
    foreach ($points as [$line, $col]) {
        $adapter->lookup($line, $col);
    }
}

$wall = hrtime(true) - $start;
$peak = memory_get_peak_usage(true);

echo json_encode(['wall_ns' => $wall, 'peak_bytes' => $peak]);
