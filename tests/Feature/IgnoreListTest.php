<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap;
use Spatie\SourcemapsLookup\SourceMapLookup;

it('reports false for every source when the map has no ignoreList', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['src/app.ts', 'vendor/x.js'],
        'names' => [],
        'mappings' => '',
    ]);

    expect($map->isIgnored('src/app.ts'))->toBeFalse();
    expect($map->isIgnored('vendor/x.js'))->toBeFalse();
});

it('reports ignored sources by name', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['src/app.ts', 'vendor/x.js', 'src/b.ts', 'vendor/y.js'],
        'ignoreList' => [1, 3],
        'names' => [],
        'mappings' => '',
    ]);

    expect($map->isIgnored('src/app.ts'))->toBeFalse();
    expect($map->isIgnored('vendor/x.js'))->toBeTrue();
    expect($map->isIgnored('src/b.ts'))->toBeFalse();
    expect($map->isIgnored('vendor/y.js'))->toBeTrue();
});

it('returns false for a source that does not exist in the map', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['src/app.ts'],
        'ignoreList' => [0],
        'names' => [],
        'mappings' => '',
    ]);

    expect($map->isIgnored('does/not/exist.js'))->toBeFalse();
});

it('applies sourceRoot when matching an ignored source name', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['vendor/x.js'],
        'sourceRoot' => 'webpack://',
        'ignoreList' => [0],
        'names' => [],
        'mappings' => '',
    ]);

    // Caller queries with the resolved name, matching what Position::$sourceFileName returns.
    expect($map->isIgnored('webpack://vendor/x.js'))->toBeTrue();
    // Also matches the raw entry from sources[], since both refer to the same index.
    expect($map->isIgnored('vendor/x.js'))->toBeTrue();
});

it('rejects an ignoreList that is not a list', function () {
    SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'ignoreList' => ['not' => 'a list'],
        'names' => [],
        'mappings' => '',
    ]);
})->throws(InvalidSourceMap::class, 'ignoreList must be a list');

it('rejects non-int entries in ignoreList', function () {
    SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'ignoreList' => [0, 'oops'],
        'names' => [],
        'mappings' => '',
    ]);
})->throws(InvalidSourceMap::class, 'ignoreList entries must be integers');

it('rejects out-of-range ignoreList indices', function () {
    SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'ignoreList' => [5],
        'names' => [],
        'mappings' => '',
    ]);
})->throws(InvalidSourceMap::class, 'out of range');
