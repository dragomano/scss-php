<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class InvalidSyntaxException extends SassArgumentException
{
    public static function cannotDetectFromPath(string $path): self
    {
        return new self("Cannot detect syntax from path: $path");
    }
}
