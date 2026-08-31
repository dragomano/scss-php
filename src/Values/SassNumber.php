<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\SCSS\Utils\UnitConverter;

use function abs;
use function is_infinite;
use function is_int;
use function is_nan;
use function rtrim;
use function str_contains;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function var_export;

use const PHP_INT_MAX;

final class SassNumber extends AbstractSassValue
{
    public function __construct(
        private readonly int|float $value,
        private readonly ?string $unit = null,
    ) {}

    public function toCss(): string
    {
        if (! is_int($this->value) && (is_nan($this->value) || is_infinite($this->value))) {
            return $this->formatNonFiniteValue();
        }

        $number = $this->formatNumberValue($this->value);

        if (! $this->isCompoundUnit($this->unit)) {
            return $number . $this->formatUnit($this->unit);
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

        if (abs($value) < PHP_INT_MAX) {
            $truncated = (int) $value;

            if ((float) $truncated === $value) {
                return $this->compressLeadingZero((string) $truncated);
            }
        }

        $text = $this->removeExponent(str_replace('E', 'e', var_export($value, true)));

        if (strlen($text) < 12) {
            return $this->compressLeadingZero($text);
        }

        return $this->compressLeadingZero($this->roundDecimalString($text));
    }

    private function removeExponent(string $text): string
    {
        $ePos = strpos($text, 'e');

        if ($ePos === false) {
            return $text;
        }

        $negative = str_starts_with($text, '-');
        $mantissa = $negative ? substr($text, 1, $ePos - 1) : substr($text, 0, $ePos);
        $exponent = (int) substr($text, $ePos + 1);

        $dotPos = (int) strpos($mantissa, '.');
        $digits = substr($mantissa, 0, $dotPos) . substr($mantissa, $dotPos + 1);

        $decimalIndex = $dotPos + $exponent;

        if ($decimalIndex <= 0) {
            return ($negative ? '-' : '') . '0.' . str_repeat('0', -$decimalIndex) . $digits;
        }

        return ($negative ? '-' : '') . $digits . str_repeat('0', $decimalIndex - strlen($digits));
    }

    private function roundDecimalString(string $text): string
    {
        $dot = strpos($text, '.');

        if ($dot === false) {
            return $text;
        }

        $negative = str_starts_with($text, '-');
        $intPart  = $negative ? substr($text, 1, $dot - 1) : substr($text, 0, $dot);
        $fracPart = substr($text, $dot + 1);

        if (strlen($fracPart) <= 10) {
            return $text;
        }

        $significant = substr($fracPart, 0, 10);
        $carry       = ((int) $fracPart[10]) >= 5;

        if ($carry) {
            for ($i = 9; $i >= 0; $i--) {
                if ($significant[$i] !== '9') {
                    $significant[$i] = (string) ((int) $significant[$i] + 1);
                    $carry = false;

                    break;
                }

                $significant[$i] = '0';
            }

            if ($carry) {
                $intPart = (string) ((int) $intPart + 1);
            }
        }

        $significant = rtrim($significant, '0');

        if ($significant === '') {
            return $negative && $intPart === '0' ? '0' : ($negative ? '-' : '') . $intPart;
        }

        return ($negative ? '-' : '') . $intPart . '.' . $significant;
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

    private function formatUnit(?string $unit): string
    {
        return $unit ?? '';
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
        return '1' . ($this->isCompoundUnit($unit) ? $this->formatCompoundUnitSuffix($unit) : $unit);
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
