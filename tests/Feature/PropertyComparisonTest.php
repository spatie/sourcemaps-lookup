<?php

declare(strict_types=1);

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

    $runningSrcIdx = 0;
    $runningSrcLine = 0;
    $runningSrcCol = 0;
    $runningNameIdx = 0;

    for ($l = 0; $l < $lineCount; $l++) {
        $lineSegs = [];
        $n = mt_rand(0, $segmentsPerLine);
        for ($s = 0; $s < $n; $s++) {
            $genColDelta = $s === 0 ? mt_rand(0, 10) : mt_rand(1, 10);

            // Shape: 0 = 1-field (unmapped), 1 = 4-field, 2 = 5-field (with name).
            $shape = mt_rand(0, 2);
            if ($shape === 0) {
                $lineSegs[] = [$genColDelta];
                continue;
            }

            $srcIdxDelta = mt_rand(-1, 1);
            $newSrcIdx = $runningSrcIdx + $srcIdxDelta;
            if ($newSrcIdx < 0 || $newSrcIdx >= count($sources)) {
                $srcIdxDelta = 0;
                $newSrcIdx = $runningSrcIdx;
            }
            $srcLineDelta = mt_rand(-2, 5);
            $srcColDelta = mt_rand(-5, 5);

            $runningSrcIdx = $newSrcIdx;
            $runningSrcLine += $srcLineDelta;
            if ($runningSrcLine < 0) $runningSrcLine = 0;
            $runningSrcCol += $srcColDelta;
            if ($runningSrcCol < 0) $runningSrcCol = 0;

            if ($shape === 1) {
                $lineSegs[] = [$genColDelta, $srcIdxDelta, $srcLineDelta, $srcColDelta];
                continue;
            }

            // shape === 2: 5-field
            $nameIdxDelta = mt_rand(-1, 1);
            $newNameIdx = $runningNameIdx + $nameIdxDelta;
            if ($newNameIdx < 0 || $newNameIdx >= count($names)) {
                $nameIdxDelta = 0;
                $newNameIdx = $runningNameIdx;
            }
            $runningNameIdx = $newNameIdx;
            $lineSegs[] = [$genColDelta, $srcIdxDelta, $srcLineDelta, $srcColDelta, $nameIdxDelta];
        }
        $segments[] = $lineSegs;
    }

    $encoder = \axy\codecs\base64vlq\Encoder::getStandardInstance();

    $mappings = implode(';', array_map(function (array $line) use ($encoder) {
        $parts = array_map(function (array $fields) use ($encoder) {
            $encoded = '';
            foreach ($fields as $f) {
                $encoded .= $encoder->encode($f);
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
            foreach ([0, 1, 5, 20, 100] as $col) {
                $pgAxy = new PosGenerated;
                $pgAxy->line = $line - 1;
                $bestAxy = null;
                foreach ($axy->find(new PosMap($pgAxy)) as $c) {
                    if ($c->generated->column <= $col) {
                        $bestAxy = $c;
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

                $oursPos = $ours->lookup($line, $col);
                $oursResult = $oursPos === null
                    ? null
                    : [
                        'sourceLine' => $oursPos->sourceLine,
                        'sourceColumn' => $oursPos->sourceColumn,
                        'sourceFileName' => $oursPos->sourceFileName,
                        'name' => $oursPos->name,
                    ];

                expect($oursResult)
                    ->toEqual($axyResult, "mismatch at seed=$seed line=$line col=$col");
            }
        }
    }
});
