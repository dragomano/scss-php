<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

use function str_contains;

final class MissingFunctionArgumentsException extends SassArgumentException
{
    public function __construct(string $function, string $expected)
    {
        parent::__construct(self::formatFunctionReference($function) . " expects $expected.");
    }

    public static function count(string $function, int $expected, bool $atLeast = false): self
    {
        $qualifier = $atLeast ? 'at least ' : '';
        $suffix    = $expected > 1 ? 's' : '';

        return new self($function, "$qualifier$expected argument$suffix");
    }

    public static function required(string $function, string $argument): self
    {
        return new self($function, "required argument '$argument'");
    }

    private static function formatFunctionReference(string $function): string
    {
        if (str_contains($function, '()')) {
            return $function;
        }

        return "$function()";
    }
}
