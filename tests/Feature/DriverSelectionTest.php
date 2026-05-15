<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Drivers\PhpParserDriver;
use Spatie\SourcemapsLookup\Drivers\RawSegment;
use Spatie\SourcemapsLookup\Drivers\SourceMapParserDriver;
use Spatie\SourcemapsLookup\SourceMapLookup;

it('auto-picks PhpParserDriver when no Rust subpackage is installed', function () {
    // In the main repo test run, spatie/sourcemaps-lookup-rust is NOT a dev dep.
    // So auto-detect must always return PhpParserDriver.
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.js'],
        'mappings' => 'AAAA',
    ]);

    // Probe via a public side effect: lookup works.
    expect($map->lookup(1, 0))->not->toBeNull();
});

it('accepts an explicit driver instance', function () {
    $driver = new class implements SourceMapParserDriver
    {
        public bool $loaded = false;

        public function load(string $m, int $sc, int $nc): void
        {
            $this->loaded = true;
        }

        public function lineCount(): int
        {
            return 0;
        }

        public function lookup(int $l, int $c): ?RawSegment
        {
            return null;
        }

        public function segmentsForLine(int $l): iterable
        {
            return [];
        }
    };

    SourceMapLookup::fromArray(
        ['version' => 3, 'sources' => ['a.js'], 'mappings' => ''],
        $driver,
    );

    expect($driver->loaded)->toBeTrue();
});

it('forwards sourceCount and nameCount to the driver', function () {
    $driver = new class implements SourceMapParserDriver
    {
        public int $sc = -1;

        public int $nc = -1;

        public function load(string $m, int $sc, int $nc): void
        {
            $this->sc = $sc;
            $this->nc = $nc;
        }

        public function lineCount(): int
        {
            return 0;
        }

        public function lookup(int $l, int $c): ?RawSegment
        {
            return null;
        }

        public function segmentsForLine(int $l): iterable
        {
            return [];
        }
    };

    SourceMapLookup::fromArray(
        ['version' => 3, 'sources' => ['a.js', 'b.js'], 'names' => ['x', 'y', 'z'], 'mappings' => ''],
        $driver,
    );

    expect($driver->sc)->toBe(2);
    expect($driver->nc)->toBe(3);
});

it('accepts PhpParserDriver as an explicit override', function () {
    $map = SourceMapLookup::fromArray(
        ['version' => 3, 'sources' => ['a.js'], 'mappings' => 'AAAA'],
        new PhpParserDriver,
    );

    expect($map->lookup(1, 0))->not->toBeNull();
});
