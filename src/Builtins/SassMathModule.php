<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Exceptions\BuiltinArgumentException;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Exceptions\InvalidArgumentTypeException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\SassThrowable;
use Bugo\SCSS\Exceptions\UnknownSassFunctionException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Utils\UnitConverter;

use function abs;
use function acos;
use function array_map;
use function asin;
use function atan;
use function atan2;
use function ceil;
use function cos;
use function count;
use function fdiv;
use function floor;
use function get_debug_type;
use function implode;
use function is_int;
use function log;
use function max;
use function mt_getrandmax;
use function mt_rand;
use function round;
use function sin;
use function sqrt;
use function str_contains;
use function tan;

use const M_E;
use const M_PI;
use const PHP_FLOAT_EPSILON;
use const PHP_FLOAT_MAX;

final class SassMathModule extends AbstractModule
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    private const FUNCTIONS = [
        'abs',
        'acos',
        'asin',
        'atan',
        'atan2',
        'ceil',
        'clamp',
        'compatible',
        'cos',
        'div',
        'floor',
        'hypot',
        'is-unitless',
        'log',
        'max',
        'min',
        'percentage',
        'pow',
        'random',
        'round',
        'sin',
        'sqrt',
        'tan',
        'unit',
    ];

    private const GLOBAL_FUNCTIONS = [
        'abs',
        'acos',
        'asin',
        'ceil',
        'clamp',
        'cos',
        'floor',
        'hypot',
        'log',
        'max',
        'min',
        'percentage',
        'pow',
        'random',
        'round',
        'sin',
        'sqrt',
        'tan',
        'unit',
    ];

    private const GLOBAL_ALIASES = [
        'comparable' => 'compatible',
        'unitless'   => 'is-unitless',
    ];

    public function getName(): string
    {
        return 'math';
    }

    public function getFunctions(): array
    {
        return self::FUNCTIONS;
    }

    public function getGlobalAliases(): array
    {
        return $this->globalAliases(self::GLOBAL_FUNCTIONS, self::GLOBAL_ALIASES);
    }

    public function getVariables(): array
    {
        return [
            'e'                => new NumberNode(M_E),
            'epsilon'          => new NumberNode(PHP_FLOAT_EPSILON),
            'max-number'       => new NumberNode(PHP_FLOAT_MAX),
            'max-safe-integer' => new NumberNode(self::MAX_SAFE_INTEGER),
            'min-number'       => new NumberNode(5e-324),
            'min-safe-integer' => new NumberNode(-self::MAX_SAFE_INTEGER),
            'pi'               => new NumberNode(M_PI),
        ];
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     * @throws SassThrowable
     */
    public function call(string $name, array $positional, array $named, ?BuiltinCallContext $context = null): AstNode
    {
        $previousDisplayName = $this->beginBuiltinCall($name, $context);

        try {
            return match ($name) {
                'abs'         => $this->abs($positional, $context),
                'acos'        => $this->acos($positional),
                'asin'        => $this->asin($positional),
                'atan'        => $this->atan($positional),
                'atan2'       => $this->atan2($positional),
                'ceil'        => $this->ceil($positional, $context),
                'clamp'       => $this->clamp($positional),
                'compatible'  => $this->compatible($positional, $context),
                'cos'         => $this->cos($positional),
                'div'         => $this->div($positional),
                'floor'       => $this->floor($positional, $context),
                'hypot'       => $this->hypot($positional),
                'is-unitless' => $this->isUnitless($positional, $context),
                'log'         => $this->log($positional),
                'max'         => $this->max($positional, $context),
                'min'         => $this->min($positional, $context),
                'percentage'  => $this->percentage($positional, $context),
                'pow'         => $this->pow($positional),
                'random'      => $this->random($positional, $context),
                'round'       => $this->round($positional, $context),
                'sin'         => $this->sin($positional),
                'sqrt'        => $this->sqrt($positional),
                'tan'         => $this->tan($positional),
                'unit'        => $this->unit($positional, $context),
                default       => throw new UnknownSassFunctionException('math', $name),
            };
        } finally {
            $this->endBuiltinCall($previousDisplayName);
        }
    }

    /**
     * @param array<int, AstNode> $positional
     * @throws SassThrowable
     */
    private function abs(array $positional, ?BuiltinCallContext $context): AstNode
    {
        try {
            $number = $this->requireNumber($positional, 0, 'math.abs');
        } catch (SassThrowable $sassThrowable) {
            if ($this->shouldDeferToCss($sassThrowable)) {
                throw new DeferToCssFunctionException($sassThrowable->getMessage(), 0, $sassThrowable);
            }

            throw $sassThrowable;
        }

        $this->warnAboutDeprecatedMathFunction($context, 'abs', $positional);

        return new NumberNode(abs($number->value), $number->unit);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function acos(array $positional): AstNode
    {
        $value = $this->requireUnitlessNumber($positional, 0, 'math.acos');

        return new NumberNode(rad2deg(acos($value)), 'deg');
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function asin(array $positional): AstNode
    {
        $value = $this->requireUnitlessNumber($positional, 0, 'math.asin');

        return new NumberNode(rad2deg(asin($value)), 'deg');
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function atan(array $positional): AstNode
    {
        $value = $this->requireUnitlessNumber($positional, 0, 'math.atan');

        return new NumberNode(rad2deg(atan($value)), 'deg');
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function atan2(array $positional): AstNode
    {
        $a = $this->requireNumber($positional, 0, 'math.atan2');
        $b = $this->requireNumber($positional, 1, 'math.atan2');

        if (! $this->unitsCompatible($a->unit, $b->unit)) {
            throw IncompatibleUnitsException::functionArguments($this->builtinCallReference('math.atan2'));
        }

        return new NumberNode(rad2deg(atan2((float) $a->value, (float) $b->value)), 'deg');
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function ceil(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $number = $this->requireNumber($positional, 0, 'math.ceil');

        $this->warnAboutDeprecatedMathFunction($context, 'ceil', $positional);

        return new NumberNode((int) ceil((float) $number->value), $number->unit);
    }

    /**
     * @param array<int, AstNode> $positional
     * @throws SassThrowable
     */
    private function clamp(array $positional): AstNode
    {
        try {
            $minValue = $this->requireNumber($positional, 0, 'math.clamp');
            $number   = $this->requireNumber($positional, 1, 'math.clamp');
            $maxValue = $this->requireNumber($positional, 2, 'math.clamp');

            $this->assertCompatibleUnits($minValue, $number);
            $this->assertCompatibleUnits($number, $maxValue);

            $comparisonUnit  = $minValue->unit ?? $number->unit ?? $maxValue->unit;
            $minComparable   = $this->convertNumberValue($minValue, $comparisonUnit);
            $valueComparable = $this->convertNumberValue($number, $comparisonUnit);
            $maxComparable   = $this->convertNumberValue($maxValue, $comparisonUnit);

            if ($this->compareNumbers($valueComparable, $minComparable) <= 0) {
                return new NumberNode($minValue->value, $minValue->unit);
            }

            if ($this->compareNumbers($valueComparable, $maxComparable) >= 0) {
                return new NumberNode($maxValue->value, $maxValue->unit);
            }

            return new NumberNode($number->value, $number->unit);
        } catch (SassThrowable $sassThrowable) {
            if ($this->shouldDeferToCss($sassThrowable)) {
                throw new DeferToCssFunctionException($sassThrowable->getMessage(), 0, $sassThrowable);
            }

            throw $sassThrowable;
        }
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function compatible(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $a = $this->requireNumber($positional, 0, 'math.compatible');
        $b = $this->requireNumber($positional, 1, 'math.compatible');

        $this->warnAboutDeprecatedMathFunction($context, 'compatible', $positional);

        return $this->boolNode($this->unitsCompatible($a->unit, $b->unit));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function cos(array $positional): AstNode
    {
        $number  = $this->requireNumber($positional, 0, 'math.cos');
        $radians = $this->toRadians($number);

        return new NumberNode(cos($radians));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function div(array $positional): AstNode
    {
        $a = $this->requireNumber($positional, 0, 'math.div');
        $b = $this->requireNumber($positional, 1, 'math.div');

        $aFloat = (float) $a->value;
        $bFloat = (float) $b->value;
        $unit   = UnitConverter::divide($a->unit, $b->unit);

        if ($bFloat === 0.0) {
            if ($aFloat === 0.0) {
                return new NumberNode(fdiv(0.0, 0.0), $unit);
            }

            return new NumberNode(fdiv($aFloat > 0.0 ? 1.0 : -1.0, 0.0), $unit);
        }

        return new NumberNode($aFloat / $bFloat, $unit);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function floor(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $number = $this->requireNumber($positional, 0, 'math.floor');

        $this->warnAboutDeprecatedMathFunction($context, 'floor', $positional);

        return new NumberNode((int) floor((float) $number->value), $number->unit);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function hypot(array $positional): AstNode
    {
        if ($positional === []) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('math.hypot'),
                1,
                true,
            );
        }

        $numbers = array_map(fn(AstNode $value): NumberNode
            => $this->ensureNumber($value, 'math.hypot'), $positional);

        $unit = $numbers[0]->unit;

        foreach ($numbers as $number) {
            if (! $this->unitsCompatible($unit, $number->unit)) {
                throw IncompatibleUnitsException::functionArguments($this->builtinCallReference('math.hypot'));
            }
        }

        $sum = 0.0;
        foreach ($numbers as $number) {
            $value = (float) $number->value;

            $sum += $value * $value;
        }

        return new NumberNode(sqrt($sum), $unit);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function isUnitless(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $number = $this->requireNumber($positional, 0, 'math.is-unitless');

        $this->warnAboutDeprecatedMathFunction($context, 'is-unitless', $positional);

        return $this->boolNode($number->unit === null);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function log(array $positional): AstNode
    {
        $number = $this->requireUnitlessNumber($positional, 0, 'math.log');

        if (isset($positional[1])) {
            $base = $this->ensureUnitlessNumber($positional[1], 'math.log');

            if ($base === 0.0) {
                return new NumberNode(fdiv(0.0, 0.0));
            }

            if ($base === 1.0) {
                return new NumberNode(fdiv(0.0, 0.0));
            }

            return new NumberNode($base < 0.0 ? fdiv(0.0, 0.0) : log($number) / log($base));
        }

        return new NumberNode(log($number));
    }

    /**
     * @param array<int, AstNode> $positional
     * @throws SassThrowable
     */
    private function max(array $positional, ?BuiltinCallContext $context): AstNode
    {
        return $this->extrema($positional, true, $context);
    }

    /**
     * @param array<int, AstNode> $positional
     * @throws SassThrowable
     */
    private function min(array $positional, ?BuiltinCallContext $context): AstNode
    {
        return $this->extrema($positional, false, $context);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function percentage(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $number = $this->requireNumber($positional, 0, 'math.percentage');

        if ($number->unit !== null) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('math.percentage'),
                'a unitless number',
            );
        }

        $this->warnAboutDeprecatedMathFunction($context, 'percentage', $positional);

        return new NumberNode(((float) $number->value) * 100.0, '%');
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function pow(array $positional): AstNode
    {
        $base     = $this->requireUnitlessNumber($positional, 0, 'math.pow');
        $exponent = $this->requireUnitlessNumber($positional, 1, 'math.pow');

        return new NumberNode($base ** $exponent);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function random(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (! isset($positional[0])) {
            $this->warnAboutDeprecatedMathFunction($context, 'random', $positional);

            return new NumberNode(mt_rand() / mt_getrandmax());
        }

        $limit = $this->requireNumber($positional, 0, 'math.random');

        if (! is_int($limit->value) || $limit->value < 1) {
            throw BuiltinArgumentException::mustBePositiveInteger(
                $this->builtinCallReference('math.random'),
                'limit',
            );
        }

        $this->warnAboutDeprecatedMathFunction($context, 'random', $positional);

        return new NumberNode(mt_rand(1, $limit->value), $limit->unit);
    }

    /**
     * @param array<int, AstNode> $positional
     * @throws SassThrowable
     */
    private function round(array $positional, ?BuiltinCallContext $context): AstNode
    {
        try {
            $number = $this->requireNumber($positional, 0, 'math.round');
        } catch (SassThrowable $sassThrowable) {
            if ($this->shouldDeferToCss($sassThrowable)) {
                throw new DeferToCssFunctionException($sassThrowable->getMessage(), 0, $sassThrowable);
            }

            throw $sassThrowable;
        }

        $this->warnAboutDeprecatedMathFunction($context, 'round', $positional);

        return new NumberNode((int) round((float) $number->value), $number->unit);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function sin(array $positional): AstNode
    {
        $number  = $this->requireNumber($positional, 0, 'math.sin');
        $radians = $this->toRadians($number);

        return new NumberNode(sin($radians));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function sqrt(array $positional): AstNode
    {
        $number = $this->requireUnitlessNumber($positional, 0, 'math.sqrt');

        return new NumberNode(sqrt($number));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function tan(array $positional): AstNode
    {
        $number  = $this->requireNumber($positional, 0, 'math.tan');
        $radians = $this->toRadians($number);

        return new NumberNode(tan($radians));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function unit(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $number = $this->requireNumber($positional, 0, 'math.unit');

        $this->warnAboutDeprecatedMathFunction($context, 'unit', $positional);

        return new StringNode($number->unit ?? '');
    }

    /**
     * @param array<int, AstNode> $positional
     * @throws SassThrowable
     */
    private function extrema(array $positional, bool $wantMax, ?BuiltinCallContext $context): AstNode
    {
        try {
            if (count($positional) < 1) {
                throw new MissingFunctionArgumentsException(
                    $this->builtinErrorContext($wantMax ? 'math.max' : 'math.min'),
                    'at least one number',
                );
            }

            $numbers = array_map(fn(AstNode $value): NumberNode
                => $this->ensureNumber($value, $wantMax ? 'math.max' : 'math.min'), $positional);

            $unit = $numbers[0]->unit;

            foreach ($numbers as $number) {
                if (! $this->unitsCompatible($unit, $number->unit)) {
                    throw IncompatibleUnitsException::functionArguments(
                        $this->builtinCallReference($wantMax ? 'math.max' : 'math.min'),
                    );
                }
            }

            $result = $numbers[0];
            foreach ($numbers as $number) {
                if ($wantMax && (float) $number->value > (float) $result->value) {
                    $result = $number;
                }

                if (! $wantMax && (float) $number->value < (float) $result->value) {
                    $result = $number;
                }
            }

            $this->warnAboutDeprecatedMathFunction($context, $wantMax ? 'max' : 'min', $positional);

            return new NumberNode($result->value, $unit);
        } catch (SassThrowable $sassThrowable) {
            if ($this->shouldDeferToCss($sassThrowable)) {
                throw new DeferToCssFunctionException($sassThrowable->getMessage(), 0, $sassThrowable);
            }

            throw $sassThrowable;
        }
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function requireNumber(array $positional, int $index, string $context): NumberNode
    {
        if (! isset($positional[$index])) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext($context),
                'required number argument',
            );
        }

        return $this->ensureNumber($positional[$index], $context);
    }

    private function ensureNumber(AstNode $value, string $context): NumberNode
    {
        if (! ($value instanceof NumberNode)) {
            throw new InvalidArgumentTypeException(
                $this->builtinErrorContext($context),
                'number',
                get_debug_type($value),
            );
        }

        return $value;
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function requireUnitlessNumber(array $positional, int $index, string $context): float
    {
        if (! isset($positional[$index])) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext($context),
                'required number argument',
            );
        }

        return $this->ensureUnitlessNumber($positional[$index], $context);
    }

    private function ensureUnitlessNumber(AstNode $value, string $context): float
    {
        $number = $this->ensureNumber($value, $context);

        if ($number->unit !== null) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext($context),
                'a unitless number',
            );
        }

        return (float) $number->value;
    }

    private function unitsCompatible(?string $a, ?string $b): bool
    {
        return UnitConverter::compatible($a, $b);
    }

    private function assertCompatibleUnits(NumberNode $a, NumberNode $b): void
    {
        if (! $this->unitsCompatible($a->unit, $b->unit)) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('math.clamp'),
                'compatible units',
            );
        }
    }

    private function convertNumberValue(NumberNode $number, ?string $unit): float
    {
        return UnitConverter::convert((float) $number->value, $number->unit, $unit);
    }

    private function compareNumbers(float $left, float $right): int
    {
        $delta     = $left - $right;
        $tolerance = PHP_FLOAT_EPSILON * max(1.0, abs($left), abs($right)) * 16.0;

        if (abs($delta) <= $tolerance) {
            return 0;
        }

        return $delta < 0.0 ? -1 : 1;
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function warnAboutDeprecatedMathFunction(
        ?BuiltinCallContext $context,
        string $name,
        array $positional,
    ): void {
        if (! $this->isGlobalBuiltinCall() || in_array($name, ['abs', 'clamp'], true)) {
            return;
        }

        $this->warnAboutDeprecatedBuiltinFunctionWithSingleSuggestion(
            $context,
            $this->deprecatedMathSuggestion($name, $positional),
            'math.' . $name,
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function deprecatedMathSuggestion(string $name, array $positional): string
    {
        $arguments = $this->hasRawArguments() ? $this->rawPositionalArguments() : $positional;

        return 'math.' . $name . '(' . implode(', ', $this->describeArguments($arguments)) . ')';
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, string>
     */
    private function describeArguments(array $arguments): array
    {
        return array_map($this->describeValue(...), $arguments);
    }

    private function describeValue(AstNode $value): string
    {
        if ($value instanceof SpreadArgumentNode) {
            return $this->describeValue($value->value) . '...';
        }

        if ($value instanceof VariableReferenceNode) {
            return '$' . $value->name;
        }

        if (
            $value instanceof StringNode
            || $value instanceof NumberNode
            || $value instanceof BooleanNode
        ) {
            return (string) $value;
        }

        return '';
    }

    private function toRadians(NumberNode $number): float
    {
        if ($number->unit === null || $number->unit === 'rad') {
            return (float) $number->value;
        }

        if ($number->unit === 'deg') {
            return deg2rad((float) $number->value);
        }

        if ($number->unit === 'turn') {
            return ((float) $number->value) * 2.0 * M_PI;
        }

        if ($number->unit === 'grad') {
            return ((float) $number->value) * M_PI / 200.0;
        }

        throw BuiltinArgumentException::unsupportedUnit(
            $this->builtinCallReference('math'),
            $number->unit,
            'unitless, deg, rad, grad, or turn',
        );
    }

    private function shouldDeferToCss(SassThrowable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'expects number arguments')
            || str_contains($message, 'expects number, got')
            || str_contains($message, 'expects compatible units')
            || str_contains($message, 'arguments must have compatible units');
    }
}
