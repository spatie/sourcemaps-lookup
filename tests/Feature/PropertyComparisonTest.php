<?php

declare(strict_types=1);

use axy\codecs\base64vlq\Encoder;
use axy\sourcemap\PosGenerated;
use axy\sourcemap\PosMap;
use axy\sourcemap\SourceMap as AxySourceMap;
use Spatie\SourcemapsLookup\SourceMapLookup;

/**
 * Generate a deterministic random valid source map with $lineCount lines and up to
 * $segmentsPerLine segments per line. Each segment is randomly chosen as 1-field
 * (unmapped), 4-field (mapped), or 5-field (mapped-with-name) to exercise the full
 * spec shape against axy.
 */
function generateRandomMap(int $seed, int $lineCount, int $segmentsPerLine): array
{
    mt_srand($seed);

    $sources = ['src/a.ts', 'src/b.ts', 'src/c.ts'];
    $names = ['a', 'b', 'c', 'd'];
    $segments = [];

    $runningSourceIndex = 0;
    $runningSourceLine = 0;
    $runningSourceColumn = 0;
    $runningNameIndex = 0;

    for ($lineNumber = 0; $lineNumber < $lineCount; $lineNumber++) {
        $lineSegments = [];
        $segmentCount = mt_rand(0, $segmentsPerLine);
        for ($segmentNumber = 0; $segmentNumber < $segmentCount; $segmentNumber++) {
            $generatedColumnDelta = $segmentNumber === 0 ? mt_rand(0, 10) : mt_rand(1, 10);

            // Shape: 0 = 1-field (unmapped), 1 = 4-field, 2 = 5-field (with name).
            $shape = mt_rand(0, 2);
            if ($shape === 0) {
                $lineSegments[] = [$generatedColumnDelta];

                continue;
            }

            $sourceIndexDelta = mt_rand(-1, 1);
            $newSourceIndex = $runningSourceIndex + $sourceIndexDelta;
            if ($newSourceIndex < 0 || $newSourceIndex >= count($sources)) {
                $sourceIndexDelta = 0;
                $newSourceIndex = $runningSourceIndex;
            }
            $sourceLineDelta = mt_rand(-2, 5);
            $sourceColumnDelta = mt_rand(-5, 5);

            $runningSourceIndex = $newSourceIndex;
            $runningSourceLine += $sourceLineDelta;
            if ($runningSourceLine < 0) {
                $runningSourceLine = 0;
            }
            $runningSourceColumn += $sourceColumnDelta;
            if ($runningSourceColumn < 0) {
                $runningSourceColumn = 0;
            }

            if ($shape === 1) {
                $lineSegments[] = [$generatedColumnDelta, $sourceIndexDelta, $sourceLineDelta, $sourceColumnDelta];

                continue;
            }

            // shape === 2: 5-field
            $nameIndexDelta = mt_rand(-1, 1);
            $newNameIndex = $runningNameIndex + $nameIndexDelta;
            if ($newNameIndex < 0 || $newNameIndex >= count($names)) {
                $nameIndexDelta = 0;
                $newNameIndex = $runningNameIndex;
            }
            $runningNameIndex = $newNameIndex;
            $lineSegments[] = [$generatedColumnDelta, $sourceIndexDelta, $sourceLineDelta, $sourceColumnDelta, $nameIndexDelta];
        }
        $segments[] = $lineSegments;
    }

    $encoder = Encoder::getStandardInstance();

    $mappings = implode(';', array_map(function (array $line) use ($encoder) {
        $parts = array_map(function (array $fields) use ($encoder) {
            $encoded = '';
            foreach ($fields as $field) {
                $encoded .= $encoder->encode($field);
            }

            return $encoded;
        }, $line);

        return implode(',', $parts);
    }, $segments));

    return [
        'version' => 3,
        'sources' => $sources,
        'names' => $names,
        'mappings' => $mappings,
    ];
}

it('matches axy on a grid of random maps', function () {
    foreach ([1, 42, 101, 999, 2026] as $seed) {
        $data = generateRandomMap($seed, lineCount: 20, segmentsPerLine: 8);

        $axy = new AxySourceMap($data, 'rand.js.map');
        $ours = SourceMapLookup::fromArray($data);

        for ($line = 1; $line <= 20; $line++) {
            foreach ([0, 1, 5, 20, 100] as $column) {
                $axyGeneratedPosition = new PosGenerated;
                $axyGeneratedPosition->line = $line - 1;
                $bestAxy = null;
                foreach ($axy->find(new PosMap($axyGeneratedPosition)) as $candidate) {
                    if ($candidate->generated->column <= $column) {
                        $bestAxy = $candidate;
                    } else {
                        break;
                    }
                }
                // An unmapped (1-field) segment has null source fields in axy.
                // Our API returns null for such segments, so normalise axy's
                // unmapped matches to null as well.
                $axyResult = ($bestAxy === null || $bestAxy->source->fileIndex === null)
                    ? null
                    : [
                        'sourceLine' => $bestAxy->source->line + 1,
                        'sourceColumn' => $bestAxy->source->column,
                        'sourceFileName' => $bestAxy->source->fileName,
                        'name' => $bestAxy->source->name,
                    ];

                $oursPosition = $ours->lookup($line, $column);
                $oursResult = $oursPosition === null
                    ? null
                    : [
                        'sourceLine' => $oursPosition->sourceLine,
                        'sourceColumn' => $oursPosition->sourceColumn,
                        'sourceFileName' => $oursPosition->sourceFileName,
                        'name' => $oursPosition->name,
                    ];

                expect($oursResult)
                    ->toEqual($axyResult, "mismatch at seed=$seed line=$line column=$column");
            }
        }
    }
});
