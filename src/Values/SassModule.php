<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

final class SassModule extends AbstractSassValue
{
    public function __construct(private readonly ?string $name = null) {}

    public function toCss(): string
    {
        if ($this->name === null) {
            return 'get-module()';
        }

        return 'get-module("' . $this->name . '")';
    }

    public function isTruthy(): bool
    {
        return true;
    }
}
