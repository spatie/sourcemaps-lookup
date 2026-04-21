<?php

declare(strict_types=1);

namespace Spatie\SourcemapsLookup;

final readonly class GeneratedPosition
{
    public function __construct(
        public int $line,
        public int $column,
    ) {}
}
