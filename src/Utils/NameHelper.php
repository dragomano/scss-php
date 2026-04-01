<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function explode;
use function str_contains;

final class NameHelper
{
    /**
     * @return array{namespace: string, member: string|null}
     */
    public static function splitQualifiedName(string $name): array
    {
        if (! str_contains($name, '.')) {
            return ['namespace' => $name, 'member' => null];
        }

        $parts = explode('.', $name, 2);

        return [
            'namespace' => $parts[0],
            'member'    => $parts[1] ?? null,
        ];
    }

    /**
     * @return array{namespace: string, member: string}
     */
    public static function splitNamespacedName(string $name): array
    {
        $parts = explode('.', $name, 2);

        return [
            'namespace' => $parts[0],
            'member'    => $parts[1] ?? '',
        ];
    }

    public static function hasNamespace(string $name): bool
    {
        return str_contains($name, '.');
    }
}
