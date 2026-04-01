<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function str_replace;

final class NameNormalizer
{
    public static function normalize(string $name): string
    {
        /** @var array<string, string> $cache */
        static $cache = [];

        return $cache[$name] ??= str_replace('_', '-', $name);
    }

    public static function isPrivate(string $name): bool
    {
        $normalized = self::normalize($name);

        return $normalized !== '' && $normalized[0] === '-';
    }
}
