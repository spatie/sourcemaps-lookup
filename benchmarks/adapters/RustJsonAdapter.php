<?php

declare(strict_types=1);

namespace Spatie\SourcemapsLookup\Benchmarks\Adapters;

use Spatie\SourcemapsLookup\Drivers\RawSegment;
use Spatie\SourcemapsLookupRust\RustJsonDriver;

/**
 * Benchmark adapter that bypasses PHP's json_decode entirely.
 * Rust parses the JSON and extracts only the fields it needs.
 */
class RustJsonAdapter
{
    private RustJsonDriver $driver;

    /**
     * Unlike other adapters, this accepts the raw JSON string from the
     * benchmark harness (before json_decode). The load() signature stays
     * compatible by accepting array — the harness must call loadJson() instead.
     */
    public function load(array $data): void
    {
        throw new \LogicException('RustJsonAdapter requires loadJson() with raw JSON string, not a decoded array.');
    }

    public function loadJson(string $json): void
    {
        $this->driver = new RustJsonDriver;
        $this->driver->loadJson($json);
    }

    /** @return array{line:int,column:int,fileName:?string,name:?string}|null */
    public function lookup(int $line, int $column): ?array
    {
        return $this->driver->resolvedLookup($line, $column);
    }

    /**
     * @param  list<array{0:int,1:int}>  $points
     * @return list<RawSegment|null>
     */
    public function lookupMany(array $points): array
    {
        $zeroBasedPoints = array_map(
            fn (array $point): array => [$point[0] - 1, $point[1]],
            $points,
        );

        return $this->driver->lookupMany($zeroBasedPoints);
    }
}
