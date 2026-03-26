<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class IncompatibleUnitsException extends SassException
{
    public function __construct(string $left, string $right, ?string $message = null)
    {
        parent::__construct($message ?? "$left and $right have incompatible units.");
    }

    public static function functionArguments(string $function): self
    {
        return new self('', '', "$function arguments must have compatible units.");
    }
}
