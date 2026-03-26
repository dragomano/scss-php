<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class UnsupportedColorValueException extends SassArgumentException
{
    public function __construct(string $value)
    {
        parent::__construct("Unsupported color value '$value'.");
    }
}
