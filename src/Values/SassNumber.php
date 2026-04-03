<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\SCSS\Utils\UnitConverter;

use function is_infinite;
use function is_int;
use function is_nan;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;

final class SassNumber extends AbstractSassValue
{
    private const ZERO_UNITS = [
        'cm',
        'em',
        'in',
        'mm',
        'pc',
        'pt',
        'px',
        'rem',
        'vmax',
        'vmin',
    ];

    public function __construct(
        private readonly int|float $value,
        private readonly ?string $unit = null,
        private readonly bool $preserveZeroUnit = false,
    ) {}

    public function toCss(): string
    {
        if (! is_int($this->value) && (is_nan($this->value) || is_infinite($this->value))) {
            return $this->formatNonFiniteValue();
        }

        $number = $this->formatNumberValue($this->value);

        if (! $this->isCompoundUnit($this->unit)) {
            return $number . $this->formatUnit($number, $this->unit);
        }

        if ($number === '0' && ! $this->preserveZeroUnit) {
            return '0';
        }

        return $this->formatCompoundUnitAsCalc($number, $this->unit ?? '');
    }

    public function isTruthy(): bool
    {
        return true;
    }

    private function formatNumberValue(int|float $value): string
    {
        if (is_int($value)) {
            return $this->compressLeadingZero((string) $value);
        }

        $formatted = rtrim(sprintf('%.10F', $value), '0');
        $trimmed   = rtrim($formatted, '.');

        if ($trimmed === '-0') {
            return '0';
        }

        return $this->compressLeadingZero($trimmed);
    }

    private function formatNonFiniteValue(): string
    {
        $keyword = is_nan($this->value)
            ? 'NaN'
            : ($this->value < 0 ? '-infinity' : 'infinity');

        if ($this->unit === null || $this->unit === '') {
            return 'calc(' . $keyword . ')';
        }

        return 'calc(' . $keyword . ' * ' . $this->formatUnitFactor($this->unit) . ')';
    }

    private function compressLeadingZero(string $number): string
    {
        if (str_starts_with($number, '0.') && strlen($number) > 2) {
            return substr($number, 1);
        }

        if (str_starts_with($number, '-0.') && strlen($number) > 3) {
            return '-' . substr($number, 2);
        }

        return $number;
    }

    private function formatUnit(string $number, ?string $unit): string
    {
        if ($unit === null || $unit === '' || $number !== '0' || $this->preserveZeroUnit) {
            return $unit ?? '';
        }

        /** @var array<string, true>|null $set */
        static $set = null;

        if ($set === null) {
            $set = [];

            foreach (self::ZERO_UNITS as $zeroUnit) {
                $set[$zeroUnit] = true;
            }
        }

        return isset($set[$unit]) ? '' : $unit;
    }

    private function isCompoundUnit(?string $unit): bool
    {
        return $unit !== null && $unit !== '' && (str_contains($unit, '*') || str_contains($unit, '/'));
    }

    private function formatCompoundUnitAsCalc(string $number, string $unit): string
    {
        return 'calc(' . $number . $this->formatCompoundUnitSuffix($unit) . ')';
    }

    private function formatUnitFactor(string $unit): string
    {
        if (! $this->isCompoundUnit($unit)) {
            return '1' . $unit;
        }

        return '1' . $this->formatCompoundUnitSuffix($unit);
    }

    private function formatCompoundUnitSuffix(string $unit): string
    {
        [$numerator, $denominator] = UnitConverter::parseParts($unit);

        if ($numerator === [] && $denominator === []) {
            return '';
        }

        $expression = '';

        if ($numerator !== []) {
            $expression .= $numerator[0];

            for ($i = 1; $i < count($numerator); $i++) {
                $expression .= ' * 1' . $numerator[$i];
            }
        }

        foreach ($denominator as $denominatorUnit) {
            $expression .= ' / 1' . $denominatorUnit;
        }

        return $expression;
    }
}
