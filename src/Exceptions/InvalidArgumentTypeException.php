<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class InvalidArgumentTypeException extends SassArgumentException
{
    public function __construct(string $context, string $expected, string $actual)
    {
        parent::__construct("$context expects $expected, got $actual.");
    }
}
