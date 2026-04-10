<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function implode;
use function str_contains;
use function str_replace;

final class SelectorHelper
{
    /**
     * @return array<int, string>
     */
    public static function splitList(string $selector, bool $filterEmpty = true): array
    {
        $parts = array_map(trim(...), explode(',', $selector));

        if ($filterEmpty) {
            return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        }

        return $parts;
    }

    public static function resolveNested(string $selector, string $parentSelector): string
    {
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
