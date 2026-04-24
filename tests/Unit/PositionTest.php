<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Position;

it('exposes readonly public properties', function () {
    $position = new Position(
        sourceLine: 10,
        sourceColumn: 4,
        sourceFileName: 'src/app.ts',
        sourceFileIndex: 2,
        name: 'handler',
    );
    expect($position->sourceLine)->toBe(10);
    expect($position->sourceColumn)->toBe(4);
    expect($position->sourceFileName)->toBe('src/app.ts');
    expect($position->sourceFileIndex)->toBe(2);
    expect($position->name)->toBe('handler');
});

it('allows null sourceFileName', function () {
    $position = new Position(1, 0, null, 0, null);
    expect($position->sourceFileName)->toBeNull();
    expect($position->name)->toBeNull();
});
