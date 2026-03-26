<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class CannotModifyBuiltInVariableException extends SassException
{
    public function __construct()
    {
        parent::__construct('Cannot modify built-in variable.');
    }
}
