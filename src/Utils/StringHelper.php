<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function ctype_xdigit;
use function in_array;
use function ltrim;
use function rtrim;
use function strlen;
use function substr;

final class StringHelper
{
    // @pest-mutate-ignore
    private const QUOTE_CHARS = ['"', "'"];

    public static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && self::hasMatchingQuotes($value)) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    public static function isQuoted(string $value): bool
    {
        return strlen($value) >= 2 && self::hasMatchingQuotes($value);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function parseNumberPrefix(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $length    = strlen($value);
        $index     = ($value[0] === '+' || $value[0] === '-') ? 1 : 0;
        $hasDigits = false;

        while ($index < $length && self::isAsciiDigit($value[$index])) {
            $index++;
            $hasDigits = true;
        }

        if ($index < $length && $value[$index] === '.') {
            $index++;

            while ($index < $length && self::isAsciiDigit($value[$index])) {
                $index++;
                $hasDigits = true;
            }
        }

        if (! $hasDigits) {
            return null;
        }

        return [substr($value, 0, $index), substr($value, $index)];
    }

    public static function consumeQuotedChar(string $text, int &$i, string &$quote): bool
    {
        $char = $text[$i];

        if ($quote !== '') {
            if ($char === '\\') {
                $i++;

                return true;
            }

            if ($char === $quote) {
                $quote = '';
            }

            return true;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;

            return true;
        }

        return false;
    }

    public static function hasMatchingQuotes(string $value): bool
    {
        if (strlen($value) < 2) {
            return false;
        }

        $first = $value[0];
        $last  = $value[strlen($value) - 1];

        return in_array($first, self::QUOTE_CHARS, true) && $first === $last;
    }

    public static function trimPreservingEscapeTerminator(string $value): string
    {
        $trimmed = rtrim(ltrim($value));

        if ($trimmed === $value || $trimmed === '') {
            return $trimmed;
        }

        $length = strlen($trimmed);
        $index  = $length;
        $hex    = 0;

        while ($index > 0 && $hex < 6 && ctype_xdigit($trimmed[$index - 1])) {
            $index--;

            $hex++;
        }

        if ($hex > 0 && $index > 0 && $trimmed[$index - 1] === '\\') {
            return $trimmed . ' ';
        }

        $trailingBackslashes = 0;
        $probe               = $length - 1;

        while ($probe >= 0 && $trimmed[$probe] === '\\') {
            $trailingBackslashes++;

            $probe--;
        }

        if ($trailingBackslashes % 2 === 1) {
            return $trimmed . ' ';
        }

        return $trimmed;
    }

    private static function isAsciiDigit(string $char): bool
    {
        return $char >= '0' && $char <= '9';
    }
}
