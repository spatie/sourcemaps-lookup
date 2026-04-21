<?php

declare(strict_types=1);

namespace Spatie\SourcemapsLookup\Benchmarks\Adapters;

use Spatie\SourcemapsLookup\SourceMapLookup;

final class OursAdapter
{
    private SourceMapLookup $map;

    public function load(array $data): void
    {
        $this->map = SourceMapLookup::fromArray($data);
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
