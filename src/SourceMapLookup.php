<?php

declare(strict_types=1);

namespace Spatie\SourcemapsLookup;

use Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap;
use Spatie\SourcemapsLookup\Exceptions\UnsupportedSourceMap;
use Spatie\SourcemapsLookup\Internal\LineIndex;
use Spatie\SourcemapsLookup\Internal\LineParser;
use Spatie\SourcemapsLookup\Internal\Segment;

final class SourceMapLookup
{
    private readonly string $mappings;

    private readonly LineIndex $lineIndex;

    /** @var list<?string> */
    private readonly array $sources;

    /** @var list<?string> */
    private readonly array $sourcesContent;

    /** @var list<string> */
    private readonly array $names;

    private readonly string $sourceRoot;

    /** @var array<int, string> Packed-binary segment buffers (20 bytes/segment). */
    private array $segmentCache = [];

    /** @var array<int, array{0:int,1:int,2:int,3:int}> */
    private array $stateCache = [];

    /**
     * Reverse index: fileIndex => ("sourceLine,sourceColumn" => GeneratedPosition).
     * Built lazily on first findGenerated() call; null until then.
     *
     * @var array<int, array<string, GeneratedPosition>>|null
     */
    private ?array $reverseIndex = null;

    private function __construct(array $data)
    {
        if (isset($data['sections'])) {
            throw new UnsupportedSourceMap('Indexed (sectioned) source maps are not supported');
        }

        if (($data['version'] ?? null) !== 3) {
            throw new InvalidSourceMap('Only Source Map v3 is supported');
        }

        foreach (['sources', 'mappings'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new InvalidSourceMap("Missing required key: $required");
            }
        }

        if (! is_string($data['mappings'])) {
            throw new InvalidSourceMap('mappings must be a string');
        }
        if (! is_array($data['sources']) || ! array_is_list($data['sources'])) {
            throw new InvalidSourceMap('sources must be a list (0-indexed array)');
        }
        if (array_key_exists('names', $data) && (! is_array($data['names']) || ! array_is_list($data['names']))) {
            throw new InvalidSourceMap('names must be a list (0-indexed array)');
        }
        if (array_key_exists('sourceRoot', $data) && $data['sourceRoot'] !== null && ! is_string($data['sourceRoot'])) {
            throw new InvalidSourceMap('sourceRoot must be a string');
        }
        if (array_key_exists('sourcesContent', $data)) {
            if (! is_array($data['sourcesContent']) || ! array_is_list($data['sourcesContent'])) {
                throw new InvalidSourceMap('sourcesContent must be a list (0-indexed array)');
            }
            if (count($data['sourcesContent']) !== count($data['sources'])) {
                throw new InvalidSourceMap('sourcesContent length must match sources length');
            }
        }

        $this->mappings = $data['mappings'];
        $this->sources = $data['sources'];
        $this->names = $data['names'] ?? [];
        $this->sourcesContent = $data['sourcesContent'] ?? [];
        $this->sourceRoot = (string) ($data['sourceRoot'] ?? '');
        $this->lineIndex = new LineIndex($this->mappings);
        $this->stateCache[-1] = [0, 0, 0, 0];
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidSourceMap('Invalid JSON: '.$e->getMessage(), 0, $e);
        }
        if (! is_array($data)) {
            throw new InvalidSourceMap('Decoded JSON must be an object');
        }

        return new self($data);
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidSourceMap("Could not read source map file: $path");
        }

        return self::fromJson(file_get_contents($path));
    }

    /** @return list<?string> */
    public function sourceNames(): array
    {
        return $this->sources;
    }

    public function sourceContent(int $fileIndex): ?string
    {
        return $this->sourcesContent[$fileIndex] ?? null;
    }

    /**
     * Resolve a generated position (line, column) to its original source position.
     *
     * @param  int  $line  1-based line in the generated file.
     * @param  int  $column  0-based column in the generated file.
     * @return Position|null Returns null in two distinct cases (treated the same):
     *                       - the nearest-preceding segment for that position is a 1-field "unmapped"
     *                       segment (bundler-inserted code with no source origin);
     *                       - no segment covers the queried position (blank line, column before the
     *                       first segment, or line beyond the map).
     *
     * Throws InvalidSourceMap only if the map itself is malformed (bad mappings
     * encoding or out-of-range source/name index). A successful-but-empty lookup
     * is never an exception.
     */
    public function lookup(int $line, int $column): ?Position
    {
        $lineIdx = $line - 1;
        if ($lineIdx < 0 || $lineIdx >= $this->lineIndex->count()) {
            return null;
        }

        $packed = $this->segmentsForLine($lineIdx);
        if ($packed === '') {
            return null;
        }

        $best = $this->findBestSegment($packed, $column);
        if ($best === null || ! $best->isMapped()) {
            return null;
        }

        return new Position(
            sourceLine: $best->sourceLine + 1,
            sourceColumn: $best->sourceColumn,
            sourceFileName: $this->resolveFileName($best->sourceIndex),
            sourceFileIndex: $best->sourceIndex,
            name: $best->nameIndex !== null ? ($this->names[$best->nameIndex] ?? null) : null,
        );
    }

    /**
     * Reverse lookup: given a position in an original source file, return the
     * generated (line, column) it maps to. Exact match only — no nearest-preceding
     * fallback. Useful for editor tooling and coverage mapping, not for stack
     * traces (use lookup() for those).
     *
     * Builds a full reverse index on first call (parses every line), so the cost
     * is paid once. Callers that only use lookup() never pay this cost.
     *
     * @param  int  $fileIndex  Index into the map's sources array.
     * @param  int  $sourceLine  1-based line in the original source file.
     * @param  int  $sourceColumn  0-based column in the original source file.
     */
    public function findGenerated(int $fileIndex, int $sourceLine, int $sourceColumn): ?GeneratedPosition
    {
        $this->reverseIndex ??= $this->buildReverseIndex();
        $key = ($sourceLine - 1).','.$sourceColumn;

        return $this->reverseIndex[$fileIndex][$key] ?? null;
    }

    /** @return array<int, array<string, GeneratedPosition>> */
    private function buildReverseIndex(): array
    {
        $index = [];
        $total = $this->lineIndex->count();
        for ($lineIdx = 0; $lineIdx < $total; $lineIdx++) {
            $packed = $this->segmentsForLine($lineIdx);
            if ($packed === '') {
                continue;
            }
            $count = intdiv(strlen($packed), Segment::SIZE);
            for ($i = 0; $i < $count; $i++) {
                $seg = Segment::fromPacked($packed, $i);
                if (! $seg->isMapped()) {
                    continue;
                }
                $key = $seg->sourceLine.','.$seg->sourceColumn;
                if (! isset($index[$seg->sourceIndex][$key])) {
                    $index[$seg->sourceIndex][$key] = new GeneratedPosition(
                        line: $lineIdx + 1,
                        column: $seg->generatedColumn,
                    );
                }
            }
        }

        return $index;
    }

    private function segmentsForLine(int $lineIdx): string
    {
        if (isset($this->segmentCache[$lineIdx])) {
            return $this->segmentCache[$lineIdx];
        }

        // Find the nearest cached state before $lineIdx; walk forward from there.
        $cursor = $lineIdx - 1;
        while ($cursor >= 0 && ! isset($this->stateCache[$cursor])) {
            $cursor--;
        }
        // $cursor is now either -1 (initial state) or the most recent parsed line.

        for ($i = $cursor + 1; $i <= $lineIdx; $i++) {
            [$packed, $newState] = LineParser::parse(
                $this->mappings,
                $this->lineIndex->offset($i),
                $this->lineIndex->end($i),
                $this->stateCache[$i - 1],
                count($this->sources),
                count($this->names),
            );
            $this->segmentCache[$i] = $packed;
            $this->stateCache[$i] = $newState;
        }

        return $this->segmentCache[$lineIdx];
    }

    /**
     * Binary-search the packed buffer for the last segment with
     * generatedColumn <= $column. Only the 4-byte generatedColumn of each
     * probed segment is unpacked; the winner's full record is materialized
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

    private function resolveFileName(int $sourceIndex): ?string
    {
        $name = $this->sources[$sourceIndex] ?? null;
        if ($name === null || $this->sourceRoot === '') {
            return $name;
        }
        $prefix = str_ends_with($this->sourceRoot, '/')
            ? $this->sourceRoot
            : $this->sourceRoot.'/';

        return $prefix.$name;
    }
}
