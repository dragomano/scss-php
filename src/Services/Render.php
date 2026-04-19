<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\States\OutputState;
use Bugo\SCSS\Utils\DeferredChunk;
use Bugo\SCSS\Utils\OutputChunk;
use Bugo\SCSS\Utils\SourceMapMapping;
use Bugo\SCSS\Utils\SourceMapOptions;
use Bugo\SCSS\Utils\SourceMapPosition;

use function abs;
use function count;
use function explode;
use function implode;
use function is_numeric;
use function max;
use function min;
use function property_exists;
use function rtrim;
use function str_ends_with;
use function str_repeat;
use function strlen;
use function strrpos;
use function substr_count;

final readonly class Render
{
    public function __construct(
        private CompilerContext $ctx,
        private CompilerOptions $options,
        private AstValueFormatterInterface $valueFormatter,
    ) {}

    public function indentPrefix(int $indent): string
    {
        return $this->ctx->renderer->indentCache[$indent] ??= str_repeat('  ', $indent);
    }

    public function format(AstNode $node, Environment $env): string
    {
        return $this->valueFormatter->format($node, $env);
    }

    public function optimize(string $compiled): string
    {
        $optimized = $this->ctx->optimizer->optimize($compiled, $this->options);

        if ($optimized !== $compiled && $this->shouldRemapMappingsAfterOptimization($compiled, $optimized)) {
            $this->remapMappingsAfterOptimization($compiled, $optimized);
        }

        return $optimized;
    }

    public function appendChunk(string &$output, string $chunk, ?Visitable $origin = null): void
    {
        if ($chunk === '') {
            return;
        }

        $sourceMapState  = $this->ctx->sourceMapState;
        $collectMappings = $sourceMapState->collectMappings;

        if (! $collectMappings) {
            $output .= $chunk;

            return;
        }

        if ($origin !== null) {
            $indent = 0;
            while ($indent < strlen($chunk) && $chunk[$indent] === ' ') {
                $indent++;
            }

            $baseColumn = $sourceMapState->generatedColumn;

            $sourceMapState->generatedColumn = $baseColumn + $indent;

            $this->appendMapping($origin);

            if ($sourceMapState->pendingValueMappings !== []) {
                $remaining = [];

                foreach ($sourceMapState->pendingValueMappings as $pending) {
                    if ($pending['owner'] === $origin) {
                        $sourceMapState->generatedColumn = $baseColumn + $pending['offset'];

                        $this->appendRawMapping($pending['line'], $pending['column']);
                    } else {
                        $remaining[] = $pending;
                    }
                }

                $sourceMapState->pendingValueMappings = $remaining;
            }

            $sourceMapState->generatedColumn = $baseColumn;
        }

        $output .= $chunk;

        $length       = strlen($chunk);
        $newLineCount = substr_count($chunk, "\n");

        if ($newLineCount === 0) {
            $sourceMapState->generatedColumn += $length;

            return;
        }

        $sourceMapState->generatedLine += $newLineCount;

        $sourceMapState->generatedColumn = $length - (int) strrpos($chunk, "\n") - 1;
    }

    public function outputState(): OutputState
    {
        return $this->ctx->outputState;
    }

    public function trimTrailingNewlines(string $value): string
    {
        $length = strlen($value);

        if ($length === 0 || $value[$length - 1] !== "\n") {
            return $value;
        }

        return rtrim($value, "\n");
    }

    public function trimAndAdjustState(string $value): string
    {
        $trimmed = $this->trimTrailingNewlines($value);

        if ($trimmed === $value || ! $this->ctx->sourceMapState->collectMappings) {
            return $trimmed;
        }

        $state   = $this->ctx->sourceMapState;
        $removed = strlen($value) - strlen($trimmed);

        $state->generatedLine -= $removed;

        $lastNl = strrpos($trimmed, "\n");
        $state->generatedColumn = $lastNl === false
            ? strlen($trimmed)
            : strlen($trimmed) - $lastNl - 1;

        return $trimmed;
    }

    public function indentLines(string $text, string $prefix): string
    {
        if ($text === '' || $prefix === '') {
            return $text;
        }

        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }

            $lines[$index] = $prefix . $line;
        }

        return implode("\n", $lines);
    }

    public function outputSeparator(): string
    {
        return $this->ctx->renderer->separator;
    }

    public function collectSourceMappings(): bool
    {
        return $this->ctx->sourceMapState->collectMappings;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public function savePosition(): array
    {
        $state = $this->ctx->sourceMapState;

        return [$state->generatedLine, $state->generatedColumn, count($state->mappings)];
    }

    /**
     * @param array{0: int, 1: int, 2: int} $saved
     */
    public function restorePosition(array $saved): void
    {
        $state = $this->ctx->sourceMapState;

        $state->generatedLine   = $saved[0];
        $state->generatedColumn = $saved[1];

        if (count($state->mappings) > $saved[2]) {
            array_splice($state->mappings, $saved[2]);
        }
    }

    public function addPendingValueMapping(int $offset, int $sourceLine, int $sourceColumn, object $owner): void
    {
        if (! $this->ctx->sourceMapState->collectMappings) {
            return;
        }

        $this->ctx->sourceMapState->pendingValueMappings[] = [
            'offset' => $offset,
            'line'   => $sourceLine,
            'column' => $sourceColumn,
            'owner'  => $owner,
        ];
    }

    /**
     * @param array{0: int, 1: int, 2: int} $saved
     */
    public function createDeferredChunk(string $chunk, array $saved): DeferredChunk
    {
        return new DeferredChunk(
            $chunk,
            $saved[0],
            $saved[1],
            array_slice($this->ctx->sourceMapState->mappings, $saved[2]),
        );
    }

    public function appendDeferredChunk(string &$output, DeferredChunk $deferred): void
    {
        $startLine   = $this->ctx->sourceMapState->generatedLine;
        $startColumn = $this->ctx->sourceMapState->generatedColumn;

        $this->appendChunk($output, $deferred->content());

        if (! $this->ctx->sourceMapState->collectMappings || $deferred->mappings === []) {
            return;
        }

        foreach ($deferred->mappings as $mapping) {
            $generated = $mapping->generated;
            $lineDelta = $generated->line - $deferred->baseLine;
            $column    = $lineDelta === 0
                ? $startColumn + ($generated->column - $deferred->baseColumn)
                : $generated->column;

            $this->ctx->sourceMapState->mappings[] = $mapping->withGeneratedPosition(
                new SourceMapPosition($startLine + $lineDelta, $column),
            );
        }
    }

    public function appendOutputChunk(string &$output, OutputChunk $chunk): void
    {
        if ($chunk instanceof DeferredChunk) {
            $this->appendDeferredChunk($output, $chunk);

            return;
        }

        $this->appendChunk($output, $chunk->content());
    }

    public function buildSourceMap(string $compiled, string $source): string
    {
        $mappings = $this->ctx->sourceMapState->mappings;

        $outputLines = substr_count($compiled, "\n") + 1;

        if ($compiled !== '' && str_ends_with($compiled, "\n")) {
            $outputLines--;
        }

        $options = new SourceMapOptions(
            outputLines: $outputLines,
            sourceContent: $this->options->includeSources ? $source : '',
            includeSources: $this->options->includeSources,
        );

        return $this->ctx->sourceMapGenerator->generate(
            $mappings,
            $this->ctx->currentSourceFile,
            $this->options->outputFile,
            $options,
        );
    }

    private function shouldRemapMappingsAfterOptimization(string $before, string $after): bool
    {
        if ($this->options->sourceMapFile === null) {
            return false;
        }

        $mappingCount = count($this->ctx->sourceMapState->mappings);

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

    private function appendRawMapping(int $sourceLine, int $sourceColumn): void
    {
        $this->ctx->sourceMapState->mappings[] = new SourceMapMapping(
            new SourceMapPosition(
                $this->ctx->sourceMapState->generatedLine,
                $this->ctx->sourceMapState->generatedColumn,
            ),
            new SourceMapPosition(
                max(1, $sourceLine),
                max(0, $sourceColumn - 1),
            ),
            0,
        );
    }

    private function appendMapping(Visitable $origin): void
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

        $this->ctx->sourceMapState->mappings[] = new SourceMapMapping(
            new SourceMapPosition(
                $this->ctx->sourceMapState->generatedLine,
                $this->ctx->sourceMapState->generatedColumn,
            ),
            new SourceMapPosition(
                max(1, (int) $originLine),
                max(0, (int) $originColumn - 1),
            ),
            0,
        );
    }

    private function remapMappingsAfterOptimization(string $before, string $after): void
    {
        if ($this->ctx->sourceMapState->mappings === []) {
            return;
        }

        $oldToNewOffsets  = $this->buildOldToNewOffsetMap($before, $after);
        $beforeLineStarts = $this->buildLineStartOffsets($before);
        $afterLineStarts  = $this->buildLineStartOffsets($after);
        $beforeLength     = strlen($before);

        foreach ($this->ctx->sourceMapState->mappings as $index => $mapping) {
            $line   = $mapping->generated->line;
            $column = $mapping->generated->column;

            $oldOffset = $this->lineColumnToOffsetUsingLineStarts($beforeLineStarts, $beforeLength, $line, $column);
            $newOffset = $oldToNewOffsets[$oldOffset] ?? 0;

            [$newLine, $newColumn] = $this->offsetToLineColumnUsingLineStarts($afterLineStarts, $newOffset);

            $this->ctx->sourceMapState->mappings[$index] = $mapping->withGeneratedPosition(
                new SourceMapPosition($newLine, $newColumn),
            );
        }
    }

    /**
     * @return array<int, int>
     */
    private function buildOldToNewOffsetMap(string $before, string $after): array
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

    /**
     * @param array<int, int> $lineStarts
     * @return array{0: int, 1: int}
     */
    private function offsetToLineColumnUsingLineStarts(array $lineStarts, int $offset): array
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
}
