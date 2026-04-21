<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap;
use Spatie\SourcemapsLookup\Internal\LineParser;
use Spatie\SourcemapsLookup\Internal\Segment;

// State is a list tuple: [sourceIndex, sourceLine, sourceColumn, nameIndex].
$initialState = [0, 0, 0, 0];

/** @return list<Segment> */
function decodeAll(string $packed): array
{
    $count = intdiv(strlen($packed), Segment::SIZE);
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $out[] = Segment::fromPacked($packed, $i);
    }

    return $out;
}

it('returns no segments for an empty line', function () use ($initialState) {
    [$packed, $state] = LineParser::parse('', 0, 0, $initialState);
    expect($packed)->toBe('');
    expect($state)->toBe($initialState);
});

it('parses a single 4-field mapped segment', function () use ($initialState) {
    // "AAAA" -> genCol=0, srcIdx=0, srcLine=0, srcCol=0
    [$packed, $state] = LineParser::parse('AAAA', 0, 4, $initialState);
    $segments = decodeAll($packed);
    expect($segments)->toHaveCount(1);
    expect($segments[0]->generatedColumn)->toBe(0);
    expect($segments[0]->sourceIndex)->toBe(0);
    expect($segments[0]->sourceLine)->toBe(0);
    expect($segments[0]->sourceColumn)->toBe(0);
    expect($segments[0]->nameIndex)->toBeNull();
    expect($state)->toBe($initialState);
});

it('parses a 5-field segment and updates nameIndex', function () use ($initialState) {
    // "AAAAA" -> 5 fields all zero
    [$packed, $state] = LineParser::parse('AAAAA', 0, 5, $initialState);
    $segments = decodeAll($packed);
    expect($segments[0]->nameIndex)->toBe(0);
    expect($state[3])->toBe(0);
});

it('parses a 1-field unmapped segment', function () use ($initialState) {
    // "A" -> genCol=0, unmapped
    [$packed, $state] = LineParser::parse('A', 0, 1, $initialState);
    $segments = decodeAll($packed);
    expect($segments)->toHaveCount(1);
    expect($segments[0]->generatedColumn)->toBe(0);
    expect($segments[0]->isMapped())->toBeFalse();
    expect($state)->toBe($initialState);
});

it('parses multiple comma-separated segments with cumulative state', function () use ($initialState) {
    // "AAAA,CAEC" -> two segments:
    //   1st: genCol=0, srcIdx=0, srcLine=0, srcCol=0
    //   2nd: +1, +0, +2, +1 -> genCol=1, srcIdx=0, srcLine=2, srcCol=1
    [$packed, $state] = LineParser::parse('AAAA,CAEC', 0, 9, $initialState);
    $segments = decodeAll($packed);
    expect($segments)->toHaveCount(2);
    expect($segments[1]->generatedColumn)->toBe(1);
    expect($segments[1]->sourceLine)->toBe(2);
    expect($segments[1]->sourceColumn)->toBe(1);
    expect($state[1])->toBe(2);
    expect($state[2])->toBe(1);
});

it('returns exactly 4 state fields, with no generatedColumn carried across lines', function () use ($initialState) {
    [$_, $state] = LineParser::parse('AAAA', 0, 4, $initialState);
    expect($state)->toHaveCount(4);
    expect(array_keys($state))->toBe([0, 1, 2, 3]);
});

it('throws on invalid field count', function () use ($initialState) {
    // "AA" = 2 fields - not allowed (must be 1, 4, or 5)
    LineParser::parse('AA', 0, 2, $initialState);
})->throws(InvalidSourceMap::class);

it('uses the substring within the given bounds', function () use ($initialState) {
    // full string is "AAAA;CAEC", line 1 starts at offset 5 and ends at 9
    [$packed, $_] = LineParser::parse('AAAA;CAEC', 5, 9, $initialState);
    $segments = decodeAll($packed);
    expect($segments)->toHaveCount(1);
    expect($segments[0]->generatedColumn)->toBe(1);
});
