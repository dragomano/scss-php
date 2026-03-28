<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use function count;
use function ctype_alnum;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

final class SassList extends AbstractSassValue
{
    /**
     * @param list<string> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly string $separator = 'space',
        private readonly bool $bracketed = false,
        private readonly bool $shorthand = true
    ) {}

    public function toCss(): string
    {
        $items = $this->filterNullItems($this->items);
        $items = $this->optimizeBoxItems($items);

        $value    = '';
        $first    = true;
        $previous = '';

        foreach ($items as $formatted) {
            if (! $first) {
                $joiner = $this->resolveSeparator();

                if (
                    $this->separator === 'space'
                    && (
                        (
                            str_ends_with($previous, '-')
                            && $previous !== '-'
                            && $formatted !== ''
                            && (str_starts_with($formatted, '#{') || ctype_alnum($formatted[0]))
                        )
                        || (
                            str_ends_with($previous, '}')
                            && str_contains($previous, '#{')
                            && str_starts_with($formatted, '-')
                        )
                    )
                ) {
                    $joiner = '';
                }

                $value .= $joiner;
            }

            $value .= $formatted;

            $first    = false;
            $previous = $formatted;
        }

        if ($this->bracketed) {
            return '[' . $value . ']';
        }

        return $value;
    }

    public function isTruthy(): bool
    {
        return true;
    }

    private function resolveSeparator(): string
    {
        return match ($this->separator) {
            'comma' => ', ',
            'slash' => ' / ',
            default => ' ',
        };
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private function optimizeBoxItems(array $items): array
    {
        $count = count($items);

        if (! $this->shorthand || $this->separator !== 'space' || $this->bracketed || $count < 2 || $count > 4) {
            return $items;
        }

        if ($count === 4) {
            $a = $items[0];
            $b = $items[1];
            $c = $items[2];
            $d = $items[3];

            if ($a === $b && $b === $c && $c === $d) {
                return [$a];
            }

            if ($a === $c && $b === $d) {
                return [$a, $b];
            }

            if ($b === $d) {
                return [$a, $b, $c];
            }

            return $items;
        }

        if ($count === 3) {
            $a = $items[0];
            $b = $items[1];
            $c = $items[2];

            if ($a === $b && $b === $c) {
                return [$a];
            }

            if ($a === $c) {
                return [$a, $b];
            }

            return $items;
        }

        if ($items[0] === $items[1]) {
            return [$items[0]];
        }

        return $items;
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private function filterNullItems(array $items): array
    {
        $filtered = [];

        foreach ($items as $item) {
            if ($item === 'null') {
                continue;
            }

            $filtered[] = $item;
        }

        return $filtered;
    }
}
