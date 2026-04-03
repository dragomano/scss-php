<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\UnitConverter;
use Bugo\SCSS\Values\SassNumber;
use Bugo\SCSS\Values\SassValue;
use Closure;

use function abs;
use function array_key_exists;
use function array_map;
use function count;
use function ctype_alpha;
use function ctype_digit;
use function ctype_xdigit;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function round;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

final readonly class Condition
{
    /**
     * @param Closure(AstNode, Environment): AstNode $evaluateValue
     * @param Closure(AstNode, Environment): string $format
     */
    public function __construct(
        private CompilerContext $ctx,
        private ParserInterface $parser,
        private Text $text,
        private Closure $evaluateValue,
        private Closure $format
    ) {}

    public function evaluate(string $condition, Environment $env): bool
    {
        $parsed = $this->parse($condition);

        return $this->evaluateParsed($parsed, $env);
    }

    public function isTruthy(AstNode $value): bool
    {
        return $this->ctx->valueFactory->fromAst($value)->isTruthy();
    }

    public function compare(AstNode $left, string $operator, AstNode $right, Environment $env): bool
    {
        if ($operator === '==' || $operator === '!=') {
            $equals = $this->areValuesEqual($left, $right, $env);

            return $operator === '==' ? $equals : ! $equals;
        }

        if ($left instanceof NumberNode && $right instanceof NumberNode) {
            return $this->compareNumbers($left, $operator, $right);
        }

        return $this->compareValues(
            $this->toSassValueForFormatting($left, $env)->toCss(),
            $operator,
            $this->toSassValueForFormatting($right, $env)->toCss()
        );
    }

    /**
     * @return array<int, string>
     */
    public function splitTopLevelByOperator(string $condition, string $operator): array
    {
        $cacheKey = $operator . "\0" . $condition;

        if (array_key_exists($cacheKey, $this->ctx->conditionCacheState->split)) {
            return $this->ctx->conditionCacheState->split[$cacheKey];
        }

        $parts = $this->text->splitTopLevelByOperator($condition, $operator);

        $this->ctx->conditionCacheState->split[$cacheKey] = $parts;

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $condition): array
    {
        if (
            array_key_exists($condition, $this->ctx->conditionCacheState->parsed)
            && is_array($this->ctx->conditionCacheState->parsed[$condition])
        ) {
            /** @var array<string, mixed> $cached */
            $cached = $this->ctx->conditionCacheState->parsed[$condition];

            return $cached;
        }

        $normalized = trim($condition);

        if ($normalized !== $condition && array_key_exists($normalized, $this->ctx->conditionCacheState->parsed)) {
            $this->ctx->conditionCacheState->parsed[$condition] = $this->ctx->conditionCacheState->parsed[$normalized];

            if (is_array($this->ctx->conditionCacheState->parsed[$condition])) {
                /** @var array<string, mixed> $cachedNormalized */
                $cachedNormalized = $this->ctx->conditionCacheState->parsed[$condition];

                return $cachedNormalized;
            }
        }

        $condition = $normalized;

        if ($condition === '') {
            $node = ['type' => 'empty'];

            $this->ctx->conditionCacheState->parsed[$condition] = $node;

            return $node;
        }

        if ($this->text->isWrappedBySingleOuterParentheses($condition)) {
            $node = $this->parse(substr($condition, 1, -1));

            $this->ctx->conditionCacheState->parsed[$condition] = $node;

            return $node;
        }

        $hasOr  = str_contains($condition, ' or ');
        $hasAnd = str_contains($condition, ' and ');
        $hasNot = str_starts_with(strtolower($condition), 'not ');

        if (! $hasOr && ! $hasAnd && ! $hasNot) {
            return $this->parseLeaf($condition);
        }

        if ($hasOr) {
            $orParts = $this->splitTopLevelByOperator($condition, 'or');

            if (count($orParts) > 1) {
                $node = [
                    'type'  => 'or',
                    'items' => array_map($this->parse(...), $orParts),
                ];

                $this->ctx->conditionCacheState->parsed[$condition] = $node;

                return $node;
            }
        }

        if ($hasAnd) {
            $andParts = $this->splitTopLevelByOperator($condition, 'and');

            if (count($andParts) > 1) {
                $node = [
                    'type'  => 'and',
                    'items' => array_map($this->parse(...), $andParts),
                ];

                $this->ctx->conditionCacheState->parsed[$condition] = $node;

                return $node;
            }
        }

        if ($hasNot) {
            $node = [
                'type' => 'not',
                'item' => $this->parse(substr($condition, 4)),
            ];

            $this->ctx->conditionCacheState->parsed[$condition] = $node;

            return $node;
        }

        return $this->parseLeaf($condition);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseLeaf(string $condition): array
    {
        $comparison = $this->splitComparison($condition);

        if ($comparison !== null) {
            [$leftRaw, $operator, $rightRaw] = $comparison;

            return $this->cacheAndReturn($condition, [
                'type'     => 'comparison',
                'left'     => $leftRaw,
                'operator' => $operator,
                'right'    => $rightRaw,
            ]);
        }

        return $this->cacheAndReturn($condition, [
            'type' => 'value',
            'raw'  => $condition,
        ]);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function cacheAndReturn(string $condition, array $node): array
    {
        $this->ctx->conditionCacheState->parsed[$condition] = $node;

        return $node;
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function evaluateParsed(array $condition, Environment $env): bool
    {
        $type = 'empty';

        if (isset($condition['type']) && is_string($condition['type'])) {
            $type = $condition['type'];
        }

        if ($type === 'empty') {
            return false;
        }

        if ($type === 'or') {
            $orItems = $this->text->extractStringKeyedArrayItems($condition['items'] ?? null);

            foreach ($orItems as $item) {
                if ($this->evaluateParsed($item, $env)) {
                    return true;
                }
            }

            return false;
        }

        if ($type === 'and') {
            $andItems = $this->text->extractStringKeyedArrayItems($condition['items'] ?? null);

            foreach ($andItems as $item) {
                if (! $this->evaluateParsed($item, $env)) {
                    return false;
                }
            }

            return true;
        }

        if ($type === 'not') {
            $innerCondition = ['type' => 'empty'];

            if (isset($condition['item']) && is_array($condition['item'])) {
                /** @var array<string, mixed> $innerCondition */
                $innerCondition = $condition['item'];
            }

            return ! $this->evaluateParsed($innerCondition, $env);
        }

        if ($type === 'comparison') {
            $leftRaw  = '';
            $rightRaw = '';
            $operator = '==';

            if (isset($condition['left']) && is_string($condition['left'])) {
                $leftRaw = $condition['left'];
            }

            if (isset($condition['right']) && is_string($condition['right'])) {
                $rightRaw = $condition['right'];
            }

            if (isset($condition['operator']) && is_string($condition['operator'])) {
                $operator = $condition['operator'];
            }

            $left  = $this->resolveValue($leftRaw, $env);
            $right = $this->resolveValue($rightRaw, $env);

            return $this->compare($left, $operator, $right, $env);
        }

        $rawValue = '';

        if (isset($condition['raw']) && is_string($condition['raw'])) {
            $rawValue = $condition['raw'];
        }

        $value = $this->resolveValue($rawValue, $env);

        return $this->isTruthy($value);
    }

    private function areValuesEqual(AstNode $left, AstNode $right, Environment $env): bool
    {
        if ($left instanceof NullNode && $right instanceof NullNode) {
            return true;
        }

        if ($left instanceof BooleanNode && $right instanceof BooleanNode) {
            return $left->value === $right->value;
        }

        if ($left instanceof NumberNode && $right instanceof NumberNode) {
            return $this->areNumbersEqual($left, $right);
        }

        if ($left instanceof StringNode && $right instanceof StringNode) {
            return $this->areStringsEqual($left, $right);
        }

        if ($left instanceof ColorNode && $right instanceof ColorNode) {
            return $this->areColorsEqual($left, $right);
        }

        if ($left instanceof ListNode && $right instanceof ListNode) {
            return $this->areListsEqual($left, $right, $env);
        }

        if ($left instanceof MapNode && $right instanceof MapNode) {
            return $this->areMapsEqual($left, $right, $env);
        }

        if ($left instanceof FunctionNode && $right instanceof FunctionNode) {
            return $this->areFunctionsEqual($left, $right);
        }

        return false;
    }

    private function areNumbersEqual(NumberNode $left, NumberNode $right): bool
    {
        if ($left->unit !== $right->unit) {
            if ($left->unit === null || $right->unit === null) {
                return false;
            }

            if (! UnitConverter::compatible($left->unit, $right->unit)) {
                return false;
            }
        }

        $leftValue  = $this->normalizeNumberPrecision((float) $left->value);
        $rightValue = $this->normalizeNumberPrecision((float) $right->value);

        if ($left->unit !== $right->unit) {
            $rightValue = $this->normalizeNumberPrecision(
                UnitConverter::convert($rightValue, $right->unit, $left->unit)
            );
        }

        return abs($leftValue - $rightValue) < 0.0000000001;
    }

    private function areStringsEqual(StringNode $left, StringNode $right): bool
    {
        $leftNormalized  = strtolower(trim($left->value));
        $rightNormalized = strtolower(trim($right->value));

        return $leftNormalized === $rightNormalized;
    }

    private function areColorsEqual(ColorNode $left, ColorNode $right): bool
    {
        return strtolower($left->value) === strtolower($right->value);
    }

    private function areListsEqual(ListNode $left, ListNode $right, Environment $env): bool
    {
        if ($left->separator !== $right->separator) {
            return false;
        }

        if ($left->bracketed !== $right->bracketed) {
            return false;
        }

        if (count($left->items) !== count($right->items)) {
            return false;
        }

        foreach ($left->items as $index => $leftItem) {
            if (! $this->areValuesEqual($leftItem, $right->items[$index], $env)) {
                return false;
            }
        }

        return true;
    }

    private function areMapsEqual(MapNode $left, MapNode $right, Environment $env): bool
    {
        if (count($left->pairs) !== count($right->pairs)) {
            return false;
        }

        foreach ($left->pairs as $index => $leftPair) {
            $rightPair = $right->pairs[$index];

            if (! $this->areValuesEqual($leftPair['key'], $rightPair['key'], $env)) {
                return false;
            }

            if (! $this->areValuesEqual($leftPair['value'], $rightPair['value'], $env)) {
                return false;
            }
        }

        return true;
    }

    private function areFunctionsEqual(FunctionNode $left, FunctionNode $right): bool
    {
        return $left === $right;
    }

    private function compareNumbers(NumberNode $left, string $operator, NumberNode $right): bool
    {
        $leftValue  = $this->normalizeNumberPrecision((float) $left->value);
        $rightValue = $this->normalizeNumberPrecision((float) $right->value);

        if (! UnitConverter::compatible($left->unit, $right->unit)) {
            if (in_array($operator, ['>', '>=', '<', '<='], true)) {
                throw new IncompatibleUnitsException(
                    (string) new SassNumber($left->value, $left->unit),
                    (string) new SassNumber($right->value, $right->unit)
                );
            }

            if ($operator === '!=') {
                return true;
            }

            return false;
        }

        if ($left->unit !== $right->unit) {
            $rightValue = $this->normalizeNumberPrecision(
                UnitConverter::convert($rightValue, $right->unit, $left->unit)
            );
        }

        $equals = abs($leftValue - $rightValue) < 0.0000000001;

        if ($operator === '==') {
            return $equals;
        }

        if ($operator === '!=') {
            return ! $equals;
        }

        return match ($operator) {
            '>='    => $leftValue >= $rightValue,
            '<='    => $leftValue <= $rightValue,
            '>'     => $leftValue > $rightValue,
            '<'     => $leftValue < $rightValue,
            default => false,
        };
    }

    private function normalizeNumberPrecision(float $value): float
    {
        return round($value, 10);
    }

    private function compareValues(mixed $left, string $operator, mixed $right): bool
    {
        $equals = fn(mixed $left, mixed $right): bool => $left === $right;

        return match ($operator) {
            '=='    => $equals($left, $right),
            '!='    => ! $equals($left, $right),
            '>='    => $left >= $right,
            '<='    => $left <= $right,
            '>'     => $left > $right,
            '<'     => $left < $right,
            default => false,
        };
    }

    private function toSassValueForFormatting(AstNode $node, Environment $env): SassValue
    {
        return $this->ctx->valueFactory->fromAst(
            $node,
            fn(AstNode $inner): string => ($this->format)($inner, $env)
        );
    }

    private function resolveLiteralValue(string $value): AstNode
    {
        if (
            array_key_exists($value, $this->ctx->conditionCacheState->literalValue)
            && $this->ctx->conditionCacheState->literalValue[$value] instanceof AstNode
        ) {
            return $this->ctx->conditionCacheState->literalValue[$value];
        }

        if ($value === '') {
            $literal = new StringNode('');

            $this->ctx->conditionCacheState->literalValue[$value] = $literal;

            return $literal;
        }

        if ($value === 'true' || $value === 'false') {
            $literal = $this->ctx->valueFactory->createBooleanNode($value === 'true');

            $this->ctx->conditionCacheState->literalValue[$value] = $literal;

            return $literal;
        }

        if ($value === 'null') {
            $literal = $this->ctx->valueFactory->createNullNode();

            $this->ctx->conditionCacheState->literalValue[$value] = $literal;

            return $literal;
        }

        $numberLiteral = $this->parseNumberLiteral($value);

        if ($numberLiteral !== null) {
            [$numberRaw, $unit] = $numberLiteral;

            $number  = is_numeric($numberRaw) ? (float) $numberRaw : 0.0;
            $literal = new NumberNode($number, $unit !== '' ? $unit : null);

            $this->ctx->conditionCacheState->literalValue[$value] = $literal;

            return $literal;
        }

        if ($this->isHexColorLiteral($value)) {
            $literal = new ColorNode($value);

            $this->ctx->conditionCacheState->literalValue[$value] = $literal;

            return $literal;
        }

        $literal = new StringNode($value);

        $this->ctx->conditionCacheState->literalValue[$value] = $literal;

        return $literal;
    }

    private function resolveValue(string $raw, Environment $env): AstNode
    {
        $value = trim($raw);

        if (str_starts_with($value, '$')) {
            return ($this->evaluateValue)(new VariableReferenceNode(substr($value, 1)), $env);
        }

        if (str_contains($value, '(')) {
            $ast = $this->parser->parse(".__tmp__ { __tmp__: $value; }");

            $firstChild       = $ast->children[0] ?? null;
            $firstDeclaration = $firstChild instanceof RuleNode
                ? $firstChild->children[0] ?? null
                : null;

            if ($firstDeclaration instanceof DeclarationNode) {
                return ($this->evaluateValue)($firstDeclaration->value, $env);
            }
        }

        return $this->resolveLiteralValue($value);
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function splitComparison(string $condition): ?array
    {
        if (array_key_exists($condition, $this->ctx->conditionCacheState->comparison)) {
            return $this->ctx->conditionCacheState->comparison[$condition];
        }

        $length = strlen($condition);

        for ($i = 0; $i < $length; $i++) {
            $char           = $condition[$i];
            $operator       = null;
            $operatorLength = 0;

            if ((in_array($char, ['=', '!', '<', '>'], true)) && $i + 1 < $length && $condition[$i + 1] === '=') {
                $operator       = $char . '=';
                $operatorLength = 2;
            } elseif ($char === '<' || $char === '>') {
                $operator       = $char;
                $operatorLength = 1;
            }

            if ($operator === null) {
                continue;
            }

            $left  = trim(substr($condition, 0, $i));
            $right = trim(substr($condition, $i + $operatorLength));

            if ($left === '' || $right === '') {
                continue;
            }

            $comparison = [$left, $operator, $right];

            $this->ctx->conditionCacheState->comparison[$condition] = $comparison;

            return $comparison;
        }

        $this->ctx->conditionCacheState->comparison[$condition] = null;

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseNumberLiteral(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $length = strlen($value);
        $index  = 0;

        if ($value[$index] === '+' || $value[$index] === '-') {
            $index++;
        }

        if ($index >= $length) {
            return null;
        }

        $hasDigits = false;

        while ($index < $length && ctype_digit($value[$index])) {
            $index++;
            $hasDigits = true;
        }

        if ($index < $length && $value[$index] === '.') {
            $index++;

            while ($index < $length && ctype_digit($value[$index])) {
                $index++;
                $hasDigits = true;
            }
        }

        if (! $hasDigits) {
            return null;
        }

        $numberRaw = substr($value, 0, $index);
        $unit      = substr($value, $index);

        if ($unit !== '') {
            $unitLength = strlen($unit);

            for ($i = 0; $i < $unitLength; $i++) {
                if (! ctype_alpha($unit[$i]) && $unit[$i] !== '%') {
                    return null;
                }
            }
        }

        return [$numberRaw, $unit];
    }

    private function isHexColorLiteral(string $value): bool
    {
        if (! str_starts_with($value, '#')) {
            return false;
        }

        $hex    = substr($value, 1);
        $length = strlen($hex);

        if (! in_array($length, [3, 4, 6, 8], true)) {
            return false;
        }

        return ctype_xdigit($hex);
    }
}
