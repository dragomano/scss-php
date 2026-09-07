<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function array_filter;
use function array_values;
use function implode;
use function max;
use function str_contains;
use function str_replace;
use function str_split;
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

    private static function computeNested(string $selector, string $parentSelector): string
    {
        if (! str_contains($selector, ',') && ! str_contains($parentSelector, ',')) {
            $selector       = trim($selector);
            $parentSelector = trim($parentSelector);

            if ($selector === '' || $parentSelector === '') {
                return $selector;
            }

            return str_contains($selector, '&')
                ? str_replace('&', $parentSelector, $selector)
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

        foreach ($parentParts as $pi => $parentPart) {
            $parentHasBreak = str_contains($parentPart, "\n");
            $trimmedParent  = ltrim($parentPart);

            if ($trimmedParent === '') {
                continue;
            }

            foreach ($selectorParts as $selectorPart) {
                $trimmedSelector = ltrim($selectorPart);

                if ($trimmedSelector === '') {
                    continue;
                }

                $resolvedPart = str_contains($trimmedSelector, '&')
                    ? str_replace('&', $trimmedParent, $trimmedSelector)
                    : $trimmedParent . ' ' . $trimmedSelector;

                if (in_array($resolvedPart, $resolved, true)) {
                    continue;
                }

                $resolved[] = $resolvedPart;
                $breaks[]   = $parentHasBreak && $pi > 0;
            }
        }

        if ($resolved === []) {
            return $selector;
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
