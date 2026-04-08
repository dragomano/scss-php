<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\Iris\Serializers\Serializer;

final class SassColor extends AbstractSassValue
{
    public function __construct(
        private readonly string $value,
        private readonly bool $outputHexColors = false,
        private readonly Serializer $colorSerializer = new Serializer(),
    ) {}

    public function toCss(): string
    {
        return $this->colorSerializer->serialize($this->value, $this->outputHexColors);
    }

    public function isTruthy(): bool
    {
        return true;
    }
}
