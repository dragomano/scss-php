<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class UnknownListSeparatorException extends SassArgumentException
{
    public function __construct(string $value)
    {
        parent::__construct("Unknown list separator '$value'.");
    }
}
