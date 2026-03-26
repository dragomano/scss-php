<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function array_filter;
use function array_map;
use function array_values;
use function explode;

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
}
