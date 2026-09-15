<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function array_filter;
use function array_values;
use function implode;
use function max;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_split;
use function strlen;
use function strpos;
use function strspn;
use function substr;
use function substr_count;
use function trim;

final class SelectorHelper
{
    /**
     * @return array<int, string>
     */
    public static function splitList(string $selector, bool $filterEmpty = true): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;

        foreach (str_split($selector) as $i => $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim(substr($selector, $start, $i - $start));
                $start   = $i + 1;
            }
        }

        $parts[] = trim(substr($selector, $start));

        return $filterEmpty
            ? array_values(array_filter($parts, static fn(string $part): bool => $part !== ''))
            : $parts;
    }

    public static function resolveNested(string $selector, string $parentSelector): string
    {
        return self::computeNested($selector, $parentSelector);
    }

    /**
     * @return array<int, string>
     */
    public static function splitComponents(string $selector): array
    {
        $parts    = [];
        $buffer   = '';
        $depth    = 0;
        $brackets = 0;
        $quote    = '';
        $length   = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if ($quote !== '') {
                $buffer .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $selector[$i + 1];

                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                $buffer .= $char;

                continue;
            }

            if ($char === '[') {
                $brackets++;
            } elseif ($char === ']' && $brackets > 0) {
                $brackets--;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && $depth > 0) {
                $depth--;
            } elseif ($depth === 0 && $brackets === 0 && self::isWhitespace($char)) {
                if ($buffer !== '') {
                    $parts[] = $buffer;

                    $buffer = '';
                }

                continue;
            }

            $buffer .= $char;
        }

        if ($buffer !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }

    private static function computeNested(string $selector, string $parentSelector): string
    {
        if (! str_contains($selector, ',') && ! str_contains($parentSelector, ',')) {
            $selector       = trim($selector);
            $parentSelector = trim($parentSelector);

            if ($selector === '' || $parentSelector === '') {
                return $selector;
            }

            return str_contains($selector, '&')
                ? self::normalizeCombinatorSpacing(str_replace('&', $parentSelector, $selector))
                : $selector;
        }

        $selectorParts = self::splitListRaw($selector);
        $parentParts   = self::splitListRaw($parentSelector);

        if ($selectorParts === [] || $parentParts === []) {
            return $selector;
        }

        if (str_contains($selector, '&') && self::allAmpersandsInsideParens($selector)) {
            if (substr_count($selector, '&') === 1) {
                $ampPos       = (int) strpos($selector, '&');
                $left         = substr($selector, 0, $ampPos);
                $right        = substr($selector, $ampPos + 1);
                $suffixLength = strspn($right, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-');
                $suffix       = substr($right, 0, $suffixLength);
                $rest         = substr($right, $suffixLength);
                $expanded     = array_map(
                    static fn(string $parent): string => trim($parent) . $suffix,
                    $parentParts,
                );

                return $left . implode(', ', $expanded) . $rest;
            }

            return str_replace('&', implode(', ', $parentParts), $selector);
        }

        $resolved = [];
        $breaks   = [];

        $multiAmpersandsHandled = false;

        foreach ($parentParts as $pi => $parentPart) {
            $parentHasBreak = str_contains($parentPart, "\n");
            $trimmedParent  = ltrim($parentPart);

            foreach ($selectorParts as $selectorPart) {
                $trimmedSelector = ltrim($selectorPart);

                $break = ($parentHasBreak || ($pi > 0 && str_ends_with($parentParts[$pi - 1], "\n"))) && $pi > 0;

                if (str_contains($trimmedSelector, '&')) {
                    if (self::countTopLevelAmpersands($trimmedSelector) > 1) {
                        if ($multiAmpersandsHandled) {
                            continue;
                        }

                        $multiAmpersandsHandled = true;

                        foreach (self::expandAmpersandVariants($trimmedSelector, $parentParts) as $variant) {
                            $resolved[] = self::normalizeCombinatorSpacing($variant);
                            $breaks[]   = false;
                        }

                        continue;
                    }

                    $resolved[] = self::normalizeCombinatorSpacing(
                        self::expandAmpersandVariants($trimmedSelector, [$trimmedParent])[0],
                    );

                    $breaks[] = $break;

                    continue;
                }

                $resolved[] = $trimmedParent . ' ' . $trimmedSelector;
                $breaks[]   = $break;
            }
        }

        $result = '';

        foreach ($resolved as $i => $part) {
            if ($i > 0) {
                $result .= $breaks[$i] ? ",\n" : ', ';
            }

            $result .= $part;
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    /**
     * @return list<string>
     */
    private static function splitListRaw(string $selector): array
    {
        $parts  = [];
        $depth  = 0;
        $start  = 0;
        $length = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = substr($selector, $start, $i - $start);
                $start   = $i + 1;
            }
        }

        $parts[] = substr($selector, $start);

        return array_values(array_filter($parts, static fn(string $part): bool => trim($part) !== ''));
    }

    /**
     * @param list<string> $parentParts
     * @return list<string>
     */
    private static function expandAmpersandVariants(string $selector, array $parentParts): array
    {
        $ampersandCount = self::countTopLevelAmpersands($selector);

        if ($ampersandCount <= 1) {
            return [str_replace('&', trim($parentParts[0] ?? ''), $selector)];
        }

        $segments     = self::splitByTopLevelAmpersands($selector);
        $partCount    = count($parentParts);
        $combinations = $partCount ** $ampersandCount;
        $result       = [];

        for ($combo = 0; $combo < $combinations; $combo++) {
            $variant    = $segments[0];
            $remaining  = $combo;

            for ($ampIndex = 0; $ampIndex < $ampersandCount; $ampIndex++) {
                $power      = (int) ($partCount ** ($ampersandCount - 1 - $ampIndex));
                $partIndex  = (int) ($remaining / $power);
                $remaining -= $partIndex * $power;
                $variant   .= trim($parentParts[$partIndex] ?? '') . $segments[$ampIndex + 1];
            }

            $result[] = $variant;
        }

        return $result;
    }

    private static function countTopLevelAmpersands(string $selector): int
    {
        $length   = strlen($selector);
        $count    = 0;
        $quote    = '';
        $parens   = 0;
        $brackets = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if ($quote !== '') {
                if ($char === '\\') {
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '[') {
                $brackets++;
            } elseif ($char === ']' && $brackets > 0) {
                $brackets--;
            } elseif ($char === '(') {
                $parens++;
            } elseif ($char === ')' && $parens > 0) {
                $parens--;
            } elseif ($char === '&' && $parens === 0 && $brackets === 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<string> $K+1 segments around $K top-level ampersands
     */
    private static function splitByTopLevelAmpersands(string $selector): array
    {
        $segments = [];
        $buffer   = '';
        $length   = strlen($selector);
        $quote    = '';
        $parens   = 0;
        $brackets = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if ($quote !== '') {
                $buffer .= $char;

                if ($char === '\\') {
                    $buffer .= $selector[$i + 1] ?? '';

                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote   = $char;
                $buffer .= $char;

                continue;
            }

            if ($char === '[') {
                $brackets++;
            } elseif ($char === ']' && $brackets > 0) {
                $brackets--;
            } elseif ($char === '(') {
                $parens++;
            } elseif ($char === ')' && $parens > 0) {
                $parens--;
            } elseif ($char === '&' && $parens === 0 && $brackets === 0) {
                $segments[] = $buffer;

                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $segments[] = $buffer;

        return $segments;
    }

    private static function normalizeCombinatorSpacing(string $selector): string
    {
        $length     = strlen($selector);
        $result     = '';
        $quote      = '';
        $parenDepth = 0;
        $brackets   = 0;
        $i          = 0;

        while ($i < $length) {
            $char = $selector[$i];

            if ($quote !== '') {
                $result .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $result .= $selector[$i + 1];

                    $i += 2;

                    continue;
                }

                if ($char === $quote) {
                    $quote = '';
                }

                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                $result .= $char;

                $i++;

                continue;
            }

            if ($char === '\\') {
                $result .= $char;

                if ($i + 1 < $length) {
                    $result .= $selector[$i + 1];

                    $i += 2;

                    continue;
                }

                $i++;

                continue;
            }

            if ($char === '[') {
                $brackets++;
            } elseif ($char === ']') {
                $brackets = max(0, $brackets - 1);
            } elseif ($char === '(') {
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth = max(0, $parenDepth - 1);
            } elseif (
                $brackets === 0
                && $parenDepth === 0
                && ($char === '>' || $char === '+' || $char === '~')
                && rtrim($result) !== ''
            ) {
                $result = rtrim($result) . ' ' . $char . ' ';

                $i++;

                while ($i < $length && self::isWhitespace($selector[$i])) {
                    $i++;
                }

                continue;
            }

            $result .= $char;

            $i++;
        }

        return rtrim($result);
    }

    private static function isWhitespace(string $char): bool
    {
        return $char === ' ' || $char === "\n" || $char === "\r" || $char === "\t";
    }

    private static function allAmpersandsInsideParens(string $selector): bool
    {
        $length     = strlen($selector);
        $parenDepth = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if ($char === '(') {
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth = max(0, $parenDepth - 1);
            } elseif ($char === '&' && $parenDepth === 0) {
                return false;
            }
        }

        return str_contains($selector, '&');
    }
}
