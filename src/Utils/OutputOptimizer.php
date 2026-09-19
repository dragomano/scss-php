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
    public const CHARSET_DECLARATION = "@charset \"UTF-8\";\n";

    public function __construct(
        private CompressedCssFormatter $compressedCssFormatter = new CompressedCssFormatter(),
        private ExpandedCssFormatter $expandedCssFormatter = new ExpandedCssFormatter(),
    ) {}

    public function optimizeBody(string $css, CompilerOptions $options): string
    {
        if ($options->style === Style::COMPRESSED) {
            $css = $this->removeSourceMapComments($css);
        }

        $css = $options->style === Style::COMPRESSED
            ? $this->compressedCssFormatter->format($css)
            : $this->expandedCssFormatter->format($css);

        return $this->stripContinuationMarks($css);
    }

    public function charsetPrefix(string $css): string
    {
        return mb_check_encoding($css, 'ASCII') ? '' : self::CHARSET_DECLARATION;
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
}
