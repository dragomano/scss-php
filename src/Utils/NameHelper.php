<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function str_contains;
use function strpos;
use function substr;

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
        /**
         * @var array{namespace: string, member: string}
         * @pest-mutate-ignore
        */
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
        // @pest-mutate-ignore
        $dot = strpos($name, '.');

        if ($dot === false) {
            return ['namespace' => $name, 'member' => $defaultMember];
        }

        return [
            'namespace' => substr($name, 0, $dot),
            'member'    => substr($name, $dot + 1),
        ];
    }
}
