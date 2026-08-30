<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Style;

use function mb_check_encoding;
use function str_replace;
use function strpos;
use function substr;

final readonly class OutputOptimizer
{
    public function __construct(
        private CompressedCssFormatter $compressedCssFormatter = new CompressedCssFormatter(),
        private ExpandedCssFormatter $expandedCssFormatter = new ExpandedCssFormatter(),
    ) {}

    public function optimize(string $css, CompilerOptions $options): string
    {
        if ($options->style === Style::COMPRESSED) {
            $css = $this->removeSourceMapComments($css);
        }

        $css = $options->style === Style::COMPRESSED
            ? $this->compressedCssFormatter->format($css)
            : $this->expandedCssFormatter->format($css);

        return $this->addCharsetIfNeeded($this->stripContinuationMarks($css));
    }

    private function removeSourceMapComments(string $css): string
    {
        while (($start = strpos($css, '/*# source')) !== false) {
            $end = strpos($css, '*/', $start + 2);

            if ($end === false) {
                break;
            }

            $css = substr($css, 0, $start) . substr($css, $end + 2);
        }

        return $css;
    }

    private function stripContinuationMarks(string $css): string
    {
        return str_replace(Render::CONTINUATION_MARK, '', $css);
    }

    private function addCharsetIfNeeded(string $css): string
    {
        if (! mb_check_encoding($css, 'ASCII')) {
            return '@charset "UTF-8";' . "\n" . $css;
        }

        return $css;
    }
}
