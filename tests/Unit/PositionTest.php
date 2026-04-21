<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Position;

it('exposes readonly public properties', function () {
    $p = new Position(
        sourceLine: 10,
        sourceColumn: 4,
        sourceFileName: 'src/app.ts',
        sourceFileIndex: 2,
        name: 'handler',
    );
    expect($p->sourceLine)->toBe(10);
    expect($p->sourceColumn)->toBe(4);
    expect($p->sourceFileName)->toBe('src/app.ts');
    expect($p->sourceFileIndex)->toBe(2);
    expect($p->name)->toBe('handler');
});

it('allows null sourceFileName', function () {
    $p = new Position(1, 0, null, 0, null);
    expect($p->sourceFileName)->toBeNull();
    expect($p->name)->toBeNull();
});
