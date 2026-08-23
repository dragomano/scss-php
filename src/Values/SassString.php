<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\SCSS\Utils\StringEscapeDecoder;

use function str_contains;

final class SassString extends AbstractSassValue
{
    public function __construct(
        private readonly string $value,
        private readonly bool $quoted = false,
    ) {}

    public function toCss(): string
    {
        if (! $this->quoted) {
            return StringEscapeDecoder::encodeUnquotedContent($this->value);
        }

        $quote = $this->preferredQuote();

        return $quote . StringEscapeDecoder::encodeQuotedContent($this->value, $quote) . $quote;
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
}
