<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class NonFiniteNumberException extends SassArgumentException
{
    public function __construct(string $context)
    {
        parent::__construct("$context received a non-finite number.");
    }
}
