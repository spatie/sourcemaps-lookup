<?php

declare(strict_types=1);

// Args: <adapter-fqn> <fixture-path>
// Output (stdout): JSON {"wall_ns": int, "peak_bytes": int}

require __DIR__.'/../../vendor/autoload.php';

[$_, $adapterClass, $fixture] = $argv;

$json = file_get_contents($fixture);
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

/** @var object{load:callable,lookup:callable} $adapter */
$adapter = new $adapterClass;

memory_reset_peak_usage();
$start = hrtime(true);

$adapter->load($data);
$adapter->lookup(1, 0);

$wall = hrtime(true) - $start;
$peak = memory_get_peak_usage(true);

echo json_encode(['wall_ns' => $wall, 'peak_bytes' => $peak]);
