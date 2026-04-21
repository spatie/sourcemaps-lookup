<?php

namespace Spatie\SourcemapsLookup\Internal;

use Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap;

class LineParser
{
    /**
     * Parse the segments between $start (inclusive) and $end (exclusive) of $mappings.
     *
     * Segments are returned as a packed binary string (20 bytes each, 5 x signed
     * int32: generatedColumn, sourceIndex, sourceLine, sourceColumn, nameIndex).
     * Absent sourceIndex/nameIndex are encoded as -1. Packing avoids allocating a
     * Segment object per mapping (~10x memory reduction on large maps).
     *
     * State is a 4-tuple [sourceIndex, sourceLine, sourceColumn, nameIndex].
     *
     * @param  array{0:int,1:int,2:int,3:int}  $state
     * @param  int  $sourceCount  Number of entries in the map's `sources` array; any decoded
     *                            sourceIndex >= this value triggers InvalidSourceMap.
     *                            Pass PHP_INT_MAX to skip the check.
     * @param  int  $nameCount  Same, for `names`.
     * @return array{0: string, 1: array{0:int,1:int,2:int,3:int}}
     */
    public static function parse(
        string $mappings,
        int $start,
        int $end,
        array $state,
        int $sourceCount = PHP_INT_MAX,
        int $nameCount = PHP_INT_MAX,
    ): array {
        $packed = '';

        $generatedColumn = 0;
        [$sourceIndex, $sourceLine, $sourceColumn, $nameIndex] = $state;

        $NULL = Segment::NULL_FIELD;

        $offset = $start;
        while ($offset < $end) {
            // Empty segment (",,"): nothing to decode, just skip the comma.
            if ($mappings[$offset] === ',') {
                $offset++;

                continue;
            }

            $segStart = $offset;

            // Field 1: generatedColumn delta, always present.
            $generatedColumn += Base64Vlq::decode($mappings, $offset);

            if ($offset >= $end || $mappings[$offset] === ',') {
                // 1-field (unmapped) segment.
                $packed .= pack('l5', $generatedColumn, $NULL, 0, 0, $NULL);
            } else {
                // Fields 2 to 4: sourceIndex, sourceLine, sourceColumn deltas.
                $sourceIndex += Base64Vlq::decode($mappings, $offset);
                if ($sourceIndex < 0 || $sourceIndex >= $sourceCount) {
                    throw new InvalidSourceMap(
                        "Segment at offset $segStart references source index $sourceIndex (out of range: $sourceCount sources)"
                    );
                }
                if ($offset >= $end || $mappings[$offset] === ',') {
                    throw new InvalidSourceMap(
                        "Invalid segment at offset $segStart: 2 fields (expected 1, 4, or 5)"
                    );
                }
                $sourceLine += Base64Vlq::decode($mappings, $offset);
                if ($offset >= $end || $mappings[$offset] === ',') {
                    throw new InvalidSourceMap(
                        "Invalid segment at offset $segStart: 3 fields (expected 1, 4, or 5)"
                    );
                }
                $sourceColumn += Base64Vlq::decode($mappings, $offset);

                if ($offset >= $end || $mappings[$offset] === ',') {
                    // 4-field segment.
                    $packed .= pack('l5', $generatedColumn, $sourceIndex, $sourceLine, $sourceColumn, $NULL);
                } else {
                    // Field 5: nameIndex delta.
                    $nameIndex += Base64Vlq::decode($mappings, $offset);
                    if ($nameIndex < 0 || $nameIndex >= $nameCount) {
                        throw new InvalidSourceMap(
                            "Segment at offset $segStart references name index $nameIndex (out of range: $nameCount names)"
                        );
                    }
                    if ($offset < $end && $mappings[$offset] !== ',') {
                        throw new InvalidSourceMap(
                            "Invalid segment at offset $segStart: more than 5 fields (expected 1, 4, or 5)"
                        );
                    }
                    $packed .= pack('l5', $generatedColumn, $sourceIndex, $sourceLine, $sourceColumn, $nameIndex);
                }
            }

            if ($offset < $end && $mappings[$offset] === ',') {
                $offset++;
            }
        }

        return [
            $packed,
            [$sourceIndex, $sourceLine, $sourceColumn, $nameIndex],
        ];
    }
}
