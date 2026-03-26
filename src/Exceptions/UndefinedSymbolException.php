<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class UndefinedSymbolException extends SassException
{
    public static function variable(string $name): self
    {
        return new self("Undefined variable: \$$name");
    }

    public static function variableInModule(string $module, string $name): self
    {
        return new self("Undefined variable \$$name in module '$module'.");
    }

    public static function mixin(string $name): self
    {
        return new self("Undefined mixin: $name");
    }

    public static function function(string $name): self
    {
        return new self("Undefined function: $name");
    }
}
