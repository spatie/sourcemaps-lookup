<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Internal\LineIndex;

it('has one line for empty mappings', function () {
    $lineIndex = new LineIndex('');
    expect($lineIndex->count())->toBe(1);
    expect($lineIndex->offset(0))->toBe(0);
    expect($lineIndex->end(0))->toBe(0);
});

it('has one line for single-line mappings', function () {
    $lineIndex = new LineIndex('AAAA');
    expect($lineIndex->count())->toBe(1);
    expect($lineIndex->offset(0))->toBe(0);
    expect($lineIndex->end(0))->toBe(4);
});

it('splits on semicolons', function () {
    $lineIndex = new LineIndex('AA;BB;CC');
    expect($lineIndex->count())->toBe(3);
    expect($lineIndex->offset(0))->toBe(0);
    expect($lineIndex->end(0))->toBe(2);
    expect($lineIndex->offset(1))->toBe(3);
    expect($lineIndex->end(1))->toBe(5);
    expect($lineIndex->offset(2))->toBe(6);
    expect($lineIndex->end(2))->toBe(8);
});

it('handles empty lines', function () {
    $lineIndex = new LineIndex(';;AA;');
    expect($lineIndex->count())->toBe(4);
    expect($lineIndex->end(0) - $lineIndex->offset(0))->toBe(0);
    expect($lineIndex->end(1) - $lineIndex->offset(1))->toBe(0);
    expect($lineIndex->end(2) - $lineIndex->offset(2))->toBe(2);
    expect($lineIndex->end(3) - $lineIndex->offset(3))->toBe(0);
});

it('throws on out-of-range index', function () {
    $lineIndex = new LineIndex('AA;BB');
    $lineIndex->offset(5);
})->throws(OutOfBoundsException::class);
