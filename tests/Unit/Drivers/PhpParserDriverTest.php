<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Drivers\PhpParserDriver;
use Spatie\SourcemapsLookup\Drivers\RawSegment;
use Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap;

// Mappings string: two generated lines.
//   line 0: one segment mapping gen col 0 -> source 0, src line 0, src col 0
//   line 1: one segment mapping gen col 5 -> source 0, src line 1, src col 2
// VLQ-encoded by hand:
//   "AAAA" = [0, 0, 0, 0]
//   "KACE" = [5, 0, 1, 2]   (K=5, A=0, C=1, E=2)
// Full mapping: "AAAA;KACE"
const TINY_MAPPINGS = 'AAAA;KACE';

it('loads mappings and reports line count', function () {
    $d = new PhpParserDriver();
    $d->load(TINY_MAPPINGS, sourceCount: 1, nameCount: 0);

    expect($d->lineCount())->toBe(2);
});

it('lookup returns nearest-preceding segment for a mapped line', function () {
    $d = new PhpParserDriver();
    $d->load(TINY_MAPPINGS, sourceCount: 1, nameCount: 0);

    $seg = $d->lookup(line: 1, column: 10);

    expect($seg)->toBeInstanceOf(RawSegment::class);
    expect($seg->generatedColumn)->toBe(5);
    expect($seg->sourceIndex)->toBe(0);
    expect($seg->sourceLine)->toBe(1);
    expect($seg->sourceColumn)->toBe(2);
    expect($seg->nameIndex)->toBeNull();
});

it('lookup returns null when line is out of range', function () {
    $d = new PhpParserDriver();
    $d->load(TINY_MAPPINGS, sourceCount: 1, nameCount: 0);

    expect($d->lookup(line: 42, column: 0))->toBeNull();
});

it('lookup returns null when no segment precedes the column', function () {
    $d = new PhpParserDriver();
    $d->load(TINY_MAPPINGS, sourceCount: 1, nameCount: 0);

    // First segment on line 1 is at gen col 5; querying col 2 returns null.
    expect($d->lookup(line: 1, column: 2))->toBeNull();
});

it('segmentsForLine yields mapped segments in order', function () {
    $d = new PhpParserDriver();
    $d->load(TINY_MAPPINGS, sourceCount: 1, nameCount: 0);

    $segs = iterator_to_array($d->segmentsForLine(0), preserve_keys: false);

    expect($segs)->toHaveCount(1);
    expect($segs[0]->generatedColumn)->toBe(0);
    expect($segs[0]->sourceLine)->toBe(0);
});

it('throws InvalidSourceMap on out-of-range sourceIndex', function () {
    $d = new PhpParserDriver();
    $d->load(TINY_MAPPINGS, sourceCount: 0, nameCount: 0);

    $d->lookup(line: 0, column: 0);
})->throws(InvalidSourceMap::class);

it('caches parsed lines across repeat lookups', function () {
    $d = new PhpParserDriver();
    $d->load(TINY_MAPPINGS, sourceCount: 1, nameCount: 0);

    // Warm the cache.
    $first = $d->lookup(1, 10);
    // Second lookup on the same line must return an equivalent RawSegment.
    $second = $d->lookup(1, 10);

    expect($second->sourceLine)->toBe($first->sourceLine);
    expect($second->sourceColumn)->toBe($first->sourceColumn);
});
