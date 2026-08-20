<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\Iris\LiteralParser;
use Bugo\Iris\Serializers\Serializer;

use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

final class SassColor extends AbstractSassValue
{
    public function __construct(
        private readonly string $value,
        private readonly bool $outputHexColors = false,
        private readonly Serializer $colorSerializer = new Serializer(),
    ) {}

    public function toCss(): string
    {
        $trimmed = trim($this->value);

        if (str_starts_with($trimmed, '#')) {
            return $this->formatHex($trimmed);
        }

        return $this->colorSerializer->serialize($this->value, $this->outputHexColors);
    }

    public function isTruthy(): bool
    {
        return true;
    }

    private function formatHex(string $hex): string
    {
        $hexLen = strlen($hex);

        if ($hexLen !== 5 && $hexLen !== 9) {
            return $hex;
        }

        $rgb = (new LiteralParser())->toRgb($hex);

        if ($rgb === null) {
            return $hex;
        }

        $r = (int) $rgb->r;
        $g = (int) $rgb->g;
        $b = (int) $rgb->b;

        return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, $this->formatAlpha($rgb->a));
    }

    private function formatAlpha(float $alpha): string
    {
        $formatted = sprintf('%.10f', $alpha);
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');

        if (str_starts_with($formatted, '0.') && strlen($formatted) > 2) {
            $formatted = substr($formatted, 1);
        }

        return $formatted;
    }
}
