<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

abstract class AbstractSassValue implements SassValue
{
    public function __toString(): string
    {
        return $this->toCss();
    }
}
