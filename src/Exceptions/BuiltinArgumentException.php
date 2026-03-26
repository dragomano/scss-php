<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class BuiltinArgumentException extends SassArgumentException
{
    public static function cannotOperateOnEmpty(string $context, string $target): self
    {
        return new self("$context cannot operate on an empty $target.");
    }

    public static function mustNotBeZero(string $context, string $argument): self
    {
        return new self("$context $argument must not be 0.");
    }

    public static function outOfRange(string $context, string $argument, int $value): self
    {
        return new self("$context $argument '$value' is out of range.");
    }

    public static function mustBePositiveInteger(string $context, string $argument): self
    {
        return new self("$context $argument must be a positive integer.");
    }

    public static function unsupportedUnit(string $context, string $actual, string $allowed): self
    {
        return new self("$context unsupported unit '$actual'. Allowed units: $allowed.");
    }
}
