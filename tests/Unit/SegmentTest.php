<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Internal\Segment;

it('constructs an unmapped (1-field) segment', function () {
    $segment = new Segment(generatedColumn: 5);
    expect($segment->generatedColumn)->toBe(5);
    expect($segment->sourceIndex)->toBeNull();
    expect($segment->sourceLine)->toBeNull();
    expect($segment->sourceColumn)->toBeNull();
    expect($segment->nameIndex)->toBeNull();
    expect($segment->isMapped())->toBeFalse();
});

it('constructs a mapped (4-field) segment', function () {
    $segment = new Segment(10, 0, 2, 3);
    expect($segment->generatedColumn)->toBe(10);
    expect($segment->sourceIndex)->toBe(0);
    expect($segment->sourceLine)->toBe(2);
    expect($segment->sourceColumn)->toBe(3);
    expect($segment->nameIndex)->toBeNull();
    expect($segment->isMapped())->toBeTrue();
});

it('constructs a mapped-with-name (5-field) segment', function () {
    $segment = new Segment(10, 0, 2, 3, 7);
    expect($segment->nameIndex)->toBe(7);
    expect($segment->isMapped())->toBeTrue();
});
