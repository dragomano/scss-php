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

    public static function unescapeQuotedContent(string $value): string
    {
        $result = '';
        $length = strlen($value);
        $index  = 0;

        while ($index < $length) {
            $char = $value[$index];

            if ($char !== '\\' || $index + 1 >= $length) {
                $result .= $char;
                $index++;

                continue;
            }

            $next = $value[$index + 1];

            if (in_array($next, ['\\', '"', "'"], true)) {
                $result .= $next;
                $index += 2;

                continue;
            }

            $result .= '\\' . $next;
            $index += 2;
        }

        return $result;
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
}
