<?php

namespace Spatie\SourcemapsLookup;

use JsonException;
use Spatie\SourcemapsLookup\Drivers\PhpParserDriver;
use Spatie\SourcemapsLookup\Drivers\RawSegment;
use Spatie\SourcemapsLookup\Drivers\SourceMapParserDriver;
use Spatie\SourcemapsLookup\Exceptions\DriverUnavailable;
use Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap;
use Spatie\SourcemapsLookup\Exceptions\UnsupportedSourceMap;
use Spatie\SourcemapsLookup\Internal\WalkBack;
use Spatie\SourcemapsLookup\Scopes\Scope;

class SourceMapLookup
{
    public const DEFAULT_WALKBACK_LINES = 60;

    private SourceMapParserDriver $driver;

    /** @var list<?string> */
    private array $sources;

    /** @var list<?string> */
    private array $sourcesContent;

    /** @var list<string> */
    private array $names;

    private string $sourceRoot;

    private string $sourceRootPrefix;

    /** @var array<int, true> */
    private array $ignoredIndices = [];

    /** @var array<string, true>|null */
    private ?array $ignoredNames = null;

    /** @var array<int, array<string, GeneratedPosition>>|null */
    private ?array $reverseIndex = null;

    /** @var array<int, list<string>|null> */
    private array $splitLines = [];

    /** @var array<string, list<array{name: ?string, line: int, column: int}>> */
    private array $scopeChainCache = [];

    private function __construct(array $data, ?SourceMapParserDriver $driver = null)
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

        $this->sources = $data['sources'];
        $this->names = $data['names'] ?? [];
        $this->sourcesContent = $data['sourcesContent'] ?? [];
        $this->sourceRoot = (string) ($data['sourceRoot'] ?? '');
        $this->sourceRootPrefix = $this->sourceRoot === '' || str_ends_with($this->sourceRoot, '/')
            ? $this->sourceRoot
            : $this->sourceRoot.'/';

        $this->driver = $driver ?? self::autoPickDriver();
        $this->driver->load($data['mappings'], count($this->sources), count($this->names));
    }

    public static function fromArray(array $data, ?SourceMapParserDriver $driver = null): self
    {
        return new self($data, $driver);
    }

    public static function fromJson(string $json, ?SourceMapParserDriver $driver = null): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidSourceMap('Invalid JSON: '.$e->getMessage(), 0, $e);
        }
        if (! is_array($data)) {
            throw new InvalidSourceMap('Decoded JSON must be an object');
        }

        return new self($data, $driver);
    }

    public static function fromFile(string $path, ?SourceMapParserDriver $driver = null): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidSourceMap("Could not read source map file: $path");
        }

        return self::fromJson(file_get_contents($path), $driver);
    }

    /**
     * Zero-config driver pick. If the Rust subpackage is installed and
     * the FFI .dylib loads, use Rust. Otherwise fall back to PHP.
     * Never throws DriverUnavailable — that is only for explicit requests.
     */
    private static function autoPickDriver(): SourceMapParserDriver
    {
        $rustClass = '\\Spatie\\SourcemapsLookupRust\\RustParserDriver';

        if (class_exists($rustClass)
            && extension_loaded('ffi')
            && $rustClass::isAvailable()) {
            try {
                return new $rustClass();
            } catch (DriverUnavailable) {
                // Binary load or probe failed after isAvailable() lied.
                // Fall through to PHP driver.
            }
        }

        return new PhpParserDriver();
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

    /** @return array<int, string>|null */
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

    public function lookup(int $line, int $column): ?Position
    {
        $raw = $this->driver->lookup($line - 1, $column);

        return $raw === null ? null : $this->wrap($raw);
    }

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
        $total = $this->driver->lineCount();
        for ($lineIdx = 0; $lineIdx < $total; $lineIdx++) {
            foreach ($this->driver->segmentsForLine($lineIdx) as $seg) {
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

    private function wrap(RawSegment $raw): Position
    {
        return new Position(
            sourceLine: $raw->sourceLine + 1,
            sourceColumn: $raw->sourceColumn,
            sourceFileName: $this->resolveFileName($raw->sourceIndex),
            sourceFileIndex: $raw->sourceIndex,
            name: $raw->nameIndex !== null ? ($this->names[$raw->nameIndex] ?? null) : null,
        );
    }

    /** @return list<string>|null */
    private function splitLinesFor(int $fileIndex): ?array
    {
        if (array_key_exists($fileIndex, $this->splitLines)) {
            return $this->splitLines[$fileIndex];
        }
        $content = $this->sourceContent($fileIndex);

        return $this->splitLines[$fileIndex] = $content === null ? null : explode("\n", $content);
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
