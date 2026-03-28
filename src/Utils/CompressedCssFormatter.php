<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function ctype_space;
use function ctype_xdigit;
use function in_array;
use function ltrim;
use function max;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

final readonly class CompressedCssFormatter
{
    public function __construct() {}

    public function format(string $css): string
    {
        $css = $this->removeRegularComments($css);
        $css = $this->compactCss($css);
        $css = $this->optimizeCompressedLiterals($css);

        return trim($css);
    }

    private function removeRegularComments(string $css): string
    {
        $result = '';
        $length = strlen($css);
        $index  = 0;

        while ($index < $length) {
            if ($css[$index] === '/' && $index + 1 < $length && $css[$index + 1] === '*') {
                $end = strpos($css, '*/', $index + 2);

                if ($end === false) {
                    break;
                }

                $end += 2;

                $comment = substr($css, $index, $end - $index);

                if ($this->shouldPreserveComment($comment)) {
                    $result .= $comment;
                }

                $index = $end;

                continue;
            }

            $result .= $css[$index];

            $index++;
        }

        if ($index < $length) {
            $result .= substr($css, $index);
        }

        return $result;
    }

    private function shouldPreserveComment(string $comment): bool
    {
        if (str_starts_with($comment, '/*!')) {
            return true;
        }

        $inner = trim(substr($comment, 2, -2));

        return str_starts_with(ltrim($inner), '# sourceMappingURL=');
    }

    private function compactCss(string $css): string
    {
        $result         = '';
        $length         = strlen($css);
        $inString       = false;
        $quote          = '';
        $escaped        = false;
        $pendingSpace   = false;
        $lastOutputChar = '';
        $parenDepth     = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];

            if ($inString) {
                $result .= $char;

                $lastOutputChar = $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $inString = false;
                    $quote    = '';
                }

                continue;
            }

            if (ctype_space($char)) {
                $pendingSpace = true;

                continue;
            }

            if ($char === ';') {
                $next = $this->nextNonSpaceChar($css, $i + 1);

                if ($next === '}' || $next === ';') {
                    $pendingSpace = false;

                    continue;
                }

                $result .= ';';

                $lastOutputChar = ';';
                $pendingSpace   = false;

                continue;
            }

            if ($pendingSpace) {
                $next = $char;

                if (
                    $lastOutputChar !== ''
                    && ! $this->isTightPunctuation($lastOutputChar)
                    && ! $this->isTightPunctuation($char)
                    && ! $this->shouldSkipSpace($lastOutputChar, $next, $parenDepth)
                ) {
                    $result .= ' ';
                }
            }

            $result .= $char;

            $lastOutputChar = $char;
            $pendingSpace   = false;

            if ($char === '"' || $char === "'") {
                $inString = true;
                $quote    = $char;
            }

            if ($char === '(') {
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth = max(0, $parenDepth - 1);
            }
        }

        return $result;
    }

    private function isTightPunctuation(string $char): bool
    {
        return in_array($char, ['{', '}', ':', ';', ',', '/'], true);
    }

    private function nextNonSpaceChar(string $css, int $start): string
    {
        $length = strlen($css);

        for ($i = $start; $i < $length; $i++) {
            if (! ctype_space($css[$i])) {
                return $css[$i];
            }
        }

        return '';
    }

    private function shouldSkipSpace(string $previous, string $next, int $parenDepth): bool
    {
        if ($previous === ')' && $this->isIdentifierStart($next)) {
            return true;
        }

        if ($parenDepth <= 0) {
            return false;
        }

        if ($next === '*' || $next === '/') {
            return true;
        }

        return ($previous === '*' || $previous === '/') && $this->isMathOperandStart($next);
    }

    private function isMathOperandStart(string $char): bool
    {
        return ($char >= '0' && $char <= '9')
            || ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || in_array($char, ['(', '.', '%', '#', '$', '-'], true);
    }

    private function isIdentifierStart(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || in_array($char, ['_', '-'], true);
    }

    private function shortenHex(string $candidate): string
    {
        $hex = '#' . strtolower($candidate);
        $len = strlen($hex);

        if ($len === 7 && $hex[1] === $hex[2] && $hex[3] === $hex[4] && $hex[5] === $hex[6]) {
            return '#' . $hex[1] . $hex[3] . $hex[5];
        }

        if ($len === 9 && $hex[1] === $hex[2] && $hex[3] === $hex[4] && $hex[5] === $hex[6] && $hex[7] === $hex[8]) {
            return '#' . $hex[1] . $hex[3] . $hex[5] . $hex[7];
        }

        return $hex;
    }

    private function optimizeCompressedLiterals(string $css): string
    {
        $css = $this->shortenHexColors($css);

        return str_replace('hue-rotate(0deg)', 'hue-rotate(0)', $css);
    }

    private function shortenHexColors(string $css): string
    {
        $result   = '';
        $length   = strlen($css);
        $inString = false;
        $quote    = '';
        $i        = 0;

        while ($i < $length) {
            $char = $css[$i];

            if (! $inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $quote    = $char;
                $result  .= $char;

                $i++;

                continue;
            }

            if ($inString) {
                if ($char === $quote && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inString = false;
                }

                $result .= $char;

                $i++;

                continue;
            }

            if ($char === '#') {
                foreach ([8, 6] as $hexLen) {
                    if ($i + $hexLen >= $length) {
                        continue;
                    }

                    $candidate = substr($css, $i + 1, $hexLen);
                    if (! ctype_xdigit($candidate)) {
                        continue;
                    }

                    $after = $i + 1 + $hexLen;
                    if ($after < $length && ctype_xdigit($css[$after])) {
                        continue;
                    }

                    $result .= $this->shortenHex($candidate);

                    $i += 1 + $hexLen;

                    continue 2;
                }
            }

            $result .= $char;

            $i++;
        }

        return $result;
    }
}
