<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use function implode;

final class SassMap extends AbstractSassValue
{
    /**
     * @param array<int, array{key: SassValue, value: SassValue}> $pairs
     */
    public function __construct(private readonly array $pairs) {}

    public function toCss(): string
    {
        $parts = [];

        foreach ($this->pairs as $pair) {
            $parts[] = $pair['key']->toCss() . ': ' . $pair['value']->toCss();
        }

        return '(' . implode(', ', $parts) . ')';
    }

    public function isTruthy(): bool
    {
        return true;
    }
}
