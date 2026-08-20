<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Style;

use function mb_check_encoding;

final readonly class OutputOptimizer
{
    public function __construct(
        private CompressedCssFormatter $compressedCssFormatter = new CompressedCssFormatter(),
        private ExpandedCssFormatter $expandedCssFormatter = new ExpandedCssFormatter(),
    ) {}

    public function optimize(string $css, CompilerOptions $options): string
    {
        $css = $options->style === Style::COMPRESSED
            ? $this->compressedCssFormatter->format($css)
            : $this->expandedCssFormatter->format($css);

        return $this->addCharsetIfNeeded($css);
    }

    private function addCharsetIfNeeded(string $css): string
    {
        if (! mb_check_encoding($css, 'ASCII')) {
            return '@charset "UTF-8";' . "\n" . $css;
        }

        return $css;
    }
}
