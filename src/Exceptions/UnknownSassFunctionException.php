<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class UnknownSassFunctionException extends SassArgumentException
{
    public function __construct(string $module, string $name)
    {
        parent::__construct("Unknown sass:$module function '$name'.");
    }
}
