<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\Iris\Serializers\Serializer;

use function ctype_xdigit;
use function hexdec;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
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

        $digits = substr($hex, 1);

        if (! ctype_xdigit($digits)) {
            return $hex;
        }

        if ($hexLen === 5) {
            $digits = $digits[0] . $digits[0] . $digits[1] . $digits[1]
                . $digits[2] . $digits[2] . $digits[3] . $digits[3];
        }

        return sprintf(
            'rgba(%d, %d, %d, %s)',
            (int) hexdec(substr($digits, 0, 2)),
            (int) hexdec(substr($digits, 2, 2)),
            (int) hexdec(substr($digits, 4, 2)),
            (new SassNumber((float) hexdec(substr($digits, 6, 2)) / 255.0, compressed: $this->compressed))->toCss(),
        );
    }
}
