<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Style;

use function explode;
use function implode;
use function mb_check_encoding;
use function str_starts_with;
use function strpos;
use function substr;
use function substr_count;
use function trim;

final readonly class OutputOptimizer
{
    public function __construct(
        private CompressedCssFormatter $compressedCssFormatter = new CompressedCssFormatter(),
    ) {}

    public function optimize(string $css, CompilerOptions $options): string
    {
        if ($options->style === Style::COMPRESSED) {
            $css = $this->compressedCssFormatter->format($css);
        }

        if ($options->style === Style::EXPANDED) {
            $css = $this->normalizeBlockSeparation($css);
        }

        return $this->addCharsetIfNeeded($css);
    }

    private function normalizeBlockSeparation(string $css): string
    {
        $lines  = explode("\n", $css);
        $result = [];
        $depth  = 0;

        $prevClosedAtRoot          = false;
        $prevClosedInsideKeyframes = false;
        $prevMediaPrelude          = null;
        $insideKeyframes           = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $openBraces  = substr_count($trimmed, '{');
            $closeBraces = substr_count($trimmed, '}');

            $isKeyframesLine = str_starts_with($trimmed, '@') && str_contains($trimmed, 'keyframes');

            if ($depth === 0 && $openBraces > 0 && $isKeyframesLine) {
                $insideKeyframes = true;
            }

            if ($prevClosedAtRoot) {
                $shouldAddBlankLine = true;

                if ($prevClosedInsideKeyframes) {
                    $shouldAddBlankLine = false;
                }

                if (str_starts_with($trimmed, '@keyframes ')) {
                    $shouldAddBlankLine = false;
                }

                if (str_starts_with($trimmed, '@media ') && $prevMediaPrelude !== null) {
                    $nextPrelude = $this->extractMediaPrelude($trimmed);

                    if ($nextPrelude !== null && $this->isMergedMediaPair($prevMediaPrelude, $nextPrelude)) {
                        $shouldAddBlankLine = false;
                    }
                }

                if ($shouldAddBlankLine) {
                    $result[] = '';
                }
            }

            $result[] = $line;

            if ($depth === 0 && str_starts_with($trimmed, '@media ')) {
                $prevMediaPrelude = $this->extractMediaPrelude($trimmed);
            }

            $depth += $openBraces - $closeBraces;

            $prevClosedAtRoot = $depth === 0 && $closeBraces > 0;
            $prevClosedInsideKeyframes = $prevClosedAtRoot && $insideKeyframes;

            if ($depth === 0) {
                $insideKeyframes = false;
            }
        }

        return implode("\n", $result);
    }

    private function isMergedMediaPair(string $preludeA, string $preludeB): bool
    {
        return $this->isMergedExtension($preludeA, $preludeB)
            || $this->isMergedExtension($preludeB, $preludeA);
    }

    private function isMergedExtension(string $base, string $extended): bool
    {
        return str_starts_with($extended, $base . ' and ')
            || str_starts_with($extended, $base . ' not ');
    }

    private function extractMediaPrelude(string $line): ?string
    {
        if (! str_starts_with($line, '@media ')) {
            return null;
        }

        $rest     = substr($line, 8);
        $bracePos = strpos($rest, ' {');

        if ($bracePos !== false) {
            return substr($rest, 0, $bracePos);
        }

        $bracePos = strpos($rest, '{');

        if ($bracePos !== false) {
            return substr($rest, 0, $bracePos);
        }

        return $rest;
    }

    private function addCharsetIfNeeded(string $css): string
    {
        if (! mb_check_encoding($css, 'ASCII')) {
            return '@charset "UTF-8";' . "\n" . $css;
        }

        return $css;
    }
}
