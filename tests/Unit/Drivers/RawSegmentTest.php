<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Drivers\RawSegment;

it('is a readonly struct with all five fields', function () {
    $seg = new RawSegment(
        generatedColumn: 10,
        sourceIndex: 2,
        sourceLine: 7,
        sourceColumn: 3,
        nameIndex: 1,
    );

    expect($seg->generatedColumn)->toBe(10);
    expect($seg->sourceIndex)->toBe(2);
    expect($seg->sourceLine)->toBe(7);
    expect($seg->sourceColumn)->toBe(3);
    expect($seg->nameIndex)->toBe(1);
});

it('allows null sourceIndex for unmapped segments', function () {
    $seg = new RawSegment(generatedColumn: 5, sourceIndex: null, sourceLine: null, sourceColumn: null, nameIndex: null);

    expect($seg->sourceIndex)->toBeNull();
    expect($seg->nameIndex)->toBeNull();
});
