<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class InvalidLoopBoundaryException extends SassArgumentException
{
    public function __construct(string $value)
    {
        parent::__construct("Loop boundary must be numeric, '$value' given.");
    }
}
