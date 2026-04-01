<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use function str_contains;
use function str_replace;

final class SassString extends AbstractSassValue
{
    public function __construct(
        private readonly string $value,
        private readonly bool $quoted = false
    ) {}

    public function toCss(): string
    {
        if (! $this->quoted) {
            return $this->value;
        }

        $quote = $this->preferredQuote();

        return $quote . $this->escapeQuotedValue($quote) . $quote;
    }

    public function isTruthy(): bool
    {
        return true;
    }

    private function preferredQuote(): string
    {
        if (str_contains($this->value, '"') && ! str_contains($this->value, "'")) {
            return "'";
        }

        return '"';
    }

    private function escapeQuotedValue(string $quote): string
    {
        return str_replace($quote, '\\' . $quote, $this->value);
    }
}
