<?php

namespace Spatie\SourcemapsLookup\Drivers;

/**
 * Pluggable parser backend.
 *
 * Drivers own the raw mappings string, the line index, and any per-line
 * decode caches. They are handed the mappings once via load() and then
 * answer lookup()/segmentsForLine() queries. Absolutely no concern of
 * sources[], names[], sourcesContent, sourceRoot, or ignoreList — those
 * live on SourceMapLookup.
 */
interface SourceMapParserDriver
{
    /**
     * Parse the mappings string (or prepare to parse lazily). sourceCount
     * and nameCount are used for range-checking segment indices; drivers
     * throw \Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap on violation.
     */
    public function load(string $mappings, int $sourceCount, int $nameCount): void;

    /** Total number of generated lines covered by the map. */
    public function lineCount(): int;

    /**
     * Nearest-preceding mapped segment at (0-based $line, 0-based $column).
     * Returns null when the line is out of range, when the nearest segment
     * is an unmapped (1-field) segment, or when no segment precedes the
     * queried column.
     */
    public function lookup(int $line, int $column): ?RawSegment;

    /**
     * Iterate the mapped segments of a 0-based line in generatedColumn
     * order. Unmapped segments are not yielded.
     *
     * @return iterable<RawSegment>
     */
    public function segmentsForLine(int $line): iterable;
}
