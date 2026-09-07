<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use function array_flip;
use function array_slice;
use function count;
use function implode;
use function in_array;
use function is_string;
use function str_replace;
use function strtolower;

final class SassCalculation extends AbstractSassValue
{
    /** @var array<int, string> */
    public const SUPPORTED_FUNCTIONS = [
        'abs',
        'acos',
        'asin',
        'atan',
        'atan2',
        'clamp',
        'cos',
        'exp',
        'hypot',
        'log',
        'max',
        'min',
        'mod',
        'pow',
        'rem',
        'round',
        'sign',
        'sin',
        'sqrt',
        'tan',
        'calc',
        'calc-size',
    ];

    /** @var array<int, string> */
    private const MODERN_COLOR_FUNCTIONS = [
        'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color',
    ];

    /**
     * @param array<int, SassValue|string> $arguments
     */
    public function __construct(
        private readonly string $name,
        private readonly array $arguments = [],
    ) {}

    public static function isCalculationFunctionName(string $name): bool
    {
        /** @var array<string, int>|null $set */
        static $set = null;

        $set ??= array_flip(self::SUPPORTED_FUNCTIONS);

        return isset($set[strtolower($name)]);
    }

    public function toCss(): string
    {
        $parts = [];

        foreach ($this->arguments as $argument) {
            if (is_string($argument)) {
                $parts[] = $argument;

                continue;
            }

            $parts[] = $argument instanceof SassNull ? '' : $argument->toCss();
        }

        if (in_array(strtolower($this->name), self::MODERN_COLOR_FUNCTIONS, true)) {
            return $this->name . '(' . $this->formatSpaceSeparated($parts) . ')';
        }

        $joined = implode(', ', $parts);

        if (strtolower($this->name) === 'calc') {
            $joined = str_replace(' + -', ' - ', $joined);
            $joined = str_replace(' - -', ' + ', $joined);
        }

        return $this->name . '(' . $joined . ')';
    }

    public function isTruthy(): bool
    {
        return true;
    }

    /**
     * @param array<int, string> $parts
     */
    private function formatSpaceSeparated(array $parts): string
    {
        $channels = strtolower($this->name) === 'color' ? 4 : 3;

        if (count($parts) > $channels) {
            return implode(' ', array_slice($parts, 0, -1)) . ' / ' . $parts[count($parts) - 1];
        }

        return implode(' ', $parts);
    }
}
