<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Style;

use function explode;
use function implode;
use function mb_check_encoding;
use function substr_count;
use function trim;

final readonly class OutputOptimizer
{
    public function __construct(
        private CompressedCssFormatter $compressedCssFormatter = new CompressedCssFormatter()
    ) {}

    public function optimize(string $css, CompilerOptions $options): string
    {
        if ($options->style === Style::COMPRESSED) {
            $css = $this->compressedCssFormatter->format($css);
        }

        if ($options->splitRules) {
            $css = $this->normalizeBlockSeparation($css);
        }

        return $this->addCharsetIfNeeded($css);
    }

    private function normalizeBlockSeparation(string $css): string
    {
        $lines  = explode("\n", $css);
        $result = [];
        $depth  = 0;

        $prevClosedAtRoot = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $openBraces  = substr_count($trimmed, '{');
            $closeBraces = substr_count($trimmed, '}');

            if ($prevClosedAtRoot) {
                $result[] = '';
            }

            $result[] = $line;

            $depth += $openBraces - $closeBraces;

            $prevClosedAtRoot = $depth === 0 && $closeBraces > 0;
        }

        return implode("\n", $result);
    }

    private function addCharsetIfNeeded(string $css): string
    {
        if (! mb_check_encoding($css, 'ASCII')) {
            return '@charset "UTF-8";' . "\n" . $css;
        }

        return $css;
    }
}
