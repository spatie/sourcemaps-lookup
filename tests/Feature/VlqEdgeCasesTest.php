<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\SourceMapLookup;

/**
 * VLQ edge-case coverage: multi-byte continuations and negative deltas.
 * Encoder below is the inverse of src/Internal/Base64Vlq.php.
 */
const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

function vlqEncode(int $value): string
{
    // Move sign bit into LSB (VLQ convention).
    $shifted = $value < 0 ? ((-$value) << 1) | 1 : $value << 1;
    $encoded = '';
    do {
        $digit = $shifted & 0x1F;
        $shifted >>= 5;
        if ($shifted > 0) {
            $digit |= 0x20;  // continuation
        }
        $encoded .= B64[$digit];
    } while ($shifted > 0);

    return $encoded;
}

/** @param list<array{int,int,int,int,?int}> $segments  [generatedColumnDelta, sourceIndexDelta, sourceLineDelta, sourceColumnDelta, ?nameIndexDelta] */
function encodeLine(array $segments): string
{
    $parts = [];
    foreach ($segments as $segment) {
        $part = vlqEncode($segment[0]).vlqEncode($segment[1]).vlqEncode($segment[2]).vlqEncode($segment[3]);
        if ($segment[4] !== null) {
            $part .= vlqEncode($segment[4]);
        }
        $parts[] = $part;
    }

    return implode(',', $parts);
}

it('round-trips small values through the encoder (sanity)', function () {
    // 0..15 and -1..-15 fit in 1 char. 16/-16 need 2 chars.
    expect(strlen(vlqEncode(0)))->toBe(1);
    expect(strlen(vlqEncode(15)))->toBe(1);
    expect(strlen(vlqEncode(-15)))->toBe(1);
    expect(strlen(vlqEncode(16)))->toBe(2);      // |n| in [16, 511]
    expect(strlen(vlqEncode(-16)))->toBe(2);
    expect(strlen(vlqEncode(1000)))->toBe(3);    // |n| in [512, 16383]
    expect(strlen(vlqEncode(100_000)))->toBe(4); // |n| in [16384, 524287]
});

it('decodes segments with multi-byte VLQ sourceColumn deltas', function () {
    // One segment: genCol=0, srcIdx=0, srcLine=0, srcCol=1000 (forces 2-char VLQ).
    $mappings = encodeLine([[0, 0, 0, 1000, null]]);
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        'mappings' => $mappings,
    ]);
    $position = $map->lookup(1, 0);
    expect($position)->not->toBeNull();
    expect($position->sourceColumn)->toBe(1000);
});

it('decodes segments with very large (3+ byte) VLQ deltas', function () {
    // srcLine=100_000 forces 4-char VLQ.
    $mappings = encodeLine([[0, 0, 100_000, 0, null]]);
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        'mappings' => $mappings,
    ]);
    $position = $map->lookup(1, 0);
    expect($position)->not->toBeNull();
    expect($position->sourceLine)->toBe(100_001);  // 0-based 100_000 -> 1-based
});

it('accepts negative sourceColumn delta (source order != generated order)', function () {
    // seg1: src(line=5, col=50).  seg2: src(line=5, col=10) — delta -40.
    $mappings = encodeLine([
        [0, 0, 5, 50, null],
        [10, 0, 0, -40, null],
    ]);
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        'mappings' => $mappings,
    ]);
    $firstPosition = $map->lookup(1, 0);
    $secondPosition = $map->lookup(1, 10);
    expect($firstPosition->sourceColumn)->toBe(50);
    expect($secondPosition->sourceColumn)->toBe(10);  // moved backwards
});

it('accepts negative sourceLine delta (source order != generated order)', function () {
    // Common real case: minifier reorders, so gen line 1 references src line 20
    // and gen line 1 next segment references src line 3.
    $mappings = encodeLine([
        [0, 0, 20, 0, null],
        [10, 0, -17, 0, null],
    ]);
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        'mappings' => $mappings,
    ]);
    expect($map->lookup(1, 0)->sourceLine)->toBe(21);
    expect($map->lookup(1, 10)->sourceLine)->toBe(4);
});

it('surfaces rather than throws when cumulative sourceLine goes negative', function () {
    // A corrupt map that encodes a negative cumulative sourceLine is accepted
    // today — we report the nonsense value instead of throwing. Pinned here so
    // any future change in behaviour is a conscious decision.
    $mappings = encodeLine([[0, 0, -1, 0, null]]);
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        'mappings' => $mappings,
    ]);
    $position = $map->lookup(1, 0);
    expect($position->sourceLine)->toBe(0);  // 1-based: -1 + 1 = 0.
});
