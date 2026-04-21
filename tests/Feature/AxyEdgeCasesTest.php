<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap;
use Spatie\SourcemapsLookup\Exceptions\UnsupportedSourceMap;
use Spatie\SourcemapsLookup\SourceMapLookup;

/**
 * Edge cases ported from axy-sourcemaps' test suite.
 *
 * Sources (in ../axy-sourcemaps):
 *   - tests/parsing/FormatCheckerTest.php
 *   - tests/parsing/SegmentParserTest.php
 *   - tests/parsing/LineTest.php::providerErrorLoad
 *   - tests/classSourceMap/SearchTest.php
 */

// --- Format checking (FormatCheckerTest::providerInvalidSection) ---

it('rejects missing version', function () {
    SourceMapLookup::fromArray([
        'sources' => ['a.js', 'b.js'],
        'mappings' => 'AAAA',
    ]);
})->throws(InvalidSourceMap::class);

it('rejects version as array', function () {
    SourceMapLookup::fromArray([
        'version' => [3],
        'sources' => ['a.js'],
        'mappings' => 'AAAA',
    ]);
})->throws(InvalidSourceMap::class);

it('rejects unsupported version 5', function () {
    SourceMapLookup::fromArray([
        'version' => 5,
        'sources' => ['a.js'],
        'mappings' => 'AAAA',
    ]);
})->throws(InvalidSourceMap::class);

it('rejects sources as a string', function () {
    SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => 'a.js',
        'mappings' => 'AAAA',
    ]);
})->throws(InvalidSourceMap::class);

it('rejects sources as an associative object', function () {
    // axy rejects {"a":"a.js","b":"b.js"} — non-list.
    SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a' => 'a.js', 'b' => 'b.js'],
        'mappings' => 'AAAA',
    ]);
})->throws(InvalidSourceMap::class);

it('rejects names as a string', function () {
    SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.js'],
        'names' => 'name',
        'mappings' => 'AAAA',
    ]);
})->throws(InvalidSourceMap::class);

it('rejects mappings as an array', function () {
    SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.js'],
        'mappings' => ['AAAA'],
    ]);
})->throws(InvalidSourceMap::class);

it('rejects missing mappings', function () {
    SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.js'],
    ]);
})->throws(InvalidSourceMap::class);

it('rejects sourceRoot as an array', function () {
    SourceMapLookup::fromArray([
        'version' => 3,
        'sourceRoot' => ['a'],
        'sources' => ['a.js'],
        'mappings' => 'AAAA',
    ]);
})->throws(InvalidSourceMap::class);

it('rejects sourcesContent with wrong length', function () {
    // axy: sourcesContent must match sources length (FormatCheckerTest).
    SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.js', 'b.js'],
        'sourcesContent' => ['aaa', 'bbb', 'ccc'],
        'mappings' => 'AAAA',
    ]);
})->throws(InvalidSourceMap::class);

// --- Segment-level parsing errors (SegmentParserTest + LineTest::providerErrorLoad) ---

dataset('malformedMappings', [
    '3-field segment' => ['AAA'],
    '6-field segment' => ['AAAAAA'],
    '7-field segment' => ['AAAAAAA'],
    'invalid base64 character' => ['AA*A'],
    'incomplete VLQ continuation' => ['AAAz'],
]);

it('throws on malformed segment: %s', function (string $mappings) {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.js'],
        'names' => [],
        'mappings' => $mappings,
    ]);
    // Parsing is lazy — error surfaces on first lookup against the bad line.
    $map->lookup(1, 0);
})->with('malformedMappings')->throws(InvalidSourceMap::class);

it('throws when a segment references an out-of-range source index (AZAA)', function () {
    // From axy LineTest::providerErrorLoad 'indexed' case.
    // Z = VLQ 12, so sourceIndex = 12 against a single-source array.
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.js'],
        'names' => [],
        'mappings' => 'AZAA',
    ]);
    $map->lookup(1, 0);
})->throws(InvalidSourceMap::class);

it('throws when a 5-field segment references an out-of-range name index', function () {
    // Mirror of the source-index case for names.
    // "AAAAZ" — 5th field VLQ 12 -> nameIndex 12 with empty names.
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.js'],
        'names' => [],
        'mappings' => 'AAAAZ',
    ]);
    $map->lookup(1, 0);
})->throws(InvalidSourceMap::class);

// --- Lookup semantics against a real fixture (SearchTest::testGetPosition) ---

it('matches axy getPosition on app.js.map (line 2, col 21)', function () {
    // axy is 0-based internally: its getPosition(1, 21) == our lookup(2, 21).
    $map = SourceMapLookup::fromJson(
        file_get_contents(__DIR__ . '/../fixtures/axy/app.js.map')
    );
    $pos = $map->lookup(2, 21);
    expect($pos)->not->toBeNull();
    expect($pos->sourceLine)->toBe(6);        // axy: 0-based 5
    expect($pos->sourceColumn)->toBe(20);
    expect($pos->sourceFileName)->toBe('carry.ts');
    expect($pos->name)->toBe('carry');
});

it('returns null beyond the mapping range (SearchTest parity)', function () {
    $map = SourceMapLookup::fromJson(
        file_get_contents(__DIR__ . '/../fixtures/axy/app.js.map')
    );
    expect($map->lookup(200, 10))->toBeNull();
});

// --- Sectioned (indexed) maps: intentionally unsupported ---

it('rejects sectioned maps (not supported)', function () {
    // Mirrors axy's ability to parse sections — we explicitly do not support this.
    // Bumping this package to cover sections would require a full sub-map merging pass.
    SourceMapLookup::fromArray([
        'version' => 3,
        'sections' => [
            ['offset' => ['line' => 0, 'column' => 0], 'map' => [
                'version' => 3,
                'sources' => ['a.js'],
                'names' => [],
                'mappings' => 'AAAA',
            ]],
        ],
    ]);
})->throws(UnsupportedSourceMap::class);
