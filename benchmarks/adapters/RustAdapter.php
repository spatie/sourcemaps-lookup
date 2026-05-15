<?php

declare(strict_types=1);

namespace Spatie\SourcemapsLookup\Benchmarks\Adapters;

use Spatie\SourcemapsLookup\Drivers\RawSegment;
use Spatie\SourcemapsLookup\SourceMapLookup;
use Spatie\SourcemapsLookupRust\RustParserDriver;

class RustAdapter
{
    private SourceMapLookup $map;

    private RustParserDriver $driver;

    public function load(array $data): void
    {
        $this->driver = new RustParserDriver;
        $this->map = SourceMapLookup::fromArray($data, $this->driver);
    }

    /**
     * Batch lookup via a single FFI call. Bypasses SourceMapLookup::lookup() to
     * isolate parser_lookup_many cost. Returns raw segments only — comparable
     * to per-call lookup() for hot-path timing.
     *
     * @param  list<array{0:int,1:int}>  $points
     * @return list<RawSegment|null>
     */
    public function lookupMany(array $points): array
    {
        return $this->driver->lookupMany($points);
    }

    /** @return array{line:int,column:int,fileName:?string,name:?string}|null */
    public function lookup(int $line, int $column): ?array
    {
        $pos = $this->map->lookup($line, $column);
        if ($pos === null) {
            return null;
        }

        return [
            'line' => $pos->sourceLine,
            'column' => $pos->sourceColumn,
            'fileName' => $pos->sourceFileName,
            'name' => $pos->name,
        ];
    }
}
