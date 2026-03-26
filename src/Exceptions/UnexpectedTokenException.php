<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class UnexpectedTokenException extends SassException
{
    public function __construct(string $expected, string $actual, int $line)
    {
        parent::__construct("Expected $expected, got $actual at line $line");
    }
}
