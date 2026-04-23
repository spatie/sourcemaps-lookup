<?php

declare(strict_types=1);

namespace Spatie\SourcemapsLookup\Benchmarks\Adapters;

use axy\sourcemap\PosGenerated;
use axy\sourcemap\PosMap;
use axy\sourcemap\SourceMap;

class AxyAdapter
{
    private SourceMap $map;

    public function load(array $data): void
    {
        $this->map = new SourceMap($data, 'bench.js.map');
    }

    /**
     * Input convention matches our public API: 1-based line, 0-based column (Mozilla/browser convention).
     * Axy is 0-based on both internally (per the Source Map v3 spec), so we only convert the line.
     *
     * @return array{line:int,column:int,fileName:?string,name:?string}|null
     */
    public function lookup(int $line, int $column): ?array
    {
        $pg = new PosGenerated;
        $pg->line = $line - 1;

        $best = null;
        foreach ($this->map->find(new PosMap($pg)) as $candidate) {
            if ($candidate->generated->column <= $column) {
                $best = $candidate;
            } else {
                break;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'line' => $best->source->line + 1,
            'column' => $best->source->column,
            'fileName' => $best->source->fileName,
            'name' => $best->source->name,
        ];
    }
}
