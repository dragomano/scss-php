<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

final class SassMixin extends AbstractSassValue
{
    public function __construct(private readonly string $name) {}

    public function toCss(): string
    {
        return 'get-mixin("' . $this->name . '")';
    }

    public function isTruthy(): bool
    {
        return true;
    }

    public function name(): string
    {
        return $this->name;
    }
}
