<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class MaxIterationsExceededException extends SassException
{
    public function __construct(string $directive)
    {
        parent::__construct("$directive exceeded maximum iteration limit.");
    }
}
