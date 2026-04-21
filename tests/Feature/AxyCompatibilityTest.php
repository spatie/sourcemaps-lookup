<?php

declare(strict_types=1);

use axy\sourcemap\PosGenerated;
use axy\sourcemap\PosMap;
use axy\sourcemap\SourceMap as AxySourceMap;
use Spatie\SourcemapsLookup\SourceMapLookup;

function axyLookup(AxySourceMap $map, int $line, int $column): ?array
{
    $pg = new PosGenerated;
    $pg->line = $line - 1;
    $best = null;
    foreach ($map->find(new PosMap($pg)) as $c) {
        if ($c->generated->column <= $column) {
            $best = $c;
        } else {
            break;
        }
    }
    if ($best === null) {
        return null;
    }

    return [
        'sourceLine' => $best->source->line + 1,
        'sourceColumn' => $best->source->column,
        'sourceFileName' => $best->source->fileName,
        'name' => $best->source->name,
    ];
}

function oursLookup(SourceMapLookup $map, int $line, int $column): ?array
{
    $p = $map->lookup($line, $column);
    if ($p === null) {
        return null;
    }

    return [
        'sourceLine' => $p->sourceLine,
        'sourceColumn' => $p->sourceColumn,
        'sourceFileName' => $p->sourceFileName,
        'name' => $p->name,
    ];
}

dataset('axyFixtures', [
    'app' => [__DIR__.'/../fixtures/axy/app.js.map'],
    'map' => [__DIR__.'/../fixtures/axy/map.js.map'],
    'scontent' => [__DIR__.'/../fixtures/axy/scontent.js.map'],
]);

it('matches axy lookup() on the first few positions of real fixtures', function (string $path) {
    $json = file_get_contents($path);
    $data = json_decode($json, true);

    $axy = new AxySourceMap($data, basename($path));
    $ours = SourceMapLookup::fromArray($data);

    // Probe a grid of positions; compare results.
    // axy is 0-based internally, we are 1-based on input, so we pass 1-based here.
    $lineCount = substr_count($data['mappings'], ';') + 1;
    for ($line = 1; $line <= min($lineCount, 20); $line++) {
        foreach ([0, 5, 20, 100, 500] as $col) {
            expect(oursLookup($ours, $line, $col))
                ->toEqual(axyLookup($axy, $line, $col));
        }
    }
})->with('axyFixtures');
