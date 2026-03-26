<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class DivisionByZeroException extends SassException
{
    public function __construct()
    {
        parent::__construct('Division by zero in expression.');
    }
}
