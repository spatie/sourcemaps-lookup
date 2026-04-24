<?php

namespace Spatie\SourcemapsLookup\Drivers;

use Generator;
use Spatie\SourcemapsLookup\Internal\LineIndex;
use Spatie\SourcemapsLookup\Internal\LineParser;
use Spatie\SourcemapsLookup\Internal\Segment;

final class PhpParserDriver implements SourceMapParserDriver
{
    private string $mappings;

    private LineIndex $lineIndex;

    private int $sourceCount;

    private int $nameCount;

    /** @var array<int, string> Packed 20-byte segment buffers, keyed by 0-based line. */
    private array $segmentCache = [];

    /** @var array<int, array{0:int,1:int,2:int,3:int}> VLQ state, keyed by 0-based line. -1 is the prelude. */
    private array $stateCache;

    public function load(string $mappings, int $sourceCount, int $nameCount): void
    {
        $this->mappings = $mappings;
        $this->sourceCount = $sourceCount;
        $this->nameCount = $nameCount;
        $this->lineIndex = new LineIndex($mappings);
        $this->stateCache = [-1 => [0, 0, 0, 0]];
        $this->segmentCache = [];
    }

    public function lineCount(): int
    {
        return $this->lineIndex->count();
    }

    public function lookup(int $line, int $column): ?RawSegment
    {
        if ($line < 0 || $line >= $this->lineIndex->count()) {
            return null;
        }

        $packed = $this->packedSegmentsFor($line);
        if ($packed === '') {
            return null;
        }

        $best = $this->findBestSegment($packed, $column);
        if ($best === null || ! $best->isMapped()) {
            return null;
        }

        return new RawSegment(
            generatedColumn: $best->generatedColumn,
            sourceIndex: $best->sourceIndex,
            sourceLine: $best->sourceLine,
            sourceColumn: $best->sourceColumn,
            nameIndex: $best->nameIndex,
        );
    }

    public function segmentsForLine(int $line): Generator
    {
        if ($line < 0 || $line >= $this->lineIndex->count()) {
            return;
        }
        $packed = $this->packedSegmentsFor($line);
        $count = intdiv(strlen($packed), Segment::SIZE);
        for ($i = 0; $i < $count; $i++) {
            $seg = Segment::fromPacked($packed, $i);
            if (! $seg->isMapped()) {
                continue;
            }
            yield new RawSegment(
                generatedColumn: $seg->generatedColumn,
                sourceIndex: $seg->sourceIndex,
                sourceLine: $seg->sourceLine,
                sourceColumn: $seg->sourceColumn,
                nameIndex: $seg->nameIndex,
            );
        }
    }

    /**
     * Lazy parse-up-to-line, reusing the nearest cached VLQ state.
     * Identical to the algorithm previously inlined in SourceMapLookup.
     */
    private function packedSegmentsFor(int $lineIdx): string
    {
        if (isset($this->segmentCache[$lineIdx])) {
            return $this->segmentCache[$lineIdx];
        }

        $cursor = $lineIdx - 1;
        while ($cursor >= 0 && ! isset($this->stateCache[$cursor])) {
            $cursor--;
        }

        for ($i = $cursor + 1; $i <= $lineIdx; $i++) {
            [$packed, $newState] = LineParser::parse(
                $this->mappings,
                $this->lineIndex->offset($i),
                $this->lineIndex->end($i),
                $this->stateCache[$i - 1],
                $this->sourceCount,
                $this->nameCount,
            );
            $this->segmentCache[$i] = $packed;
            $this->stateCache[$i] = $newState;
        }

        return $this->segmentCache[$lineIdx];
    }

    /**
     * Binary-search the packed buffer for the last segment with
     * generatedColumn <= $column. Only the 4-byte generatedColumn of each
     * probed segment is unpacked; the winner's full record is materialised
     * once at the end.
     */
    private function findBestSegment(string $packed, int $column): ?Segment
    {
        $count = intdiv(strlen($packed), Segment::SIZE);
        $lo = 0;
        $hi = $count - 1;
        $best = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $genCol = unpack('l', $packed, $mid * Segment::SIZE)[1];
            if ($genCol <= $column) {
                $best = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $best < 0 ? null : Segment::fromPacked($packed, $best);
    }
}
