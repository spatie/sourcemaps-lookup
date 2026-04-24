<?php

namespace Spatie\SourcemapsLookup\Drivers;

/**
 * Minimal DTO returned by SourceMapParserDriver primitives.
 * Kept deliberately thin: ints only, no resolved file names or name strings.
 * SourceMapLookup wraps it into a Position with sourceRoot + names resolved.
 */
final readonly class RawSegment
{
    public function __construct(
        public int $generatedColumn,
        public ?int $sourceIndex,
        public ?int $sourceLine,
        public ?int $sourceColumn,
        public ?int $nameIndex,
    ) {}
}
