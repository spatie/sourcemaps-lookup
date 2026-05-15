<?php

declare(strict_types=1);

// Per-phase timing for one (adapter, fixture) sample.
// Output (stdout): JSON with ns durations for each phase.
//
// Args: <adapter-fqn> <fixture-path> <points-file>

require __DIR__.'/../vendor/autoload.php';

use Spatie\SourcemapsLookupRust\RustParserDriver;

[$_, $adapterClass, $fixture, $pointsFile] = $argv;

$points = json_decode(file_get_contents($pointsFile), true, 512, JSON_THROW_ON_ERROR);

$t0 = hrtime(true);
$json = file_get_contents($fixture);
$t_file_read = hrtime(true) - $t0;

$t0 = hrtime(true);
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$t_json_decode = hrtime(true) - $t0;

$adapter = new $adapterClass;

$t0 = hrtime(true);
$adapter->load($data);
$t_load = hrtime(true) - $t0;

[$firstLine, $firstCol] = $points[0];
$t0 = hrtime(true);
$adapter->lookup($firstLine, $firstCol);
$t_first_lookup = hrtime(true) - $t0;

$warmPoints = array_slice($points, 1);

$t0 = hrtime(true);
foreach ($warmPoints as [$l, $c]) {
    $adapter->lookup($l, $c);
}
$t_warm_lookups = hrtime(true) - $t0;

// Rust-only: same warm points via batched FFI, plus FFI-overhead probe.
$t_batch_lookups = null;
$t_ffi_overhead_per_call = null;
if ($adapter instanceof \Spatie\SourcemapsLookup\Benchmarks\Adapters\RustAdapter
    || method_exists($adapter, 'lookupMany')) {
    $t0 = hrtime(true);
    $adapter->lookupMany($warmPoints);
    $t_batch_lookups = hrtime(true) - $t0;
}

// Probe FFI overhead via parser_null_field — a no-work entry/exit that pays
// only the FFI call cost. Only when the adapter under test actually uses Rust;
// otherwise loading the dylib here would pollute the OursAdapter / AxyAdapter
// samply traces.
$isRustAdapter = $adapter instanceof \Spatie\SourcemapsLookup\Benchmarks\Adapters\RustAdapter
    || method_exists($adapter, 'lookupMany');
if ($isRustAdapter && RustParserDriver::isAvailable()) {
    $rust = new RustParserDriver();
    $rust->load('AAAA', 1, 0); // tiny map
    $iters = 5000;
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        // Anything that crosses FFI with no work; lineCount is closest.
        $rust->lineCount();
    }
    $t_ffi_overhead_per_call = (int) ((hrtime(true) - $t0) / $iters);
}

echo json_encode([
    'file_read_ns' => $t_file_read,
    'json_decode_ns' => $t_json_decode,
    'load_ns' => $t_load,
    'first_lookup_ns' => $t_first_lookup,
    'warm_lookups_ns' => $t_warm_lookups,
    'warm_lookup_count' => count($warmPoints),
    'batch_lookups_ns' => $t_batch_lookups,
    'ffi_overhead_per_call_ns' => $t_ffi_overhead_per_call,
    'peak_bytes' => memory_get_peak_usage(true),
]);
