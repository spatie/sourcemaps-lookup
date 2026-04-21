<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\GeneratedPosition;
use Spatie\SourcemapsLookup\SourceMapLookup;

it('resolves a source position back to its generated position', function () {
    // app.js.map: lookup(2, 21) -> carry.ts line 6, col 20, name=carry.
    // So findGenerated(0, 6, 20) should return (line 2, col 21).
    $map = SourceMapLookup::fromFile(__DIR__.'/../fixtures/axy/app.js.map');
    $gen = $map->findGenerated(0, 6, 20);
    expect($gen)->toBeInstanceOf(GeneratedPosition::class);
    expect($gen->line)->toBe(2);
    expect($gen->column)->toBe(21);
});

it('returns null for an exact position the map does not mention', function () {
    $map = SourceMapLookup::fromFile(__DIR__.'/../fixtures/axy/app.js.map');
    // A source column that's not a segment origin.
    expect($map->findGenerated(0, 6, 999))->toBeNull();
});

it('returns null for an unknown source file index', function () {
    $map = SourceMapLookup::fromFile(__DIR__.'/../fixtures/axy/app.js.map');
    expect($map->findGenerated(99, 1, 0))->toBeNull();
});

it('is a round trip for every mapped segment on line 1 of a small map', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        'mappings' => 'AAAA,CAEC,CAEC',
        // seg 1: gen(0,0) -> src(0,0)
        // seg 2: gen(0,1) -> src(2,1)
        // seg 3: gen(0,2) -> src(4,2)
    ]);
    foreach ([[0, 0, 1, 0], [2, 1, 1, 1], [4, 2, 1, 2]] as [$srcLine, $srcCol, $genLine, $genCol]) {
        // sourceLine in API is 1-based.
        $gen = $map->findGenerated(0, $srcLine + 1, $srcCol);
        expect($gen)->not->toBeNull();
        expect($gen->line)->toBe($genLine);
        expect($gen->column)->toBe($genCol);
    }
});
