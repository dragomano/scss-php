<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Utils\SourceMapMapping;
use Bugo\SCSS\Utils\SourceMapPosition;

use function abs;
use function count;
use function intdiv;
use function is_numeric;
use function max;
use function min;
use function property_exists;
use function strlen;

final class RenderSourceMapHelper
{
    public function shouldRemapMappingsAfterOptimization(
        ?string $sourceMapFile,
        int $mappingCount,
        string $before,
        string $after,
    ): bool {
        if ($sourceMapFile === null) {
            return false;
        }

        if ($mappingCount <= 20000) {
            return true;
        }

        $maxLength = max(strlen($before), strlen($after));

        if ($maxLength <= 150000) {
            return true;
        }

        $lengthDelta = abs(strlen($after) - strlen($before));

        if ($lengthDelta > 5000 || $mappingCount > 75000) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, SourceMapMapping> $mappings
     */
    public function appendRawMapping(
        array &$mappings,
        int $generatedLine,
        int $generatedColumn,
        int $sourceLine,
        int $sourceColumn,
    ): void {
        $mappings[] = new SourceMapMapping(
            new SourceMapPosition($generatedLine, $generatedColumn),
            new SourceMapPosition(
                max(1, $sourceLine),
                max(0, $sourceColumn - 1),
            ),
            0,
        );
    }

    /**
     * @param array<int, SourceMapMapping> $mappings
     */
    public function appendMapping(array &$mappings, int $generatedLine, int $generatedColumn, Visitable $origin): void
    {
        if (! property_exists($origin, 'line') || ! property_exists($origin, 'column')) {
            return;
        }

        $originData   = (array) $origin;
        $originLine   = $originData['line'] ?? null;
        $originColumn = $originData['column'] ?? null;

        if (! is_numeric($originLine) || ! is_numeric($originColumn)) {
            return;
        }

        $mappings[] = new SourceMapMapping(
            new SourceMapPosition($generatedLine, $generatedColumn),
            new SourceMapPosition(
                max(1, (int) $originLine),
                max(0, (int) $originColumn - 1),
            ),
            0,
        );
    }

    /**
     * @param array<int, SourceMapMapping> $mappings
     * @return array<int, SourceMapMapping>
     */
    public function remapMappingsAfterOptimization(array $mappings, string $before, string $after): array
    {
        if ($mappings === []) {
            return [];
        }

        $oldToNewOffsets  = $this->buildOldToNewOffsetMap($before, $after);
        $beforeLineStarts = $this->buildLineStartOffsets($before);
        $afterLineStarts  = $this->buildLineStartOffsets($after);
        $beforeLength     = strlen($before);

        foreach ($mappings as $index => $mapping) {
            $line   = $mapping->generated->line;
            $column = $mapping->generated->column;

            $oldOffset = $this->lineColumnToOffsetUsingLineStarts($beforeLineStarts, $beforeLength, $line, $column);
            $newOffset = $oldToNewOffsets[$oldOffset] ?? 0;

            [$newLine, $newColumn] = $this->offsetToLineColumnUsingLineStarts($afterLineStarts, $newOffset);

            $mappings[$index] = $mapping->withGeneratedPosition(
                new SourceMapPosition($newLine, $newColumn),
            );
        }

        return $mappings;
    }

    /**
     * @return array<int, int>
     */
    public function buildOldToNewOffsetMap(string $before, string $after): array
    {
        $oldLength = strlen($before);
        $newLength = strlen($after);

        $map = [];
        $i   = 0;
        $j   = 0;

        while ($i < $oldLength || $j < $newLength) {
            if ($i < $oldLength && $j < $newLength && $before[$i] === $after[$j]) {
                $map[$i] = $j;
                $i++;
                $j++;

                continue;
            }

            if ($i < $oldLength && ($j >= $newLength || ($i + 1 < $oldLength && $before[$i + 1] === $after[$j]))) {
                $map[$i] = $j;
                $i++;

                continue;
            }

            if ($j < $newLength && ($i >= $oldLength || ($j + 1 < $newLength && $before[$i] === $after[$j + 1]))) {
                $j++;

                continue;
            }

            if ($i < $oldLength) {
                $map[$i] = $j;
                $i++;

                if ($j < $newLength) {
                    $j++;
                }
            }
        }

        $map[$oldLength] = $newLength;

        return $map;
    }

    /**
     * @param array<int, int> $lineStarts
     * @return array{0: int, 1: int}
     */
    public function offsetToLineColumnUsingLineStarts(array $lineStarts, int $offset): array
    {
        if ($lineStarts === []) {
            return [1, max(0, $offset)];
        }

        $offset    = max(0, $offset);
        $left      = 0;
        $right     = count($lineStarts) - 1;
        $lineIndex = 0;

        while ($left <= $right) {
            $mid       = intdiv($left + $right, 2);
            $lineStart = $lineStarts[$mid];

            if ($lineStart <= $offset) {
                $lineIndex = $mid;
                $left      = $mid + 1;

                continue;
            }

            $right = $mid - 1;
        }

        $line   = $lineIndex + 1;
        $column = $offset - $lineStarts[$lineIndex];

        return [$line, $column];
    }

    /**
     * @return array<int, int>
     */
    private function buildLineStartOffsets(string $text): array
    {
        $length = strlen($text);
        $starts = [0];

        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] === "\n") {
                $starts[] = $i + 1;
            }
        }

        return $starts;
    }

    /**
     * @param array<int, int> $lineStarts
     */
    private function lineColumnToOffsetUsingLineStarts(array $lineStarts, int $textLength, int $line, int $column): int
    {
        if ($line <= 1) {
            return max(0, min($column, $textLength));
        }

        $lineIndex = min(max(1, $line), count($lineStarts)) - 1;
        $lineStart = $lineStarts[$lineIndex] ?? 0;

        return max(0, min($lineStart + $column, $textLength));
    }
}
