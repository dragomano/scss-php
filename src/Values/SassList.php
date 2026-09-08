<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

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
    ) {}

    public function toCss(): string
    {
        $items = $this->filterNullItems($this->items);

        if ($items === [] && ! $this->bracketed) {
            return '()';
        }

        $value    = '';
        $first    = true;
        $previous = '';

        foreach ($items as $formatted) {
            if (! $first) {
                $joiner = $this->resolveSeparator();

                if (
                    $this->separator === 'space'
                    && str_ends_with($previous, '}')
                    && str_contains($previous, '#{')
                    && str_starts_with($formatted, '-')
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
