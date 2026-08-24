<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function chr;
use function ctype_space;
use function ctype_xdigit;
use function dechex;
use function hexdec;
use function ord;
use function strcspn;
use function strlen;
use function substr;

/**
 * Decodes CSS/Sass escape sequences inside quoted string literal content.
 */
final class StringEscapeDecoder
{
    public const PROTECTED_HASH = "\u{D800}";

    public const PROTECTED_HASH_CODE_POINT = 0xD800;

    private const REPLACEMENT = "\u{FFFD}";

    private const REPLACEMENT_CODE_POINT = 0xFFFD;

    private const MAX_HEX_DIGITS = 6;

    public static function protectHashes(string $text): string
    {
        $length = strlen($text);
        $result = '';
        $index  = 0;

        while ($index < $length) {
            $pos = strpos($text, '#', $index);

            if ($pos === false) {
                $result .= substr($text, $index);

                break;
            }

            $backslashes = 0;

            while (
                $pos - $backslashes - 1 >= 0
                && $text[$pos - $backslashes - 1] === '\\'
            ) {
                $backslashes++;
            }

            if ($backslashes % 2 === 1 && ($text[$pos + 1] ?? '') === '{') {
                $result .= substr($text, $index, $pos - 1 - $index);
                $result .= self::PROTECTED_HASH;

                $index = $pos + 1;

                continue;
            }

            $result .= substr($text, $index, $pos + 1 - $index);

            $index = $pos + 1;
        }

        return $result;
    }

    public static function decodeLiteral(string $raw): string
    {
        $length = strlen($raw);
        $mask   = '\\#';
        $result = '';
        $index  = 0;

        while ($index < $length) {
            $plain = strcspn($raw, $mask, $index);

            if ($plain > 0) {
                $result .= substr($raw, $index, $plain);

                $index += $plain;

                continue;
            }

            $char = $raw[$index];

            if ($char === '#' && ($raw[$index + 1] ?? '') === '{') {
                $end = self::skipInterpolation($raw, $index + 1);

                $result .= substr($raw, $index, $end - $index);

                $index = $end;

                continue;
            }

            if ($char === '\\') {
                $result .= self::decodeEscapeAt($raw, $index);

                continue;
            }

            $result .= $char;

            $index++;
        }

        return $result;
    }

    public static function hexToUtf8(string $hex): string
    {
        return self::codePointToUtf8((int) hexdec($hex));
    }

    public static function encodeQuotedContent(string $decoded, string $quote): string
    {
        $length   = strlen($decoded);
        $result   = '';
        $index    = 0;
        $quoteOrd = ord($quote);

        while ($index < $length) {
            [$codePoint, $width] = self::decodeCodePointAt($decoded, $index);

            $replacement = match (true) {
                $codePoint === self::PROTECTED_HASH_CODE_POINT => null,
                $codePoint === 0x0A => '\a',
                $codePoint === 0x0D => '\d',
                $codePoint === 0x0B => '\b',
                $codePoint === 0x0C => '\c',
                $codePoint === 0x5C => '\\\\',
                $codePoint === $quoteOrd => '\\' . $quote,
                self::needsHexEscape($codePoint) => '\\' . dechex($codePoint),
                default => null,
            };

            if ($replacement === null) {
                $result .= substr($decoded, $index, $width);
            } else {
                $result .= $replacement;

                if (
                    $codePoint !== 0x5C
                    && $codePoint !== $quoteOrd
                    && self::needsSeparatorAfter($decoded, $index + $width)
                ) {
                    $result .= ' ';
                }
            }

            $index += $width;
        }

        return $result;
    }

    public static function encodeUnquotedContent(string $decoded): string
    {
        $length = strlen($decoded);
        $result = '';
        $index  = 0;

        while ($index < $length) {
            [$codePoint, $width] = self::decodeCodePointAt($decoded, $index);

            if ($codePoint === 0x0A) {
                $result .= ' ';
            } elseif (self::isPrivateUseCodePoint($codePoint)) {
                $result .= '\\' . dechex($codePoint);

                if (self::needsSeparatorAfter($decoded, $index + $width)) {
                    $result .= ' ';
                }
            } else {
                $result .= substr($decoded, $index, $width);
            }

            $index += $width;
        }

        return $result;
    }

    /**
     * @param int $openBraceIndex index of the '{' that opens the region
     */
    public static function skipInterpolation(string $text, int $openBraceIndex): int
    {
        $length = strlen($text);
        $depth  = 1;
        $index  = $openBraceIndex + 1;

        while ($index < $length && $depth > 0) {
            $char = $text[$index];

            if ($char === '"' || $char === "'") {
                $index = self::skipQuotedChunk($text, $index);

                continue;
            }

            if ($char === '\\' && $index + 1 < $length) {
                $index += 2;

                continue;
            }

            if ($char === '#' && ($text[$index + 1] ?? '') === '{') {
                $depth++;

                $index += 2;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }

            $index++;
        }

        return $index;
    }

    /**
     * @param int $startIndex index of the opening quote
     */
    public static function skipQuotedChunk(string $text, int $startIndex): int
    {
        $length = strlen($text);
        $quote  = $text[$startIndex];
        $index  = $startIndex + 1;

        while ($index < $length) {
            $char = $text[$index];

            if ($char === '\\' && $index + 1 < $length) {
                $index += 2;

                continue;
            }

            if ($char === '#' && ($text[$index + 1] ?? '') === '{') {
                $index = self::skipInterpolation($text, $index + 1);

                continue;
            }

            $isClosing = $char === $quote;

            $index++;

            if ($isClosing) {
                return $index;
            }
        }

        return $index;
    }

    private static function decodeEscapeAt(string $text, int &$index): string
    {
        $length = strlen($text);

        $index++; // skip the backslash

        if ($index >= $length) {
            return '\\';
        }

        $char = $text[$index];

        // Line continuation: backslash followed by a newline produces nothing
        if ($char === "\n") {
            $index++;

            return '';
        }

        if ($char === "\r") {
            $index++;

            if (($text[$index] ?? '') === "\n") {
                $index++;
            }

            return '';
        }

        if (ctype_xdigit($char)) {
            $hex = '';

            while ($index < $length && strlen($hex) < self::MAX_HEX_DIGITS && ctype_xdigit($text[$index])) {
                $hex .= $text[$index];

                $index++;
            }

            // A single whitespace after the escape terminates it and is consumed
            if ($index < $length && ctype_space($text[$index])) {
                $index++;
            }

            return self::hexToUtf8($hex);
        }

        // Escaped plain character: the backslash is dropped
        $index++;

        return $char;
    }

    private static function codePointToUtf8(int $codePoint): string
    {
        if (
            $codePoint <= 0
            || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)
            || $codePoint > 0x10FFFF
        ) {
            return self::REPLACEMENT;
        }

        if ($codePoint <= 0x7F) {
            return chr($codePoint);
        }

        if ($codePoint <= 0x7FF) {
            return chr(0xC0 | ($codePoint >> 6))
                . chr(0x80 | ($codePoint & 0x3F));
        }

        if ($codePoint <= 0xFFFF) {
            return chr(0xE0 | ($codePoint >> 12))
                . chr(0x80 | (($codePoint >> 6) & 0x3F))
                . chr(0x80 | ($codePoint & 0x3F));
        }

        return chr(0xF0 | ($codePoint >> 18))
            . chr(0x80 | (($codePoint >> 12) & 0x3F))
            . chr(0x80 | (($codePoint >> 6) & 0x3F))
            . chr(0x80 | ($codePoint & 0x3F));
    }

    /**
     * @return array{int, int} UTF-8 code point and its byte width
     */
    private static function decodeCodePointAt(string $value, int $index): array
    {
        $length = strlen($value);
        $byte   = ord($value[$index]);

        if ($byte < 0x80) {
            return [$byte, 1];
        }

        if ($byte >= 0xF0) {
            if (
                $index + 3 < $length
                && self::isContinuationByte(ord($value[$index + 1]))
                && self::isContinuationByte(ord($value[$index + 2]))
                && self::isContinuationByte(ord($value[$index + 3]))
            ) {
                return [
                    (($byte & 0x07) << 18)
                        | ((ord($value[$index + 1]) & 0x3F) << 12)
                        | ((ord($value[$index + 2]) & 0x3F) << 6)
                        | (ord($value[$index + 3]) & 0x3F),
                    4,
                ];
            }

            return [self::REPLACEMENT_CODE_POINT, 1];
        }

        if ($byte >= 0xE0) {
            if (
                $index + 2 < $length
                && self::isContinuationByte(ord($value[$index + 1]))
                && self::isContinuationByte(ord($value[$index + 2]))
            ) {
                return [
                    (($byte & 0x0F) << 12)
                        | ((ord($value[$index + 1]) & 0x3F) << 6)
                        | (ord($value[$index + 2]) & 0x3F),
                    3,
                ];
            }

            return [self::REPLACEMENT_CODE_POINT, 1];
        }

        if (
            $index + 1 < $length
            && self::isContinuationByte(ord($value[$index + 1]))
        ) {
            return [(($byte & 0x1F) << 6) | (ord($value[$index + 1]) & 0x3F), 2];
        }

        return [self::REPLACEMENT_CODE_POINT, 1];
    }

    private static function isContinuationByte(int $byte): bool
    {
        return ($byte & 0xC0) === 0x80;
    }

    private static function needsHexEscape(int $codePoint): bool
    {
        if ($codePoint === 0x09) {
            return false;
        }

        if ($codePoint < 0x20 || $codePoint === 0x7F) {
            return true;
        }

        return self::isPrivateUseCodePoint($codePoint);
    }

    private static function isPrivateUseCodePoint(int $codePoint): bool
    {
        return ($codePoint >= 0xE000 && $codePoint <= 0xF8FF)
            || ($codePoint >= 0xF0000 && $codePoint <= 0xFFFFD)
            || ($codePoint >= 0x100000 && $codePoint <= 0x10FFFD);
    }

    private static function needsSeparatorAfter(string $value, int $nextIndex): bool
    {
        if ($nextIndex >= strlen($value)) {
            return false;
        }

        $byte = ord($value[$nextIndex]);

        return ($byte >= 0x30 && $byte <= 0x39)
            || ($byte >= 0x41 && $byte <= 0x46)
            || ($byte >= 0x61 && $byte <= 0x66)
            || $byte === 0x20
            || $byte === 0x09;
    }
}
