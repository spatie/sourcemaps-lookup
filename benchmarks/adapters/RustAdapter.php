<?php

declare(strict_types=1);

namespace Spatie\SourcemapsLookup\Benchmarks\Adapters;

use Spatie\SourcemapsLookup\SourceMapLookup;
use Spatie\SourcemapsLookupRust\RustParserDriver;

class RustAdapter
{
    private SourceMapLookup $map;

    public function load(array $data): void
    {
        $this->map = SourceMapLookup::fromArray($data, new RustParserDriver());
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
