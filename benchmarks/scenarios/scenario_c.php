<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

[$_, $adapterClass, $fixture] = $argv;

$json = file_get_contents($fixture);
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

$adapter = new $adapterClass;

$lineCount = max(1, substr_count($data['mappings'], ';'));
$targetLine = (int) ($lineCount / 2); // middle of the map

memory_reset_peak_usage();
$start = hrtime(true);

$adapter->load($data);
for ($i = 0; $i < 20; $i++) {
    $adapter->lookup(max(1, $targetLine), $i * 10);
}

$wall = hrtime(true) - $start;
$peak = memory_get_peak_usage(true);

echo json_encode(['wall_ns' => $wall, 'peak_bytes' => $peak]);
