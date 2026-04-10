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
        return self::split($name, null);
    }

    /**
     * @return array{namespace: string, member: string}
     */
    public static function splitNamespacedName(string $name): array
    {
        /** @var array{namespace: string, member: string} */
        return self::split($name, '');
    }

    public static function hasNamespace(string $name): bool
    {
        return str_contains($name, '.');
    }

    /**
     * @return array{namespace: string, member: string|null}
     */
    private static function split(string $name, ?string $defaultMember): array
    {
        if (! str_contains($name, '.')) {
            return ['namespace' => $name, 'member' => $defaultMember];
        }

        $parts = explode('.', $name, 2);

        return [
            'namespace' => $parts[0],
            'member'    => $parts[1] ?? $defaultMember,
        ];
    }
}
