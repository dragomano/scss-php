<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\SCSS\Builtins\Color\ColorSerializerAdapter;
use Bugo\SCSS\Contracts\Color\ColorSerializerInterface;

final class SassColor extends AbstractSassValue
{
    public function __construct(
        private readonly string $value,
        private readonly bool $outputHexColors = false,
        private readonly ColorSerializerInterface $colorSerializer = new ColorSerializerAdapter()
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
