<?php

namespace Spatie\SourcemapsLookup;

use JsonException;
use Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap;
use Spatie\SourcemapsLookup\Exceptions\UnsupportedSourceMap;
use Spatie\SourcemapsLookup\Internal\LineIndex;
use Spatie\SourcemapsLookup\Internal\LineParser;
use Spatie\SourcemapsLookup\Internal\Segment;
use Spatie\SourcemapsLookup\Internal\WalkBack;
use Spatie\SourcemapsLookup\Scopes\Scope;

class SourceMapLookup
{
    /**
     * Default upper bound on how far scopeAt() will walk back through
     * `sourcesContent` to find the enclosing function. Overridable per-call.
     */
    public const DEFAULT_WALKBACK_LINES = 60;

    private string $mappings;

    private LineIndex $lineIndex;

    /** @var list<?string> */
    private array $sources;

    /** @var list<?string> */
    private array $sourcesContent;

    /** @var list<string> */
    private array $names;

    private string $sourceRoot;

    /** Precomputed `$sourceRoot` with a trailing `/`; empty string when no sourceRoot. */
    private string $sourceRootPrefix;

    /** @var array<int, true> Source indices flagged as third-party by `ignoreList`. */
    private array $ignoredIndices = [];

    /**
     * Lazy name→true map built on the first `isIgnored()` call. Covers both
     * raw `sources[]` entries and their `sourceRoot`-resolved forms.
     *
     * @var array<string, true>|null
     */
    private ?array $ignoredNames = null;

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

    /**
     * Lazy per-file split of `sourcesContent` into lines, used by scopeAt().
     * A `null` entry means the source has no inlined content.
     *
     * @var array<int, list<string>|null>
     */
    private array $splitLines = [];

    /**
     * Walk-back chain cache keyed by "fileIndex,sourceLine,maxLinesBack".
     * Stores the raw WalkBack::find() result so the Scope objects wrapping
     * it stay query-specific (their innermost Position carries the queried
     * column).
     *
     * @var array<string, list<array{name: ?string, line: int, column: int}>>
     */
    private array $scopeChainCache = [];

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
        if (array_key_exists('ignoreList', $data) && $data['ignoreList'] !== null) {
            if (! is_array($data['ignoreList']) || ! array_is_list($data['ignoreList'])) {
                throw new InvalidSourceMap('ignoreList must be a list (0-indexed array)');
            }
            $sourceCount = count($data['sources']);
            foreach ($data['ignoreList'] as $index) {
                if (! is_int($index)) {
                    throw new InvalidSourceMap('ignoreList entries must be integers');
                }
                if ($index < 0 || $index >= $sourceCount) {
                    throw new InvalidSourceMap("ignoreList index $index is out of range (0..".($sourceCount - 1).')');
                }
                $this->ignoredIndices[$index] = true;
            }
        }

        $this->mappings = $data['mappings'];
        $this->sources = $data['sources'];
        $this->names = $data['names'] ?? [];
        $this->sourcesContent = $data['sourcesContent'] ?? [];
        $this->sourceRoot = (string) ($data['sourceRoot'] ?? '');
        $this->sourceRootPrefix = $this->sourceRoot === '' || str_ends_with($this->sourceRoot, '/')
            ? $this->sourceRoot
            : $this->sourceRoot.'/';
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
        } catch (JsonException $e) {
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
     * Reports whether a source is marked as third-party via the `ignoreList`
     * field (ECMA-426). Accepts either the raw entry from `sources[]` or its
     * `sourceRoot`-resolved form (i.e. what `Position::$sourceFileName`
     * exposes), whichever the caller has available. Unknown names return
     * `false`.
     */
    public function isIgnored(string $source): bool
    {
        if ($this->ignoredIndices === []) {
            return false;
        }

        if ($this->ignoredNames === null) {
            $this->ignoredNames = [];
            foreach ($this->ignoredIndices as $index => $_) {
                $raw = $this->sources[$index] ?? null;
                if ($raw === null) {
                    continue;
                }
                $this->ignoredNames[$raw] = true;
                $resolved = $this->resolveFileName($index);
                if ($resolved !== null) {
                    $this->ignoredNames[$resolved] = true;
                }
            }
        }

        return isset($this->ignoredNames[$source]);
    }

    /**
     * Return a 1-based-line-keyed slice of an inlined source file.
     *
     * Line numbers are 1-based and inclusive. Out-of-range bounds are clamped:
     * $fromLine below 1 becomes 1, $toLine past the last line becomes the last
     * line. If the clamped range is empty (fromLine > toLine, or fromLine past
     * the end of the file), returns an empty array.
     *
     * Returns null when the source file has no inlined content (either the
     * fileIndex is out of range, or sourcesContent[$fileIndex] is null). This
     * mirrors sourceContent()'s null semantics so callers can distinguish
     * "no content available" from "empty range".
     *
     * @return array<int, string>|null
     */
    public function sourceLines(int $fileIndex, int $fromLine, int $toLine): ?array
    {
        $lines = $this->splitLinesFor($fileIndex);
        if ($lines === null) {
            return null;
        }

        $fromLine = max(1, $fromLine);
        $toLine = min(count($lines), $toLine);

        if ($fromLine > $toLine) {
            return [];
        }

        $result = [];
        for ($i = $fromLine; $i <= $toLine; $i++) {
            $result[$i] = $lines[$i - 1];
        }

        return $result;
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
        $zeroBasedLine = $line - 1;
        if ($zeroBasedLine < 0 || $zeroBasedLine >= $this->lineIndex->count()) {
            return null;
        }

        $packed = $this->segmentsForLine($zeroBasedLine);
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
     * Resolve a generated position to the enclosing source-language scope.
     *
     * Modeled after the consumer semantics of the ECMA-426 Scopes proposal:
     * "given a generated position, tell me the innermost source-language scope
     * and its enclosing chain." Because no bundler currently emits the
     * `scopes` field, the implementation is a heuristic polyfill that walks
     * `sourcesContent` backward looking for enclosing function declarations.
     *
     * Returns:
     *   - a `Scope` with `$parent` set to any lexically enclosing scopes;
     *   - a single-level `Scope` when only the mapping's `name` is available
     *     (no inlined `sourcesContent` on which to walk);
     *   - `null` when the generated position resolves to nothing.
     *
     * For anonymous function boundaries (e.g. `arr.map(() => { … })`), the
     * `Scope::$name` is `null` but a Scope is still returned, signalling
     * "yes, this is a function boundary whose binding we couldn't recover."
     */
    public function scopeAt(int $line, int $column, int $maxLinesBack = self::DEFAULT_WALKBACK_LINES): ?Scope
    {
        $position = $this->lookup($line, $column);
        if ($position === null) {
            return null;
        }

        $cacheKey = $position->sourceFileIndex.','.$position->sourceLine.','.$maxLinesBack;
        if (! array_key_exists($cacheKey, $this->scopeChainCache)) {
            $lines = $this->splitLinesFor($position->sourceFileIndex);
            $this->scopeChainCache[$cacheKey] = $lines === null
                ? []
                : WalkBack::find($lines, $position->sourceLine, $maxLinesBack);
        }

        $chain = $this->scopeChainCache[$cacheKey];

        if ($chain === []) {
            return $position->name !== null
                ? new Scope($position->name, $position, null)
                : null;
        }

        // Build the Scope chain outer-to-inner. The innermost entry carries the
        // queried $position verbatim; outer entries get a Position constructed
        // from the declaration line WalkBack recorded.
        $scope = null;
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $entry = $chain[$i];
            $scopePosition = $i === 0
                ? $position
                : new Position(
                    sourceLine: $entry['line'],
                    sourceColumn: $entry['column'],
                    sourceFileName: $position->sourceFileName,
                    sourceFileIndex: $position->sourceFileIndex,
                    name: null,
                );
            $scope = new Scope($entry['name'], $scopePosition, $scope);
        }

        return $scope;
    }

    /** @return list<string>|null lines of sourcesContent for $fileIndex, cached lazily. */
    private function splitLinesFor(int $fileIndex): ?array
    {
        if (array_key_exists($fileIndex, $this->splitLines)) {
            return $this->splitLines[$fileIndex];
        }

        $content = $this->sourceContent($fileIndex);

        return $this->splitLines[$fileIndex] = $content === null ? null : explode("\n", $content);
    }

    /**
     * Reverse lookup: given a position in an original source file, return the
     * generated (line, column) it maps to. Exact match only, no nearest-preceding
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
        for ($zeroBasedLine = 0; $zeroBasedLine < $total; $zeroBasedLine++) {
            $packed = $this->segmentsForLine($zeroBasedLine);
            if ($packed === '') {
                continue;
            }
            $count = intdiv(strlen($packed), Segment::SIZE);
            for ($i = 0; $i < $count; $i++) {
                $segment = Segment::fromPacked($packed, $i);
                if (! $segment->isMapped()) {
                    continue;
                }
                $key = $segment->sourceLine.','.$segment->sourceColumn;
                if (! isset($index[$segment->sourceIndex][$key])) {
                    $index[$segment->sourceIndex][$key] = new GeneratedPosition(
                        line: $zeroBasedLine + 1,
                        column: $segment->generatedColumn,
                    );
                }
            }
        }

        return $index;
    }

    private function segmentsForLine(int $zeroBasedLine): string
    {
        if (isset($this->segmentCache[$zeroBasedLine])) {
            return $this->segmentCache[$zeroBasedLine];
        }

        // Find the nearest cached state before $zeroBasedLine; walk forward from there.
        $cursor = $zeroBasedLine - 1;
        while ($cursor >= 0 && ! isset($this->stateCache[$cursor])) {
            $cursor--;
        }
        // $cursor is now either -1 (initial state) or the most recent parsed line.

        for ($i = $cursor + 1; $i <= $zeroBasedLine; $i++) {
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

        return $this->segmentCache[$zeroBasedLine];
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
        $low = 0;
        $high = $count - 1;
        $best = -1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            $generatedColumn = unpack('l', $packed, $middle * Segment::SIZE)[1];
            if ($generatedColumn <= $column) {
                $best = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $best < 0 ? null : Segment::fromPacked($packed, $best);
    }

    private function resolveFileName(int $sourceIndex): ?string
    {
        $name = $this->sources[$sourceIndex] ?? null;
        if ($name === null || $this->sourceRootPrefix === '') {
            return $name;
        }

        return $this->sourceRootPrefix.$name;
    }
}
