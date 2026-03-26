<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

final class SassNull extends AbstractSassValue
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function toCss(): string
    {
        return 'null';
    }

    public function isTruthy(): bool
    {
        return false;
    }
}
