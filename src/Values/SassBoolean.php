<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

final class SassBoolean extends AbstractSassValue
{
    private static ?self $trueInstance = null;

    private static ?self $falseInstance = null;

    private function __construct(
        private readonly bool $value
    ) {}

    public static function fromBool(bool $value): self
    {
        if ($value) {
            return self::$trueInstance ??= new self(true);
        }

        return self::$falseInstance ??= new self(false);
    }

    public function toCss(): string
    {
        return $this->value ? 'true' : 'false';
    }

    public function isTruthy(): bool
    {
        return $this->value;
    }
}
