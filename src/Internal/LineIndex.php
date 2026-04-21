<?php

namespace Spatie\SourcemapsLookup\Internal;

use OutOfBoundsException;

class LineIndex
{
    /** @var list<int> byte offset where each line starts */
    private array $offsets;

    private int $totalLength;

    public function __construct(string $mappings)
    {
        $this->totalLength = strlen($mappings);
        $offsets = [0];
        $pos = 0;
        while (($pos = strpos($mappings, ';', $pos)) !== false) {
            $offsets[] = $pos + 1;
            $pos++;
        }
        $this->offsets = $offsets;
    }

    public function count(): int
    {
        return count($this->offsets);
    }

    public function offset(int $line): int
    {
        if ($line < 0 || $line >= count($this->offsets)) {
            throw new OutOfBoundsException("Line $line out of range (0..".(count($this->offsets) - 1).')');
        }

        return $this->offsets[$line];
    }

    public function end(int $line): int
    {
        if ($line < 0 || $line >= count($this->offsets)) {
            throw new OutOfBoundsException("Line $line out of range");
        }

        return $line + 1 < count($this->offsets)
            ? $this->offsets[$line + 1] - 1  // position of ';'
            : $this->totalLength;
    }
}
