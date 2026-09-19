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
use function strlen;
use function strpos;
use function strrpos;
use function substr_count;

use const PHP_INT_MAX;

final class SourceMappingService
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
        $originData   = (array) $origin;
        $originLine   = $originData['line'] ?? null;
        $originColumn = $originData['column'] ?? null;

        if (! is_numeric($originLine) || ! is_numeric($originColumn)) {
            return;
        }

        if ((int) $originLine < 1) {
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
    public function shiftMappingsByPrefix(array $mappings, string $prefix): array
    {
        if ($mappings === [] || $prefix === '') {
            return $mappings;
        }

        $lineShift   = substr_count($prefix, "\n");
        $lastNewline = strrpos($prefix, "\n");
        $columnShift = $lastNewline === false ? strlen($prefix) : strlen($prefix) - $lastNewline - 1;

        foreach ($mappings as $index => $mapping) {
            $line   = $mapping->generated->line;
            $column = $mapping->generated->column;

            $newColumn = $line === 1 ? $column + $columnShift : $column;

            $mappings[$index] = $mapping->withGeneratedPosition(
                new SourceMapPosition($line + $lineShift, $newColumn),
            );
        }

        return $mappings;
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

        while ($i < $oldLength && $j < $newLength) {
            if ($before[$i] === $after[$j]) {
                $map[$i] = $j;
                $i++;
                $j++;

                continue;
            }

            $deletion  = strpos($before, $after[$j], $i);
            $insertion = strpos($after, $before[$i], $j);

            $deleteSkip = $deletion === false ? PHP_INT_MAX : $deletion - $i;
            $insertSkip = $insertion === false ? PHP_INT_MAX : $insertion - $j;

            if ($deleteSkip === PHP_INT_MAX && $insertSkip === PHP_INT_MAX) {
                $map[$i] = $j;
                $i++;
                $j++;

                continue;
            }

            if ($deleteSkip <= $insertSkip) {
                while ($i < $deletion) {
                    $map[$i] = $j;
                    $i++;
                }

                continue;
            }

            $j = (int) $insertion;
        }

        while ($i < $oldLength) {
            $map[$i] = $newLength;
            $i++;
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
