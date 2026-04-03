<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function json_encode;
use function usort;

use const JSON_UNESCAPED_SLASHES;
use const PHP_INT_MAX;

final class SourceMapGenerator
{
    private const VLQ_BASE_SHIFT = 5;

    private const VLQ_BASE_MASK = 31;

    private const VLQ_CONTINUATION_BIT = 32;

    private const BASE64_MAP = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P',
        'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'a', 'b', 'c', 'd', 'e', 'f',
        'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v',
        'w', 'x', 'y', 'z', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '+', '/',
    ];

    /**
     * @param array<int, SourceMapMapping> $mappings
     */
    public function generate(
        array $mappings,
        string $sourceFile,
        string $outputFile,
        SourceMapOptions $options,
    ): string {
        $sourceMap = [
            'version'    => 3,
            'sourceRoot' => $options->sourceMapRoot,
            'sources'    => $options->sources ?: [$sourceFile],
            'names'      => [],
            'mappings'   => $this->generateMappings($mappings, $options->outputLines),
            'file'       => $outputFile,
        ];

        if ($options->includeSources) {
            $sourceMap['sourcesContent'] = [$options->sourceContent];
        }

        $encoded = json_encode($sourceMap, JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * @param array<int, SourceMapMapping> $mappings
     */
    private function generateMappings(array $mappings, ?int $totalLines = null): string
    {
        $sorted     = true;
        $lastLine   = -1;
        $lastColumn = -1;

        foreach ($mappings as $mapping) {
            [$genLine, $genCol] = $this->extractGenerated($mapping);

            if ($genLine < $lastLine || ($genLine === $lastLine && $genCol < $lastColumn)) {
                $sorted = false;

                break;
            }

            $lastLine   = $genLine;
            $lastColumn = $genCol;
        }

        if (! $sorted) {
            /**
             * @param SourceMapMapping $a
             * @param SourceMapMapping $b
             */
            usort($mappings, function (SourceMapMapping $a, SourceMapMapping $b): int {
                [$aLine, $aCol] = $this->extractGenerated($a);
                [$bLine, $bCol] = $this->extractGenerated($b);

                if ($aLine === $bLine) {
                    return $aCol <=> $bCol;
                }

                return $aLine <=> $bLine;
            });
        }

        $result        = '';
        $lineSegments  = '';
        $lastGenLine   = 1;
        $lastGenCol    = 0;
        $lastOrigLine  = 1;
        $lastOrigCol   = 0;
        $lastSourceIdx = 0;

        foreach ($mappings as $mapping) {
            [$genLine, $genCol]   = $this->extractGenerated($mapping);
            [$origLine, $origCol] = $this->extractOriginal($mapping);

            $sourceIdx = $this->extractSourceIndex($mapping);

            // Add empty segments for lines between lastGenLine + 1 and genLine - 1
            while ($lastGenLine < $genLine) {
                if ($lineSegments !== '') {
                    $result .= $lineSegments . ';';

                    $lineSegments = '';
                } else {
                    $result .= ';';
                }

                $lastGenLine++;

                $lastGenCol = 0;
            }

            if ($lineSegments !== '') {
                $lineSegments .= ',';
            }

            $lineSegments .= $this->encodeVLQ($genCol - $lastGenCol)
                . $this->encodeVLQ($sourceIdx - $lastSourceIdx)
                . $this->encodeVLQ($origLine - $lastOrigLine)
                . $this->encodeVLQ($origCol - $lastOrigCol);

            $lastGenCol    = $genCol;
            $lastOrigLine  = $origLine;
            $lastOrigCol   = $origCol;
            $lastSourceIdx = $sourceIdx;
        }

        if ($lineSegments !== '') {
            $result .= $lineSegments;
        }

        // Add empty segments for remaining lines up to totalLines
        if ($totalLines !== null) {
            while ($lastGenLine < $totalLines - 1) {
                $result .= ';';

                $lastGenLine++;
            }
        }

        return $result;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function extractGenerated(SourceMapMapping $mapping): array
    {
        return [$mapping->generated->line, $mapping->generated->column];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function extractOriginal(SourceMapMapping $mapping): array
    {
        return [$mapping->original->line, $mapping->original->column];
    }

    private function extractSourceIndex(SourceMapMapping $mapping): int
    {
        return $mapping->sourceIndex;
    }

    private function encodeVLQ(int $value): string
    {
        /** @var array<int, string> $cache */
        static $cache = [];

        if (isset($cache[$value])) {
            return $cache[$value];
        }

        $encoded = '';

        $vlq = $value < 0 ? ((-$value) << 1) + 1 : $value << 1;

        do {
            $digit = $vlq & self::VLQ_BASE_MASK;
            $vlq   = (($vlq >> 1) & PHP_INT_MAX) >> (self::VLQ_BASE_SHIFT - 1);

            if ($vlq > 0) {
                $digit |= self::VLQ_CONTINUATION_BIT;
            }

            $encoded .= self::BASE64_MAP[$digit];
        } while ($vlq > 0);

        $cache[$value] = $encoded;

        return $encoded;
    }
}
