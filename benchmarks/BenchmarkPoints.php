<?php

declare(strict_types=1);

namespace Spatie\SourcemapsLookup\Benchmarks;

use Spatie\SourcemapsLookup\Internal\LineIndex;
use Spatie\SourcemapsLookup\Internal\LineParser;
use Spatie\SourcemapsLookup\Internal\Segment;

final class BenchmarkPoints
{
    /**
     * Pick generated positions for stack-trace-shaped benchmarks.
     *
     * Returns [line, column, sourceIndex] tuples. Benchmark scenarios only use
     * the first two fields; the third makes the fixture selection auditable.
     *
     * @return list<array{0:int,1:int,2:int}>
     */
    public static function pick(string $fixturePath, int $maxPoints = 20, int $targetFiles = 5): array
    {
        $data = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        $mappings = $data['mappings'] ?? '';
        if ($mappings === '') {
            return [];
        }

        $lineIndex = new LineIndex($mappings);
        $sourceCount = count($data['sources'] ?? []);
        $nameCount = count($data['names'] ?? []);
        $fileOrder = [];
        $pointsByFile = [];
        $state = [0, 0, 0, 0];

        for ($lineIdx = 0; $lineIdx < $lineIndex->count(); $lineIdx++) {
            [$packed, $state] = LineParser::parse(
                $mappings,
                $lineIndex->offset($lineIdx),
                $lineIndex->end($lineIdx),
                $state,
                $sourceCount,
                $nameCount,
            );

            $segmentCount = intdiv(strlen($packed), Segment::SIZE);
            for ($segmentIndex = 0; $segmentIndex < $segmentCount; $segmentIndex++) {
                $segment = Segment::fromPacked($packed, $segmentIndex);
                if (! $segment->isMapped()) {
                    continue;
                }

                $sourceIndex = $segment->sourceIndex;
                if (! isset($pointsByFile[$sourceIndex])) {
                    if (count($fileOrder) >= $targetFiles) {
                        continue;
                    }
                    $fileOrder[] = $sourceIndex;
                    $pointsByFile[$sourceIndex] = [];
                }

                if (count($pointsByFile[$sourceIndex]) < $maxPoints) {
                    $pointsByFile[$sourceIndex][] = [$lineIdx + 1, $segment->generatedColumn, $sourceIndex];
                }

                if (count($fileOrder) >= $targetFiles && self::totalPointCount($pointsByFile) >= $maxPoints) {
                    break 2;
                }
            }
        }

        return self::roundRobin($pointsByFile, $fileOrder, $maxPoints);
    }

    /**
     * @param  list<array{0:int,1:int,2:int}>  $points
     */
    public static function writeTemp(array $points, string $prefix = 'bench-points-'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($tmp, json_encode($points, JSON_THROW_ON_ERROR));

        return $tmp;
    }

    /**
     * @param  array<int, list<array{0:int,1:int,2:int}>>  $pointsByFile
     */
    private static function totalPointCount(array $pointsByFile): int
    {
        return array_sum(array_map(count(...), $pointsByFile));
    }

    /**
     * @param  array<int, list<array{0:int,1:int,2:int}>>  $pointsByFile
     * @param  list<int>  $fileOrder
     * @return list<array{0:int,1:int,2:int}>
     */
    private static function roundRobin(array $pointsByFile, array $fileOrder, int $maxPoints): array
    {
        $points = [];
        $cursor = 0;

        while (count($points) < $maxPoints) {
            $added = false;
            foreach ($fileOrder as $sourceIndex) {
                if (! isset($pointsByFile[$sourceIndex][$cursor])) {
                    continue;
                }
                $points[] = $pointsByFile[$sourceIndex][$cursor];
                $added = true;
                if (count($points) >= $maxPoints) {
                    break 2;
                }
            }
            if (! $added) {
                break;
            }
            $cursor++;
        }

        while (count($points) > 0 && count($points) < $maxPoints) {
            $points[] = $points[count($points) % count($points)];
        }

        return $points;
    }
}
