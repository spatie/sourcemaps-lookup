<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Internal\Segment;

it('constructs an unmapped (1-field) segment', function () {
    $s = new Segment(generatedColumn: 5);
    expect($s->generatedColumn)->toBe(5);
    expect($s->sourceIndex)->toBeNull();
    expect($s->sourceLine)->toBeNull();
    expect($s->sourceColumn)->toBeNull();
    expect($s->nameIndex)->toBeNull();
    expect($s->isMapped())->toBeFalse();
});

it('constructs a mapped (4-field) segment', function () {
    $s = new Segment(10, 0, 2, 3);
    expect($s->generatedColumn)->toBe(10);
    expect($s->sourceIndex)->toBe(0);
    expect($s->sourceLine)->toBe(2);
    expect($s->sourceColumn)->toBe(3);
    expect($s->nameIndex)->toBeNull();
    expect($s->isMapped())->toBeTrue();
});

it('constructs a mapped-with-name (5-field) segment', function () {
    $s = new Segment(10, 0, 2, 3, 7);
    expect($s->nameIndex)->toBe(7);
    expect($s->isMapped())->toBeTrue();
});
