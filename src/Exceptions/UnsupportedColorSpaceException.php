<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

use function str_contains;

final class UnsupportedColorSpaceException extends SassArgumentException
{
    public function __construct(string $space, string $context)
    {
        $function = str_contains($context, '()') ? $context : "$context()";

        parent::__construct("Unsupported color space '$space' in $function.");
    }
}
