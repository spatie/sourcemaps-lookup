<?php

use Spatie\SourcemapsLookup\SourceMapLookup;

function mapWithContent(?string $content): SourceMapLookup
{
    return SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'sourcesContent' => [$content],
        'names' => [],
        'mappings' => '',
    ]);
}

it('returns a 1-based line-number-keyed slice', function () {
    $map = mapWithContent("line 1\nline 2\nline 3\nline 4\nline 5");

    expect($map->sourceLines(0, 2, 4))->toBe([
        2 => 'line 2',
        3 => 'line 3',
        4 => 'line 4',
    ]);
});

it('returns a single line when fromLine equals toLine', function () {
    $map = mapWithContent("a\nb\nc");

    expect($map->sourceLines(0, 2, 2))->toBe([2 => 'b']);
});

it('returns every line when the range spans the whole file', function () {
    $map = mapWithContent("x\ny\nz");

    expect($map->sourceLines(0, 1, 3))->toBe([
        1 => 'x',
        2 => 'y',
        3 => 'z',
    ]);
});

it('clamps fromLine below 1 to 1', function () {
    $map = mapWithContent("a\nb\nc");

    expect($map->sourceLines(0, -5, 2))->toBe([
        1 => 'a',
        2 => 'b',
    ]);
});

it('clamps toLine past the last line to the last line', function () {
    $map = mapWithContent("a\nb\nc");

    expect($map->sourceLines(0, 2, 999))->toBe([
        2 => 'b',
        3 => 'c',
    ]);
});

it('returns an empty array when fromLine is greater than toLine', function () {
    $map = mapWithContent("a\nb\nc");

    expect($map->sourceLines(0, 3, 2))->toBe([]);
});

it('returns an empty array when fromLine is past the end of the file', function () {
    $map = mapWithContent("a\nb");

    expect($map->sourceLines(0, 10, 20))->toBe([]);
});

it('returns null when the fileIndex is out of range', function () {
    $map = mapWithContent("a\nb");

    expect($map->sourceLines(99, 1, 2))->toBeNull();
});

it('returns null when sourcesContent at that index is null', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts', 'b.ts'],
        'sourcesContent' => ["only a\nhas content", null],
        'names' => [],
        'mappings' => '',
    ]);

    expect($map->sourceLines(1, 1, 5))->toBeNull();
});

it('treats empty string content as a single empty line', function () {
    $map = mapWithContent('');

    expect($map->sourceLines(0, 1, 1))->toBe([1 => '']);
});

it('preserves blank lines inside the slice', function () {
    $map = mapWithContent("a\n\nc");

    expect($map->sourceLines(0, 1, 3))->toBe([
        1 => 'a',
        2 => '',
        3 => 'c',
    ]);
});

it('handles a Flare-shaped 31-line-window call', function () {
    $body = implode("\n", array_map(fn ($i) => "line $i", range(1, 100)));
    $map = mapWithContent($body);

    $around = 42;
    $context = 15;

    $lines = $map->sourceLines(0, $around - $context, $around + $context);

    expect($lines)->toHaveCount(31)
        ->and(array_key_first($lines))->toBe(27)
        ->and(array_key_last($lines))->toBe(57)
        ->and($lines[42])->toBe('line 42');
});
