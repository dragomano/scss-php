<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\Iris\LiteralParser;
use Bugo\Iris\Serializers\Serializer;

use function sprintf;
use function str_starts_with;
use function strlen;
use function trim;

final class SassColor extends AbstractSassValue
{
    public function __construct(
        private readonly string $value,
        private readonly Serializer $colorSerializer = new Serializer(),
        private readonly bool $compressed = false,
    ) {}

    public function toCss(): string
    {
        $trimmed = trim($this->value);

        if (str_starts_with($trimmed, '#')) {
            return $this->formatHex($trimmed);
        }

        return $this->colorSerializer->serialize($this->value, $this->compressed);
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

        return sprintf(
            'rgba(%d, %d, %d, %s)',
            $r,
            $g,
            $b,
            (new SassNumber($rgb->a, compressed: $this->compressed))->toCss(),
        );
    }
}
