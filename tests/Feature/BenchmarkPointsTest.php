<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Benchmarks\BenchmarkPoints;

it('picks scenario points across multiple source files when the fixture has them', function () {
    $points = BenchmarkPoints::pick(__DIR__.'/../../benchmarks/fixtures/large.js.map');

    expect($points)->toHaveCount(20);

    $sourceIndexes = array_unique(array_column($points, 2));
    expect($sourceIndexes)->toHaveCount(5);
});
