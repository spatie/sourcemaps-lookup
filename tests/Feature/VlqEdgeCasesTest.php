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
    $v = $value < 0 ? ((-$value) << 1) | 1 : $value << 1;
    $out = '';
    do {
        $digit = $v & 0x1F;
        $v >>= 5;
        if ($v > 0) {
            $digit |= 0x20;  // continuation
        }
        $out .= B64[$digit];
    } while ($v > 0);
    return $out;
}

/** @param list<array{int,int,int,int,?int}> $segments  [genColDelta, srcIdxDelta, srcLineDelta, srcColDelta, ?nameIdxDelta] */
function encodeLine(array $segments): string
{
    $parts = [];
    foreach ($segments as $s) {
        $p = vlqEncode($s[0]) . vlqEncode($s[1]) . vlqEncode($s[2]) . vlqEncode($s[3]);
        if ($s[4] !== null) {
            $p .= vlqEncode($s[4]);
        }
        $parts[] = $p;
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
    $pos = $map->lookup(1, 0);
    expect($pos)->not->toBeNull();
    expect($pos->sourceColumn)->toBe(1000);
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
    $pos = $map->lookup(1, 0);
    expect($pos)->not->toBeNull();
    expect($pos->sourceLine)->toBe(100_001);  // 0-based 100_000 -> 1-based
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
    $seg1 = $map->lookup(1, 0);
    $seg2 = $map->lookup(1, 10);
    expect($seg1->sourceColumn)->toBe(50);
    expect($seg2->sourceColumn)->toBe(10);  // moved backwards
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
    $pos = $map->lookup(1, 0);
    expect($pos->sourceLine)->toBe(0);  // 1-based: -1 + 1 = 0.
});
