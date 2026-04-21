<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Internal\LineIndex;

it('has one line for empty mappings', function () {
    $idx = new LineIndex('');
    expect($idx->count())->toBe(1);
    expect($idx->offset(0))->toBe(0);
    expect($idx->end(0))->toBe(0);
});

it('has one line for single-line mappings', function () {
    $idx = new LineIndex('AAAA');
    expect($idx->count())->toBe(1);
    expect($idx->offset(0))->toBe(0);
    expect($idx->end(0))->toBe(4);
});

it('splits on semicolons', function () {
    $idx = new LineIndex('AA;BB;CC');
    expect($idx->count())->toBe(3);
    expect($idx->offset(0))->toBe(0);
    expect($idx->end(0))->toBe(2);
    expect($idx->offset(1))->toBe(3);
    expect($idx->end(1))->toBe(5);
    expect($idx->offset(2))->toBe(6);
    expect($idx->end(2))->toBe(8);
});

it('handles empty lines', function () {
    $idx = new LineIndex(';;AA;');
    expect($idx->count())->toBe(4);
    expect($idx->end(0) - $idx->offset(0))->toBe(0);
    expect($idx->end(1) - $idx->offset(1))->toBe(0);
    expect($idx->end(2) - $idx->offset(2))->toBe(2);
    expect($idx->end(3) - $idx->offset(3))->toBe(0);
});

it('throws on out-of-range index', function () {
    $idx = new LineIndex('AA;BB');
    $idx->offset(5);
})->throws(\OutOfBoundsException::class);
