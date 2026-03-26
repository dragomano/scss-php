<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class UndefinedOperationException extends SassException
{
    public static function forExpression(string $expression): self
    {
        return new self('Undefined operation "' . $expression . '".');
    }
}
