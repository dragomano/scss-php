<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

interface SassValue
{
    public function __toString(): string;

    public function toCss(): string;

    public function isTruthy(): bool;
}
