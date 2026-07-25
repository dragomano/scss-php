<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function array_filter;
use function array_unique;
use function array_values;
use function implode;
use function max;
use function str_contains;
use function str_replace;
use function str_split;
use function substr;
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

        $selectorParts = self::splitList($selector);
        $parentParts   = self::splitList($parentSelector);

        if ($selectorParts === [] || $parentParts === []) {
            return $selector;
        }

        $resolved = [];

        foreach ($parentParts as $parentPart) {
            foreach ($selectorParts as $selectorPart) {
                if (str_contains($selectorPart, '&')) {
                    $resolved[] = str_replace('&', $parentPart, $selectorPart);
                } else {
                    $resolved[] = $selectorPart;
                }
            }
        }

        return implode(', ', array_values(array_unique($resolved)));
    }
}
