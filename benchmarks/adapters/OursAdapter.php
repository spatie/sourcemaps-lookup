<?php

declare(strict_types=1);

namespace Spatie\SourcemapsLookup\Benchmarks\Adapters;

use Spatie\SourcemapsLookup\Drivers\PhpParserDriver;
use Spatie\SourcemapsLookup\SourceMapLookup;

class OursAdapter
{
    private SourceMapLookup $map;

    public function load(array $data): void
    {
        // Force the pure-PHP driver. Without an explicit driver,
        // SourceMapLookup::autoPickDriver() returns RustParserDriver whenever
        // the Rust binary is installed, which silently turns "ours" into "rust"
        // in benchmarks and profiles.
        $this->map = SourceMapLookup::fromArray($data, new PhpParserDriver());
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
