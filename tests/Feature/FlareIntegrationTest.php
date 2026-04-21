<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Position;
use Spatie\SourcemapsLookup\SourceMapLookup;

// Mirror of Flare's Domain/Error/Support/SourceMaps/SourceMapPosition usage shape.
function flareSnippet(SourceMapLookup $map, Position $pos, int $context = 10): array
{
    $content = $map->sourceContent($pos->sourceFileIndex) ?? '';
    $lines = explode("\n", $content);
    $start = max(0, $pos->sourceLine - 1 - $context);
    $end = min(count($lines) - 1, $pos->sourceLine - 1 + $context);

    return array_slice($lines, $start, $end - $start + 1);
}

it('resolves a stack frame the way Flare does', function () {
    $json = json_encode([
        'version' => 3,
        'sources' => ['src/app.ts'],
        'sourcesContent' => [
            "line 1\nline 2\nline 3\nline 4\nline 5",
        ],
        'names' => ['myHandler'],
        'mappings' => 'AAAAA',  // (genCol=0 -> srcIdx=0 line=0 col=0 name=0) on line 1
    ]);

    $map = SourceMapLookup::fromJson($json);
    $position = $map->lookup(1, 5); // Flare passes 1-based line from the stack
    expect($position)->not->toBeNull();
    expect($position->sourceLine)->toBe(1);     // 1-based
    expect($position->sourceFileName)->toBe('src/app.ts');
    expect($position->name)->toBe('myHandler');

    $snippet = flareSnippet($map, $position, 1);
    expect($snippet)->toBe(['line 1', 'line 2']);
});

it('handles missing sourceContent gracefully', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        'mappings' => 'AAAA',
    ]);
    $pos = $map->lookup(1, 0);
    expect($map->sourceContent($pos->sourceFileIndex))->toBeNull();
});

it('returns null for unmapped frames (Flare shows "source unavailable")', function () {
    // "C" = 1-field segment at genCol=1 (unmapped)
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => [],
        'names' => [],
        'mappings' => 'C',
    ]);
    expect($map->lookup(1, 5))->toBeNull();
});
