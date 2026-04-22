<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Scopes\Scope;
use Spatie\SourcemapsLookup\SourceMapLookup;

/**
 * Fixture background: `tests/fixtures/scopes/frontend-errors.js.map` comes
 * straight from Vite's @flareapp/vite plugin building a React playground.
 * See the fixture's README for the `(line, col)` → expected-scope mapping.
 */
function flareMap(): SourceMapLookup
{
    return SourceMapLookup::fromFile(__DIR__.'/../fixtures/scopes/frontend-errors.js.map');
}

it('resolves the deepest user frame: thirdLevelExplode', function () {
    $scope = flareMap()->scopeAt(1, 555);
    expect($scope)->toBeInstanceOf(Scope::class);
    expect($scope->name)->toBe('thirdLevelExplode');
    expect($scope->parent)->toBeNull();
    expect($scope->position->sourceLine)->toBe(42);
});

it('resolves the next frame up: secondLevelCompute', function () {
    $scope = flareMap()->scopeAt(1, 594);
    expect($scope->name)->toBe('secondLevelCompute');
    expect($scope->parent)->toBeNull();
});

it('resolves a nested onClick inside DeeplyNestedTrigger', function () {
    $scope = flareMap()->scopeAt(1, 627);
    expect($scope->name)->toBe('onClick');
    expect($scope->parent)->not->toBeNull();
    expect($scope->parent->name)->toBe('DeeplyNestedTrigger');
});

it('resolves a nested onClick inside AsyncPromiseTrigger', function () {
    $scope = flareMap()->scopeAt(1, 828);
    expect($scope->name)->toBe('onClick');
    expect($scope->parent?->name)->toBe('AsyncPromiseTrigger');
});

it('resolves the component render-time crash in RenderCrashChild', function () {
    $scope = flareMap()->scopeAt(1, 1194);
    expect($scope->name)->toBe('RenderCrashChild');
});

it('anchors the innermost scope at the queried source position', function () {
    $scope = flareMap()->scopeAt(1, 627);
    // The resolver's Position::$sourceLine for (1, 627) should be the onClick body,
    // not the arrow declaration itself. The Scope's innermost Position matches that.
    $raw = flareMap()->lookup(1, 627);
    expect($scope->position->sourceLine)->toBe($raw->sourceLine);
    expect($scope->position->sourceFileName)->toBe($raw->sourceFileName);
});

it('anchors enclosing scopes at their declaration line', function () {
    $scope = flareMap()->scopeAt(1, 627);
    // The enclosing DeeplyNestedTrigger should be positioned at its declaration
    // (the `function DeeplyNestedTrigger` line), not the same line as the throw.
    expect($scope->parent)->not->toBeNull();
    expect($scope->parent->position->sourceLine)->toBeLessThan($scope->position->sourceLine);
});

it('returns null for a generated position with no mapping', function () {
    expect(flareMap()->scopeAt(9999, 0))->toBeNull();
});

it('falls back to the mapping name when sourcesContent is absent', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        // sourcesContent intentionally omitted — walk-back can't run
        'names' => ['answer'],
        // one 5-field mapping: generated(col 0) -> a.ts:1:0, name 'answer'
        'mappings' => 'AAAAA',
    ]);

    $scope = $map->scopeAt(1, 0);
    expect($scope)->toBeInstanceOf(Scope::class);
    expect($scope->name)->toBe('answer');
    expect($scope->parent)->toBeNull();
});

it('returns null when neither walk-back nor the mapping yields a name', function () {
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.ts'],
        'names' => [],
        // 4-field mapping: no name; and no sourcesContent so no walk-back
        'mappings' => 'AAAA',
    ]);

    expect($map->scopeAt(1, 0))->toBeNull();
});

it('respects a per-call maxLinesBack override', function () {
    // Build a source where the enclosing function is 80 lines up.
    $source = "function deep() {\n"
        .str_repeat("    // filler\n", 80)
        ."    throw new Error();\n}\n";

    // A tiny one-line bundle mapping (gen line 1 col 0 → a.js line 82 col 4)
    // encoded as a single VLQ segment "A" (generated col 0) + source index 0 +
    // source line delta and column delta. Hand-rolling this is fiddly, so we
    // exercise the override via the Flare fixture directly.
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'file' => 'bundle.js',
        'sources' => ['a.js'],
        'sourcesContent' => [$source],
        'names' => [],
        // generated (line 1, col 0) → a.js:82:4
        'mappings' => vlqEncode(0).vlqEncode(0).vlqEncode(81).vlqEncode(4),
    ]);

    // With the default 60-line cap the walk can't reach line 1; fall through to null.
    expect($map->scopeAt(1, 0, 40))->toBeNull();

    // Raise the cap past the distance (~82 lines): should find 'deep'.
    $scope = $map->scopeAt(1, 0, 200);
    expect($scope?->name)->toBe('deep');
});

it('resolves a class method via scopeAt', function () {
    $source = <<<'JS'
class Widget {
    render() {
        throw new Error('boom');
    }
}
JS;
    // Generated (1, 0) → a.js line 3, col 8 (the throw).
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.js'],
        'sourcesContent' => [$source],
        'names' => [],
        'mappings' => vlqEncode(0).vlqEncode(0).vlqEncode(2).vlqEncode(8),
    ]);

    expect($map->scopeAt(1, 0)?->name)->toBe('render');
});

it('resolves an anonymous arrow callback with a null Scope name', function () {
    $source = <<<'JS'
[1, 2, 3].map(() => {
    throw new Error('boom');
});
JS;
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.js'],
        'sourcesContent' => [$source],
        'names' => [],
        'mappings' => vlqEncode(0).vlqEncode(0).vlqEncode(1).vlqEncode(4),
    ]);

    $scope = $map->scopeAt(1, 0);
    expect($scope)->toBeInstanceOf(Scope::class);
    expect($scope->name)->toBeNull();
});

it('is not fooled by braces inside string literals', function () {
    $source = <<<'JS'
function safe() {
    const s = "}{";
    throw new Error('boom');
}
JS;
    $map = SourceMapLookup::fromArray([
        'version' => 3,
        'sources' => ['a.js'],
        'sourcesContent' => [$source],
        'names' => [],
        'mappings' => vlqEncode(0).vlqEncode(0).vlqEncode(2).vlqEncode(4),
    ]);

    expect($map->scopeAt(1, 0)?->name)->toBe('safe');
});

// `vlqEncode()` is shared from tests/Feature/VlqEdgeCasesTest.php.
