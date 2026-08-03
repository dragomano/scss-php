<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\Iris\Serializers\Serializer;

use function str_starts_with;
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

        // Preserve original hex format
        if (str_starts_with($trimmed, '#')) {
            return $trimmed;
        }

        return $this->colorSerializer->serialize($this->value, $this->outputHexColors);
    }

    public function isTruthy(): bool
    {
        return true;
    }
}
