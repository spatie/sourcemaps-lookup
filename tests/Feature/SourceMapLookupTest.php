<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap;
use Spatie\SourcemapsLookup\Exceptions\UnsupportedSourceMap;
use Spatie\SourcemapsLookup\SourceMapLookup;

it('constructs from a minimal valid map array', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['src/a.ts'],
        'names' => [],
        'mappings' => '',
    ]);
    expect($map)->toBeInstanceOf(SourceMapLookup::class);
});

it('constructs from JSON', function () {
    $json = '{"version":3,"sources":["src/a.ts"],"names":[],"mappings":""}';
    expect(SourceMapLookup::fromJson($json))->toBeInstanceOf(SourceMapLookup::class);
});

it('constructs from a file path', function () {
    $map = SourceMapLookup::fromFile(__DIR__ . '/../fixtures/axy/app.js.map');
    expect($map)->toBeInstanceOf(SourceMapLookup::class);
    expect($map->lookup(2, 21)?->sourceFileName)->toBe('carry.ts');
});

it('throws a clear error when the file cannot be read', function () {
    SourceMapLookup::fromFile('/nonexistent/definitely/not/here.map');
})->throws(InvalidSourceMap::class, 'Could not read source map file');

it('exposes source names', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts', 'b.ts'],
        'names' => [],
        'mappings' => '',
    ]);
    expect($map->sourceNames())->toBe(['a.ts', 'b.ts']);
});

it('returns inlined source content by index', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts', 'b.ts'],
        'sourcesContent' => ['export const a = 1;', null],
        'names' => [],
        'mappings' => '',
    ]);
    expect($map->sourceContent(0))->toBe('export const a = 1;');
    expect($map->sourceContent(1))->toBeNull();
    expect($map->sourceContent(99))->toBeNull();
});

it('rejects invalid JSON', function () {
    SourceMapLookup::fromJson('not json');
})->throws(InvalidSourceMap::class);

it('rejects unsupported versions', function () {
    SourceMapLookup::fromArray([
        'version' => 2,
        'sources' => [],
        'names' => [],
        'mappings' => '',
    ]);
})->throws(InvalidSourceMap::class);

it('rejects missing required keys', function () {
    SourceMapLookup::fromArray(['version' => 3]);
})->throws(InvalidSourceMap::class);

it('rejects indexed (sectioned) maps with UnsupportedSourceMap', function () {
    SourceMapLookup::fromArray([
        'version' => 3,
        'sections' => [['offset' => ['line' => 0, 'column' => 0], 'map' => []]],
    ]);
})->throws(UnsupportedSourceMap::class);

it('returns null when no segments exist on the target line', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        'mappings' => 'AAAA',  // one segment, line 1
    ]);
    expect($map->lookup(2, 0))->toBeNull(); // line 2 doesn't exist
});

it('returns a position for a 4-field mapped segment', function () {
    // "AAAA" on line 1: genCol=0 -> srcIdx=0, srcLine=0, srcCol=0
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['src/a.ts'],
        'names' => [],
        'mappings' => 'AAAA',
    ]);
    $pos = $map->lookup(1, 0);
    expect($pos)->not->toBeNull();
    expect($pos->sourceLine)->toBe(1);     // 0-based 0 -> 1-based 1
    expect($pos->sourceColumn)->toBe(0);
    expect($pos->sourceFileName)->toBe('src/a.ts');
    expect($pos->sourceFileIndex)->toBe(0);
    expect($pos->name)->toBeNull();
});

it('returns a position with name for a 5-field segment', function () {
    // "AAAAA" -> 5 fields all zero: maps to (0,0) with nameIndex=0
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => ['myFn'],
        'mappings' => 'AAAAA',
    ]);
    expect($map->lookup(1, 0)->name)->toBe('myFn');
});

it('returns null when best match is an unmapped (1-field) segment', function () {
    // "A" = 1-field segment at genCol=0, unmapped
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => [],
        'names' => [],
        'mappings' => 'A',
    ]);
    expect($map->lookup(1, 5))->toBeNull();
});

it('picks the last segment with generatedColumn <= column', function () {
    // "AAAA,CAEC" on line 1: seg1 at col 0, seg2 at col 1
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        'mappings' => 'AAAA,CAEC',
    ]);
    expect($map->lookup(1, 0)->sourceLine)->toBe(1);  // seg1
    expect($map->lookup(1, 5)->sourceLine)->toBe(3);  // seg2 (0 + 2 = line 2 0-based = 3 1-based)
});

it('returns null when column is before the first segment', function () {
    // "CAAA" -> first segment at genCol=1
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        'mappings' => 'CAAA',
    ]);
    expect($map->lookup(1, 0))->toBeNull();
});

it('applies sourceRoot to resolved file names', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sourceRoot' => 'webpack:///',
        'sources' => ['src/a.ts'],
        'names' => [],
        'mappings' => 'AAAA',
    ]);
    expect($map->lookup(1, 0)->sourceFileName)->toBe('webpack:///src/a.ts');
});

it('appends / to sourceRoot when it does not end with one (spec §DecodeSourceMapSources)', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sourceRoot' => 'webpack:',  // no trailing slash
        'sources' => ['src/a.ts'],
        'names' => [],
        'mappings' => 'AAAA',
    ]);
    expect($map->lookup(1, 0)->sourceFileName)->toBe('webpack:/src/a.ts');
});

it('does not add an extra / when sourceRoot already ends with one', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sourceRoot' => 'webpack:///',
        'sources' => ['src/a.ts'],
        'names' => [],
        'mappings' => 'AAAA',
    ]);
    expect($map->lookup(1, 0)->sourceFileName)->toBe('webpack:///src/a.ts');
});

it('returns null sourceFileName when sources[i] is null', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => [null],
        'names' => [],
        'mappings' => 'AAAA',
    ]);
    expect($map->lookup(1, 0)->sourceFileName)->toBeNull();
});

it('lazily parses only lines touched (sanity: second lookup reuses cache)', function () {
    // Multi-line map: line 1 and line 3 mapped, line 2 empty.
    // "AAAA;;AAAA" -> line1: (0,0,0,0), line2: empty, line3: (0,0,0,0)
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        'mappings' => 'AAAA;;AAAA',
    ]);
    // Hitting line 3 directly should work (proves state walk through empty line 2).
    expect($map->lookup(3, 0))->not->toBeNull();
    // Re-hitting line 3 should still work (proves cache doesn't corrupt state).
    expect($map->lookup(3, 0))->not->toBeNull();
});

it('returns null for positions inside unmapped segments (handcrafted)', function () {
    $map = SourceMapLookup::fromJson(file_get_contents(__DIR__ . '/../fixtures/handcrafted/unmapped-segments.js.map'));
    // Segment breakdown on line 1:
    //   seg1 genCol=0 (mapped)
    //   seg2 genCol=1 (unmapped, 1-field 'C' = delta 1)
    //   seg3 genCol=4 (mapped, 'GAKE' has first field 'G' = 3, so genCol += 3 -> 4)
    expect($map->lookup(1, 0))->not->toBeNull();   // inside seg1
    expect($map->lookup(1, 1))->toBeNull();        // inside seg2 (unmapped)
    expect($map->lookup(1, 3))->toBeNull();        // still seg2
    expect($map->lookup(1, 4))->not->toBeNull();   // inside seg3
});

it('prefixes sourceRoot (handcrafted)', function () {
    $map = SourceMapLookup::fromJson(file_get_contents(__DIR__ . '/../fixtures/handcrafted/source-root.js.map'));
    expect($map->lookup(1, 0)->sourceFileName)->toBe('webpack:///./src/a.ts');
});

it('returns null sourceFileName when sources entry is null (handcrafted)', function () {
    $map = SourceMapLookup::fromJson(file_get_contents(__DIR__ . '/../fixtures/handcrafted/null-source.js.map'));
    expect($map->lookup(1, 0)->sourceFileName)->toBeNull();
});

it('walks through empty lines correctly (handcrafted)', function () {
    $map = SourceMapLookup::fromJson(file_get_contents(__DIR__ . '/../fixtures/handcrafted/empty-lines.js.map'));
    expect($map->lookup(5, 0))->not->toBeNull();
    expect($map->lookup(5, 0)->sourceLine)->toBe(1);
});
