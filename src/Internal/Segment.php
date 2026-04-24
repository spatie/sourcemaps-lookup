<?php

namespace Spatie\SourcemapsLookup\Internal;

class Segment
{
    /**
     * Bytes per packed segment record: 5 x signed int32 (native byte order).
     * Layout: generatedColumn, sourceIndex, sourceLine, sourceColumn, nameIndex.
     *
     * Signed (not unsigned) because sourceLine/sourceColumn can legitimately go
     * negative after VLQ delta accumulation. The buffer never leaves the process,
     * so native endianness is safe.
     */
    public const int SIZE = 20;

    /**
     * Sentinel for absent sourceIndex (unmapped) or nameIndex (no name).
     * Both are array indices, so never legitimately negative.
     */
    public const int NULL_FIELD = -1;

    public function __construct(
        public int $generatedColumn,
        public ?int $sourceIndex = null,
        public ?int $sourceLine = null,
        public ?int $sourceColumn = null,
        public ?int $nameIndex = null,
    ) {}

    public function isMapped(): bool
    {
        return $this->sourceIndex !== null;
    }

    /** Decode one segment out of a packed line buffer. */
    public static function fromPacked(string $packed, int $segmentIndex): self
    {
        $fields = unpack('l5', $packed, $segmentIndex * self::SIZE);
        $sourceIndex = $fields[2] === self::NULL_FIELD ? null : $fields[2];
        if ($sourceIndex === null) {
            return new self(generatedColumn: $fields[1]);
        }
        $nameIndex = $fields[5] === self::NULL_FIELD ? null : $fields[5];

        return new self(
            generatedColumn: $fields[1],
            sourceIndex: $sourceIndex,
            sourceLine: $fields[3],
            sourceColumn: $fields[4],
            nameIndex: $nameIndex,
        );
    }
}
