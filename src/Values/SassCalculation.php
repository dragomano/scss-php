<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use function implode;
use function in_array;
use function is_string;
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
    ];

    /**
     * @param array<int, SassValue|string> $arguments
     */
    public function __construct(
        private readonly string $name,
        private readonly array $arguments = []
    ) {}

    public static function isCalculationFunctionName(string $name): bool
    {
        return in_array(strtolower($name), self::SUPPORTED_FUNCTIONS, true);
    }

    public function toCss(): string
    {
        $parts = [];

        foreach ($this->arguments as $argument) {
            if (is_string($argument)) {
                $parts[] = $argument;

                continue;
            }

            $parts[] = $argument->toCss();
        }

        return $this->name . '(' . implode(', ', $parts) . ')';
    }

    public function isTruthy(): bool
    {
        return true;
    }
}
