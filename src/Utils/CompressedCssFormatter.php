<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function ctype_space;
use function ctype_xdigit;
use function implode;
use function in_array;
use function ltrim;
use function max;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

final readonly class CompressedCssFormatter
{
    private const BOX_SHORTHAND_PROPERTIES = [
        'margin',
        'padding',
        'border-width',
        'border-style',
        'border-color',
        'border-radius',
        'inset',
        'scroll-margin',
        'scroll-padding',
    ];

    private const BOX_TWO_SIDED_PROPERTIES = [
        'margin-block',
        'margin-inline',
        'padding-block',
        'padding-inline',
        'inset-block',
        'inset-inline',
        'scroll-margin-block',
        'scroll-margin-inline',
        'scroll-padding-block',
        'scroll-padding-inline',
    ];

    public function format(string $css): string
    {
        $css = $this->removeRegularComments($css);
        $css = $this->compactCss($css);
        $css = $this->collapseBoxShorthandDeclarations($css);
        $css = $this->optimizeCompressedLiterals($css);

        return trim($css);
    }

    private function removeRegularComments(string $css): string
    {
        if (! str_contains($css, '/*')) {
            return $css;
        }

        $parts   = [];
        $length  = strlen($css);
        $index   = 0;
        $lastCut = 0;

        while ($index < $length) {
            if ($css[$index] === '/' && $index + 1 < $length && $css[$index + 1] === '*') {
                if ($index > $lastCut) {
                    $parts[] = substr($css, $lastCut, $index - $lastCut);
                }

                $end = strpos($css, '*/', $index + 2);

                if ($end === false) {
                    $lastCut = $index;

                    // @pest-mutate-ignore
                    break;
                }

                $end += 2;

                $comment = substr($css, $index, $end - $index);

                if ($this->shouldPreserveComment($comment)) {
                    $parts[] = $comment;
                }

                $lastCut = $end;
                $index   = $end;

                continue;
            }

            $index++;
        }

        if ($lastCut < $length) {
            $parts[] = substr($css, $lastCut);
        }

        return $parts !== [] ? implode('', $parts) : $css;
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
        $parts          = [];
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
                $parts[] = $char;

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

                $parts[] = ';';

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
                    $parts[] = ' ';
                }
            }

            $parts[] = $char;

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

        return $parts !== [] ? implode('', $parts) : '';
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

        // @pest-mutate-ignore
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

    private function collapseBoxShorthandDeclarations(string $css): string
    {
        $parts            = [];
        $length           = strlen($css);
        $index            = 0;
        $inString         = false;
        $quote            = '';
        $escaped          = false;
        $atDeclarationPos = false;

        while ($index < $length) {
            $char = $css[$index];

            if ($inString) {
                $parts[] = $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $inString = false;
                    $quote    = '';
                }

                $index++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $quote    = $char;
                $parts[]  = $char;

                $index++;

                continue;
            }

            if ($char === '{' || $char === ';') {
                $atDeclarationPos = true;

                $parts[] = $char;

                $index++;

                continue;
            }

            if (
                $atDeclarationPos
                && $this->isIdentifierChar($char)
                && ($collapsed = $this->collapseBoxShorthandAt($css, $index)) !== null
            ) {
                [$replacement, $index] = $collapsed;

                $parts[] = $replacement;

                continue;
            }

            if (! ctype_space($char)) {
                $atDeclarationPos = false;
            }

            $parts[] = $char;

            $index++;
        }

        return implode('', $parts);
    }

    /**
     * @return array{string, int}|null
     */
    private function collapseBoxShorthandAt(string $css, int $start): ?array
    {
        $length = strlen($css);
        $index  = $start;

        while ($index < $length && $this->isIdentifierChar($css[$index])) {
            $index++;
        }

        $name = substr($css, $start, $index - $start);

        if ($index >= $length || $css[$index] !== ':') {
            return null;
        }

        $lowerName = strtolower($name);

        $isTwoSided = in_array($lowerName, self::BOX_TWO_SIDED_PROPERTIES, true);

        if (! $isTwoSided && ! in_array($lowerName, self::BOX_SHORTHAND_PROPERTIES, true)) {
            return null;
        }

        $index++;

        $components = [];
        $current    = '';
        $parenDepth = 0;
        $inString   = false;
        $quote      = '';
        $escaped    = false;

        while ($index < $length) {
            $char = $css[$index];

            if ($inString) {
                $current .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $inString = false;
                }

                $index++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $quote    = $char;
                $current .= $char;

                $index++;

                continue;
            }

            if ($char === '(') {
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth = max(0, $parenDepth - 1);
            } elseif ($parenDepth === 0) {
                if (ctype_space($char)) {
                    if ($current !== '') {
                        $components[] = $current;
                        $current      = '';
                    }

                    $index++;

                    continue;
                }

                if ($char === ';' || $char === '}' || $char === '!') {
                    break;
                }
            }

            $current .= $char;

            $index++;
        }

        if ($current !== '') {
            $components[] = $current;
        }

        $collapsed = $this->collapseBoxComponents($components, $isTwoSided);

        if ($collapsed === null) {
            return null;
        }

        return [$name . ':' . implode(' ', $collapsed), $index];
    }

    /**
     * @param list<string> $components
     *
     * @return list<string>|null
     */
    private function collapseBoxComponents(array $components, bool $twoSided): ?array
    {
        if (in_array('/', $components, true)) {
            return null;
        }

        if ($twoSided) {
            if (count($components) === 2 && $components[0] === $components[1]) {
                return [$components[0]];
            }

            return null;
        }

        return match (count($components)) {
            4       => $this->collapseFourComponents($components),
            3       => $this->collapseThreeComponents($components),
            2       => $components[0] === $components[1] ? [$components[0]] : null,
            default => null,
        };
    }

    /**
     * @param list<string> $components
     *
     * @return list<string>|null
     */
    private function collapseFourComponents(array $components): ?array
    {
        if (
            $components[0] === $components[1]
            && $components[1] === $components[2]
            && $components[2] === $components[3]
        ) {
            return [$components[0]];
        }

        if ($components[0] === $components[2] && $components[1] === $components[3]) {
            return [$components[0], $components[1]];
        }

        if ($components[1] === $components[3]) {
            return [$components[0], $components[1], $components[2]];
        }

        return null;
    }

    /**
     * @param list<string> $components
     *
     * @return list<string>|null
     */
    private function collapseThreeComponents(array $components): ?array
    {
        if ($components[2] !== $components[0]) {
            return null;
        }

        if ($components[1] === $components[0]) {
            return [$components[0]];
        }

        return [$components[0], $components[1]];
    }

    private function isIdentifierChar(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || ($char >= '0' && $char <= '9')
            || in_array($char, ['-', '_'], true);
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
        $parts    = [];
        $length   = strlen($css);
        $inString = false;
        $quote    = '';
        $i        = 0;

        while ($i < $length) {
            $char = $css[$i];

            if (! $inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $quote    = $char;
                $parts[]  = $char;

                $i++;

                continue;
            }

            if ($inString) {
                if ($char === $quote && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inString = false;
                }

                $parts[] = $char;

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

                    // @pest-mutate-ignore
                    if ($after < $length && ctype_xdigit($css[$after])) {
                        continue;
                    }

                    $parts[] = $this->shortenHex($candidate);

                    $i += 1 + $hexLen;

                    continue 2;
                }
            }

            $parts[] = $char;

            $i++;
        }

        return $parts !== [] ? implode('', $parts) : '';
    }
}
