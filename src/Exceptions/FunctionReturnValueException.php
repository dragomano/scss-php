<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class FunctionReturnValueException extends SassException
{
    public function __construct(string $function)
    {
        parent::__construct("Function '$function' did not return a value.");
    }
}
