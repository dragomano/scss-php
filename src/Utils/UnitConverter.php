<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function array_merge;
use function array_values;
use function implode;
use function strlen;

final class UnitConverter
{
    /** @var array<string, array{group: string, factor: float}> */
    private const CONVERSIONS = [
        'px'   => ['group' => 'length', 'factor' => 1.0],
        'in'   => ['group' => 'length', 'factor' => 96.0],
        'cm'   => ['group' => 'length', 'factor' => 96.0 / 2.54],
        'mm'   => ['group' => 'length', 'factor' => 96.0 / 25.4],
        'q'    => ['group' => 'length', 'factor' => 96.0 / 101.6],
        'pc'   => ['group' => 'length', 'factor' => 16.0],
        'pt'   => ['group' => 'length', 'factor' => 96.0 / 72.0],
        's'    => ['group' => 'time', 'factor' => 1.0],
        'ms'   => ['group' => 'time', 'factor' => 0.001],
        'deg'  => ['group' => 'angle', 'factor' => 1.0],
        'grad' => ['group' => 'angle', 'factor' => 0.9],
        'rad'  => ['group' => 'angle', 'factor' => 57.29577951308232],
        'turn' => ['group' => 'angle', 'factor' => 360.0],
        'Hz'   => ['group' => 'frequency', 'factor' => 1.0],
        'kHz'  => ['group' => 'frequency', 'factor' => 1000.0],
        'dpi'  => ['group' => 'resolution', 'factor' => 1.0],
        'dpcm' => ['group' => 'resolution', 'factor' => 2.54],
        'dppx' => ['group' => 'resolution', 'factor' => 96.0],
    ];

    public static function compatible(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return true;
        }

        if ($left === $right) {
            return true;
        }

        $leftInfo  = self::CONVERSIONS[$left] ?? null;
        $rightInfo = self::CONVERSIONS[$right] ?? null;

        if ($leftInfo === null || $rightInfo === null) {
            return false;
        }

        return $leftInfo['group'] === $rightInfo['group'];
    }

    public static function convert(float $value, ?string $fromUnit, ?string $toUnit): float
    {
        if ($toUnit === null || $fromUnit === null || $fromUnit === $toUnit) {
            return $value;
        }

        $from = self::CONVERSIONS[$fromUnit] ?? null;
        $to   = self::CONVERSIONS[$toUnit] ?? null;

        if ($from === null || $to === null || $from['group'] !== $to['group']) {
            return $value;
        }

        $baseValue = $value * (float) $from['factor'];

        return $baseValue / (float) $to['factor'];
    }

    public static function multiply(?string $left, ?string $right): ?string
    {
        [$leftNumerator, $leftDenominator]   = self::parseParts($left);
        [$rightNumerator, $rightDenominator] = self::parseParts($right);

        $numerator   = array_merge($leftNumerator, $rightNumerator);
        $denominator = array_merge($leftDenominator, $rightDenominator);

        [$numerator, $denominator] = self::cancelParts($numerator, $denominator);

        return self::buildString($numerator, $denominator);
    }

    public static function divide(?string $left, ?string $right): ?string
    {
        [$leftNumerator, $leftDenominator]   = self::parseParts($left);
        [$rightNumerator, $rightDenominator] = self::parseParts($right);

        $numerator   = array_merge($leftNumerator, $rightDenominator);
        $denominator = array_merge($leftDenominator, $rightNumerator);

        [$numerator, $denominator] = self::cancelParts($numerator, $denominator);

        return self::buildString($numerator, $denominator);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    public static function parseParts(?string $unit): array
    {
        if ($unit === null || $unit === '') {
            return [[], []];
        }

        $numerator   = [];
        $denominator = [];
        $token       = '';
        $operator    = '*';
        $length      = strlen($unit);

        for ($i = 0; $i < $length; $i++) {
            $char = $unit[$i];

            if ($char === '*' || $char === '/') {
                if ($token !== '') {
                    if ($operator === '*') {
                        $numerator[] = $token;
                    } else {
                        $denominator[] = $token;
                    }

                    $token = '';
                }

                $operator = $char;

                continue;
            }

            $token .= $char;
        }

        if ($token !== '') {
            if ($operator === '*') {
                $numerator[] = $token;
            } else {
                $denominator[] = $token;
            }
        }

        return [$numerator, $denominator];
    }

    /**
     * @param array<int, string> $numerator
     * @param array<int, string> $denominator
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function cancelParts(array $numerator, array $denominator): array
    {
        $remainingNumerator = [];

        foreach ($numerator as $unit) {
            $cancelled = false;

            foreach ($denominator as $index => $denominatorUnit) {
                if ($denominatorUnit === $unit) {
                    unset($denominator[$index]);
                    $cancelled = true;

                    break;
                }
            }

            if (! $cancelled) {
                $remainingNumerator[] = $unit;
            }
        }

        return [$remainingNumerator, array_values($denominator)];
    }

    /**
     * @param array<int, string> $numerator
     * @param array<int, string> $denominator
     */
    private static function buildString(array $numerator, array $denominator): ?string
    {
        if ($numerator === [] && $denominator === []) {
            return null;
        }

        $unit = '';

        if ($numerator !== []) {
            $unit = implode('*', $numerator);
        }

        foreach ($denominator as $denominatorUnit) {
            $unit .= '/' . $denominatorUnit;
        }

        return $unit;
    }
}
