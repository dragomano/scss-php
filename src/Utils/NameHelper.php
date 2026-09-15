<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function in_array;
use function str_contains;
use function str_starts_with;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function substr_count;

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

    public static function isSpecialCssFunctionName(string $name): bool
    {
        $name = strtolower($name);

        if (str_starts_with($name, '-') && substr_count($name, '-') >= 2) {
            $tail = substr($name, (int) strrpos($name, '-') + 1);

            return in_array($tail, ['calc', 'element', 'expression'], true);
        }

        return in_array($name, ['element', 'expression', 'type'], true);
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
