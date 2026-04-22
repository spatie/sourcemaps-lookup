<?php

namespace Spatie\SourcemapsLookup;

readonly class Position
{
    public function __construct(
        public int $sourceLine,
        public int $sourceColumn,
        public ?string $sourceFileName,
        public int $sourceFileIndex,
        public ?string $name,
    ) {}
}
