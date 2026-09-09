<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\UnitConverter;
use Bugo\SCSS\Values\SassCalculation;
use Bugo\SCSS\Values\SassList;
use Bugo\SCSS\Values\SassNumber;
use Bugo\SCSS\Values\SassValue;

use function ceil;
use function count;
use function exp;
use function fdiv;
use function floor;
use function fmod;
use function implode;
use function in_array;
use function is_finite;
use function is_int;
use function is_nan;
use function round;
use function sqrt;
use function strtolower;
use function trim;

use const M_E;
use const M_PI;

final readonly class CalculationEvaluator
{
    public function __construct(
        private AstValueFormatterInterface $valueFormatter,
        private ArithmeticListEvaluatorInterface $arithmeticListEvaluator,
        private AstToSassValueConverterInterface $sassValueConverter,
    ) {}

    /**
     * @param array<int, AstNode> $items
     */
    public function detectUnsupportedOperation(array $items, Environment $env): ?string
    {
        if (count($items) < 3 || count($items) % 2 === 0) {
            return null;
        }

        for ($i = 1; $i < count($items); $i += 2) {
            $operator = $items[$i];

            if (
                ! ($operator instanceof StringNode)
                || ! in_array($operator->value, ['+', '-', '*', '/'], true)
            ) {
                continue;
            }

            $left  = $items[$i - 1];
            $right = $items[$i + 1];

            if (! $this->containsCalculationValue($left) && ! $this->containsCalculationValue($right)) {
                continue;
            }

            if ($operator->value === '/') {
                continue;
            }

            return $this->valueFormatter->format($left, $env)
                . ' '
                . $operator->value
                . ' '
                . $this->valueFormatter->format($right, $env);
        }

        return null;
    }

    /**
     * @param array<int, AstNode> $items
     */
    public function formatListValue(array $items, string $separator, bool $bracketed, Environment $env): string
    {
        $formattedItems = [];

        foreach ($items as $item) {
            if ($item instanceof NullNode) {
                continue;
            }

            $formatted = $this->formatListItem($item, $separator, $env);

            if ($formatted !== '') {
                $formattedItems[] = $formatted;
            }
        }

        return (string) new SassList($formattedItems, $separator, $bracketed);
    }

    public function isSlashChain(ListNode $node): bool
    {
        $items = $node->items;
        $count = count($items);

        if ($node->separator !== 'space' || $count < 3 || $count % 2 !== 1) {
            return false;
        }

        foreach ($items as $index => $item) {
            if ($index % 2 === 0) {
                if (! $item instanceof NumberNode) {
                    return false;
                }
            } elseif (! $item instanceof StringNode || $item->value !== '/') {
                return false;
            }
        }

        return true;
    }

    public function formatSlashChain(ListNode $node, Environment $env): string
    {
        $chunks = [];

        foreach ($node->items as $index => $item) {
            if ($index % 2 === 0) {
                $chunks[] = $this->valueFormatter->format($item, $env);
            }
        }

        return implode('/', $chunks);
    }

    public function formatCalculationFunction(FunctionNode $node, Environment $env): string
    {
        $name = strtolower($node->name);

        if ($name !== 'calc' || count($node->arguments) !== 1) {
            return $this->sassValueConverter->convert($node, $env)->toCss();
        }

        $argument = $node->arguments[0];

        if ($argument instanceof ListNode && ($this->containsGroupingMarker($argument) || $this->hasExplicitParens($argument))) {
            return (string) new SassCalculation($node->name, [
                $this->formatList($argument, $env),
            ]);
        }

        if (
            ! $argument instanceof ListNode
            && ($argument instanceof FunctionNode || $argument instanceof NumberNode)
            && $argument->parenthesized > 0
        ) {
            $inner = $this->valueFormatter->format($argument, $env);

            return (string) new SassCalculation($node->name, [
                str_repeat('(', $argument->parenthesized) . $inner . str_repeat(')', $argument->parenthesized),
            ]);
        }

        if ($argument instanceof ListNode && $argument->parenthesized > 0 && count($argument->items) === 1) {
            $inner = $this->formatList($argument, $env);

            return (string) new SassCalculation($node->name, [
                str_repeat('(', $argument->parenthesized) . $inner . str_repeat(')', $argument->parenthesized),
            ]);
        }

        if (
            ! $argument instanceof ListNode
            && ! $argument instanceof FunctionNode
            && ! $argument instanceof NumberNode
        ) {
            $inner = $this->valueFormatter->format($argument, $env);

            if ($this->needsCalcGroupingParens($inner)) {
                return (string) new SassCalculation($node->name, [
                    '(' . $inner . ')',
                ]);
            }

            return (string) new SassCalculation($node->name, [$inner]);
        }

        if ($argument instanceof ListNode && $this->containsNonFiniteOperand($argument)) {
            return (string) new SassCalculation($node->name, [
                $this->formatList($argument, $env),
            ]);
        }

        return $this->sassValueConverter->convert($node, $env)->toCss();
    }

    public function toSassValue(AstNode $node, Environment $env): SassValue
    {
        return $this->sassValueConverter->convert($node, $env);
    }

    /**
     * @param array<int, AstNode> $arguments
     */
    public function simplifyFunction(string $name, array $arguments, Environment $env): ?AstNode
    {
        $lowerName = strtolower($name);

        if ($lowerName === 'calc') {
            if (count($arguments) !== 1) {
                return null;
            }

            $argument = $arguments[0];

            if ($argument instanceof NumberNode) {
                return $argument;
            }

            if ($argument instanceof FunctionNode && SassCalculation::isCalculationFunctionName($argument->name)) {
                return $argument;
            }

            $constant = $this->resolveConstant($argument);

            if ($constant instanceof NumberNode) {
                return $constant;
            }

            if ($argument instanceof ListNode) {
                $resolved = $this->resolveConstantsInList($argument);
                $resolved = $this->evaluateCalcSubLists($resolved, $env);
                $division = $this->simplifyCalcDivision($resolved);

                if ($division instanceof NumberNode) {
                    return $division;
                }

                $collapsed = $this->arithmeticListEvaluator->evaluate($resolved, true, $env, true);

                if ($collapsed instanceof NumberNode) {
                    return $collapsed;
                }

                if ($collapsed instanceof ListNode && $this->listChanged($resolved, $collapsed)) {
                    return new FunctionNode('calc', [$collapsed]);
                }

                if ($this->listChanged($argument, $resolved) && $this->onlyFiniteConstantsResolved($argument, $resolved)) {
                    return new FunctionNode('calc', [$resolved]);
                }
            }

            return null;
        }

        if ($lowerName === 'round') {
            return $this->simplifyRound($arguments);
        }

        if ($lowerName === 'sign' || $lowerName === 'exp') {
            return count($arguments) === 1
                ? ($lowerName === 'sign'
                    ? $this->simplifySign($arguments[0])
                    : $this->simplifyExp($arguments[0]))
                : null;
        }

        if ($lowerName === 'mod' || $lowerName === 'rem') {
            return count($arguments) === 2
                ? $this->simplifyModuloRemainder($lowerName, $arguments[0], $arguments[1])
                : null;
        }

        if ($lowerName === 'hypot') {
            return count($arguments) >= 2 ? $this->simplifyHypot($arguments) : null;
        }

        if (! in_array($lowerName, ['max', 'min'], true) || count($arguments) < 2) {
            return null;
        }

        foreach ($arguments as $argument) {
            if (! ($argument instanceof NumberNode)) {
                return null;
            }
        }

        /** @var NumberNode[] $arguments */
        $first = $arguments[0];

        $comparisonUnit = null;

        foreach ($arguments as $argument) {
            if ($argument->unit !== null) {
                $comparisonUnit = $argument->unit;

                break;
            }
        }

        $extremeNode  = $first;
        $extremeValue = UnitConverter::convert((float) $first->value, $first->unit, $comparisonUnit);

        for ($i = 1; $i < count($arguments); $i++) {
            $current = $arguments[$i];

            if (! UnitConverter::potentiallyCompatible($comparisonUnit, $current->unit)) {
                return null;
            }

            $currentValue = UnitConverter::convert((float) $current->value, $current->unit, $comparisonUnit);

            if ($lowerName === 'max' && $currentValue > $extremeValue) {
                $extremeValue = $currentValue;
                $extremeNode  = $current;
            }

            if ($lowerName === 'min' && $currentValue < $extremeValue) {
                $extremeValue = $currentValue;
                $extremeNode  = $current;
            }
        }

        return new NumberNode((float) $extremeNode->value, $extremeNode->unit);
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function normalizeArguments(string $name, array $arguments): array
    {
        $lowerName = strtolower($name);

        if (! in_array($lowerName, ['calc', 'min', 'max', 'clamp', 'hypot'], true)) {
            return $arguments;
        }

        $normalized = [];

        foreach ($arguments as $argument) {
            $normalized[] = $this->unwrapNestedNode($argument, $lowerName);
        }

        return $normalized;
    }

    public function unwrapNestedNode(
        AstNode $node,
        ?string $calculationContext = null,
        bool $insideList = false,
    ): AstNode {
        if ($node instanceof FunctionNode) {
            $arguments = [];

            foreach ($node->arguments as $argument) {
                $arguments[] = $this->unwrapNestedNode($argument, $calculationContext);
            }

            $normalized = new FunctionNode(
                name: $node->name,
                arguments: $arguments,
                parenthesized: $node->parenthesized,
            );

            if (strtolower($normalized->name) === 'calc' && count($normalized->arguments) === 1) {
                $inner = $normalized->arguments[0];

                if ($insideList && $calculationContext === 'calc' && $inner instanceof ListNode) {
                    return new ListNode(
                        items: $inner->items,
                        separator: $inner->separator,
                        bracketed: $inner->bracketed,
                        parenthesized: max($inner->parenthesized, 1),
                    );
                }

                if (
                    $insideList && $calculationContext === 'calc'
                    && ($inner instanceof FunctionNode || $inner instanceof NumberNode)
                    && $inner->parenthesized <= 0
                ) {
                    $inner->parenthesized = 1;

                    return $inner;
                }

                return $inner;
            }

            return $normalized;
        }

        if ($node instanceof ListNode) {
            $items = [];

            foreach ($node->items as $item) {
                $items[] = $this->unwrapNestedNode($item, $calculationContext, true);
            }

            return new ListNode($items, $node->separator, $node->bracketed, $node->parenthesized);
        }

        if ($node instanceof NamedArgumentNode) {
            return new NamedArgumentNode($node->name, $this->unwrapNestedNode($node->value, $calculationContext));
        }

        return $node;
    }

    private function containsCalculationValue(AstNode $node): bool
    {
        if ($node instanceof FunctionNode) {
            if (in_array(strtolower($node->name), ['calc', 'min', 'max', 'clamp'], true)) {
                return true;
            }

            foreach ($node->arguments as $argument) {
                if ($this->containsCalculationValue($argument)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof ListNode || $node instanceof ArgumentListNode) {
            foreach ($node->items as $item) {
                if ($this->containsCalculationValue($item)) {
                    return true;
                }
            }
        }

        if ($node instanceof NamedArgumentNode) {
            return $this->containsCalculationValue($node->value);
        }

        return false;
    }

    private function containsGroupingMarker(ListNode $list): bool
    {
        foreach ($list->items as $argument) {
            if (
                $argument instanceof FunctionNode
                && strtolower($argument->name) === 'calc'
                && count($argument->arguments) === 1
                && $argument->arguments[0] instanceof ListNode
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasExplicitParens(ListNode $list): bool
    {
        foreach ($list->items as $item) {
            if ($item instanceof ListNode && $item->parenthesized) {
                return true;
            }

            if (($item instanceof FunctionNode || $item instanceof NumberNode) && $item->parenthesized > 0) {
                return true;
            }
        }

        return false;
    }

    private function needsCalcGroupingParens(string $value): bool
    {
        $len = strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];

            if (
                $ch === ' '
                || $ch === "\t"
                || $ch === "\n"
                || $ch === "\r"
                || $ch === '+'
                || $ch === '-'
                || $ch === '*'
                || $ch === '/'
            ) {
                return true;
            }
        }

        return false;
    }

    private function onlyFiniteConstantsResolved(ListNode $original, ListNode $resolved): bool
    {
        foreach ($resolved->items as $index => $item) {
            if (! $item instanceof NumberNode) {
                continue;
            }

            if (isset($original->items[$index]) && $original->items[$index] instanceof StringNode) {
                if (! is_finite($item->value)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function formatList(ListNode $list, Environment $env): string
    {
        $items = [];

        $operatorContext = $this->extractLeadingOperatorContext($list);

        foreach ($list->items as $index => $item) {
            if (
                $item instanceof FunctionNode
                && strtolower($item->name) === 'calc'
                && count($item->arguments) === 1
                && $item->arguments[0] instanceof ListNode
            ) {
                $items[] = '(' . $this->formatList($item->arguments[0], $env) . ')';

                continue;
            }

            if ($item instanceof ListNode && $item->parenthesized) {
                if ($this->shouldStripParensInOperatorContext($list, $index, $item)) {
                    $items[] = $this->formatListValue($item->items, $item->separator, false, $env);
                } else {
                    $items[] = str_repeat('(', $item->parenthesized)
                        . $this->formatListValue($item->items, $item->separator, false, $env)
                        . str_repeat(')', $item->parenthesized);
                }

                continue;
            }

            if (($item instanceof FunctionNode || $item instanceof NumberNode) && $item->parenthesized > 0) {
                $inner   = $this->formatCalcOperand($item, $env);
                $items[] = str_repeat('(', $item->parenthesized) . $inner . str_repeat(')', $item->parenthesized);

                continue;
            }

            $nonFinite = $this->formatNonFiniteOperand($item);

            if ($nonFinite !== null) {
                $items[] = $nonFinite;

                continue;
            }

            $items[] = $this->formatListItem($item, $list->separator, $env);
        }

        return (string) new SassList($items, $list->separator, $list->bracketed);
    }

    private function formatCalcOperand(AstNode $item, Environment $env): string
    {
        return $this->formatNonFiniteOperand($item) ?? $this->valueFormatter->format($item, $env);
    }

    private function formatNonFiniteOperand(AstNode $item): ?string
    {
        if (! $item instanceof NumberNode || is_int($item->value) || is_finite($item->value)) {
            return null;
        }

        return (new SassNumber($item->value, $item->unit))->toCalcOperandCss();
    }

    private function containsNonFiniteOperand(ListNode $list): bool
    {
        foreach ($list->items as $item) {
            if ($this->formatNonFiniteOperand($item) !== null) {
                return true;
            }
        }

        return false;
    }

    private function extractLeadingOperatorContext(ListNode $list): ?string
    {
        if (count($list->items) < 2) {
            return null;
        }

        $first = $list->items[1];

        return ($first instanceof StringNode) ? $first->value : null;
    }

    private function shouldStripParensInOperatorContext(ListNode $list, int $index, ListNode $inner): bool
    {
        $innerOperator = $this->extractLeadingOperatorContext($inner);
        $outerOperator = $this->extractOperatorAdjacentToIndex($list, $index);

        if ($innerOperator === null) {
            return false;
        }

        if ($outerOperator === '/') {
            return false;
        }

        $innerPrecedence = $this->operatorPrecedence($innerOperator);
        $outerPrecedence = $outerOperator !== null ? $this->operatorPrecedence($outerOperator) : 0;

        if ($innerPrecedence > $outerPrecedence) {
            return true;
        }

        if ($innerPrecedence === $outerPrecedence && $outerOperator !== null && in_array($outerOperator, ['+', '*'], true)) {
            return true;
        }

        return false;
    }

    private function extractOperatorAdjacentToIndex(ListNode $list, int $index): ?string
    {
        $items = $list->items;
        $count = count($items);

        if ($index + 1 < $count) {
            $candidate = $items[$index + 1];

            if ($candidate instanceof StringNode && in_array($candidate->value, ['+', '-', '*', '/'], true)) {
                return $candidate->value;
            }
        }

        if ($index - 1 >= 0) {
            $candidate = $items[$index - 1];

            if ($candidate instanceof StringNode && in_array($candidate->value, ['+', '-', '*', '/'], true)) {
                return $candidate->value;
            }
        }

        return null;
    }

    private function operatorPrecedence(string $operator): int
    {
        return match ($operator) {
            '+', '-' => 1,
            '*', '/' => 2,
            default  => 0,
        };
    }

    private function formatListItem(AstNode $item, string $parentSeparator, Environment $env): string
    {
        if ($item instanceof ListNode && $item->bracketed) {
            if ($item->items === []) {
                return '[]';
            }

            return '[' . $this->formatListValue($item->items, $item->separator, false, $env) . ']';
        }

        if (($item instanceof ListNode && $item->items === [])
            || ($item instanceof MapNode && $item->isEmptyList)
        ) {
            return '';
        }

        if ($item instanceof ListNode && $this->isSlashChain($item)) {
            return $this->formatSlashChain($item, $env);
        }

        if ($parentSeparator === 'space' && $item instanceof ListNode) {
            if ($this->isSlashTriple($item)) {
                return $this->formatSlashTriple($item, $env);
            }

            return $this->formatListValue($item->items, $item->separator, false, $env);
        }

        return $this->valueFormatter->format($item, $env);
    }

    private function isSlashTriple(ListNode $node): bool
    {
        if ($node->separator !== 'space' || count($node->items) !== 3) {
            return false;
        }

        [$first, $mid, $last] = $node->items;

        return $first instanceof NumberNode
            && $mid instanceof StringNode
            && $mid->value === '/'
            && $last instanceof NumberNode;
    }

    private function formatSlashTriple(ListNode $node, Environment $env): string
    {
        return $this->valueFormatter->format($node->items[0], $env)
            . '/'
            . $this->valueFormatter->format($node->items[2], $env);
    }

    /**
     * @param array<int, AstNode> $arguments
     */
    private function simplifyRound(array $arguments): ?AstNode
    {
        if ($arguments === [] || count($arguments) > 3) {
            return null;
        }

        $strategy    = 'nearest';
        $numberIndex = 0;

        if (
            count($arguments) >= 2
            && $arguments[0] instanceof StringNode
            && in_array(strtolower(trim($arguments[0]->value)), ['nearest', 'up', 'down', 'to-zero'], true)
        ) {
            $strategy    = strtolower(trim($arguments[0]->value));
            $numberIndex = 1;
            $stepIndex   = count($arguments) === 3 ? 2 : null;
        } else {
            $stepIndex = count($arguments) === 2 ? 1 : null;
        }

        if (! isset($arguments[$numberIndex]) || ! ($arguments[$numberIndex] instanceof NumberNode)) {
            $resolvedNumber = isset($arguments[$numberIndex])
                ? $this->resolveConstant($arguments[$numberIndex])
                : null;

            if ($resolvedNumber === null) {
                return null;
            }

            $number = $resolvedNumber;
        } else {
            $number = $arguments[$numberIndex];
        }

        $step   = $stepIndex !== null && isset($arguments[$stepIndex]) ? $arguments[$stepIndex] : null;

        if ($step !== null && ! ($step instanceof NumberNode)) {
            $resolvedStep = $this->resolveConstant($step);

            if ($resolvedStep !== null) {
                $step = $resolvedStep;
            } else {
                return new FunctionNode(
                    'round',
                    $numberIndex === 1
                        ? [new StringNode($strategy), $number, $step]
                        : [$number, $step],
                );
            }
        }

        if (! $step instanceof NumberNode) {
            $value = (float) $number->value;
            $unit  = $number->unit;

            if (is_nan($value)) {
                return new NumberNode(fdiv(0.0, 0.0), $unit);
            }

            return new NumberNode((int) round($value), $unit);
        }

        $numberValue = (float) $number->value;
        $stepValue   = (float) $step->value;

        if ($stepValue === 0.0) {
            return new NumberNode(fdiv(0.0, 0.0), $number->unit ?? $step->unit);
        }

        if (is_infinite($numberValue) && is_infinite($stepValue)) {
            return new NumberNode(fdiv(0.0, 0.0));
        }

        if (is_infinite($stepValue)) {
            if ($numberValue === 0.0) {
                return new NumberNode($numberValue, $number->unit ?? $step->unit);
            }

            return match ($strategy) {
                'up'    => $numberValue > 0
                    ? new NumberNode(fdiv(1.0, 0.0), $number->unit)
                    : new NumberNode($numberValue < 0 ? -0.0 : 0.0, $number->unit),
                'down'  => $numberValue < 0
                    ? new NumberNode(fdiv(-1.0, 0.0), $number->unit)
                    : new NumberNode(0.0, $number->unit),
                default => new NumberNode($numberValue < 0 ? -0.0 : 0.0, $number->unit ?? $step->unit),
            };
        }

        if (is_infinite($numberValue)) {
            return new NumberNode($numberValue, $number->unit);
        }

        if (! UnitConverter::compatible($number->unit, $step->unit)) {
            return new FunctionNode(
                'round',
                $numberIndex === 1
                    ? [new StringNode($strategy), $number, $step]
                    : [$number, $step],
            );
        }

        $convertedStep  = UnitConverter::convert($stepValue, $step->unit, $number->unit);
        $scaled         = $numberValue / $convertedStep;
        $isNegativeStep = $convertedStep < 0;

        $rounded = match ($strategy) {
            'up'      => $isNegativeStep ? floor($scaled) : ceil($scaled),
            'down'    => $isNegativeStep ? ceil($scaled) : floor($scaled),
            'to-zero' => $isNegativeStep
                ? ($scaled < 0 ? floor($scaled) : ceil($scaled))
                : ($scaled < 0 ? ceil($scaled) : floor($scaled)),
            default   => round($scaled),
        };

        return new NumberNode($rounded * $convertedStep, $number->unit ?? $step->unit);
    }

    /**
     * @param array<int, AstNode> $arguments
     */
    private function simplifyHypot(array $arguments): ?AstNode
    {
        $resolved = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof NumberNode) {
                $resolved[] = $argument;

                continue;
            }

            $constant = $this->resolveConstant($argument);

            if ($constant !== null) {
                $resolved[] = $constant;

                continue;
            }

            return null;
        }

        /** @var NumberNode[] $resolved */
        $first = $resolved[0];
        $unit  = $first->unit;

        if ($unit === '%') {
            return null;
        }

        foreach ($resolved as $number) {
            if (! UnitConverter::compatible($unit, $number->unit)) {
                return null;
            }
        }

        $sum = 0.0;

        foreach ($resolved as $number) {
            $value = UnitConverter::convert((float) $number->value, $number->unit, $unit);
            $sum  += $value * $value;
        }

        $result = sqrt($sum);

        return new NumberNode($result, $unit);
    }

    private function resolveNumberArgument(AstNode $argument): ?NumberNode
    {
        if ($argument instanceof NumberNode) {
            return $argument;
        }

        return $this->resolveConstant($argument);
    }

    private function simplifySign(AstNode $argument): ?AstNode
    {
        $number = $this->resolveNumberArgument($argument);

        if (! $number instanceof NumberNode) {
            return null;
        }

        $value = (float) $number->value;

        return new NumberNode(match (true) {
            is_nan($value)          => fdiv(0.0, 0.0),
            $value > 0.0            => 1.0,
            $value < 0.0            => -1.0,
            fdiv(1.0, $value) < 0.0 => -0.0,
            default                 => 0.0,
        }, $number->unit);
    }

    private function simplifyExp(AstNode $argument): ?AstNode
    {
        $number = $this->resolveNumberArgument($argument);

        if (! $number instanceof NumberNode) {
            return null;
        }

        return new NumberNode(exp((float) $number->value));
    }

    private function simplifyModuloRemainder(string $name, AstNode $left, AstNode $right): ?AstNode
    {
        $x = $this->resolveNumberArgument($left);
        $y = $this->resolveNumberArgument($right);

        if (! $x instanceof NumberNode || ! $y instanceof NumberNode) {
            return null;
        }

        $xUnit = $x->unit;
        $yUnit = $y->unit;

        if (($xUnit === null) !== ($yUnit === null)) {
            return null;
        }

        if (! UnitConverter::compatible($xUnit, $yUnit)) {
            return null;
        }

        $xValue = (float) $x->value;
        $yValue = UnitConverter::convert((float) $y->value, $yUnit, $xUnit);

        if ($yValue === 0.0) {
            return new NumberNode(fdiv(0.0, 0.0), $xUnit);
        }

        $result = $name === 'mod'
            ? $xValue - $yValue * floor($xValue / $yValue)
            : fmod($xValue, $yValue);

        return new NumberNode($result, $xUnit);
    }

    private function resolveConstant(AstNode $argument): ?NumberNode
    {
        if ($argument instanceof StringNode) {
            return $this->mapConstant($argument->value);
        }

        if (! ($argument instanceof ListNode)) {
            return null;
        }

        if ($argument->separator !== 'space' || count($argument->items) !== 2) {
            return null;
        }

        $firstItem  = $argument->items[0] ?? null;
        $secondItem = $argument->items[1] ?? null;

        if (! ($firstItem instanceof StringNode) || ! ($secondItem instanceof StringNode)) {
            return null;
        }

        if (trim($firstItem->value) === '-') {
            return $this->mapConstant('-' . $secondItem->value);
        }

        return null;
    }

    private function simplifyCalcDivision(ListNode $argument): ?NumberNode
    {
        if (
            $argument->separator !== 'space'
            || $argument->bracketed
            || count($argument->items) !== 3
        ) {
            return null;
        }

        $left     = $argument->items[0] ?? null;
        $operator = $argument->items[1] ?? null;
        $right    = $argument->items[2] ?? null;

        if (
            ! $left instanceof NumberNode
            || ! $right instanceof NumberNode
            || ! $operator instanceof StringNode
            || $operator->value !== '/'
        ) {
            return null;
        }

        $simplified = $this->arithmeticListEvaluator->evaluate(
            new ListNode($argument->items, 'space', true),
            true,
            new Environment(),
        );

        return $simplified instanceof NumberNode ? $simplified : null;
    }

    private function listChanged(ListNode $original, ListNode $updated): bool
    {
        if (count($original->items) !== count($updated->items)) {
            return true;
        }

        foreach ($original->items as $index => $item) {
            if ($item !== ($updated->items[$index] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function resolveConstantsInList(ListNode $list): ListNode
    {
        $items   = [];
        $changed = false;

        foreach ($list->items as $item) {
            $resolved = $this->resolveConstant($item);

            if ($resolved instanceof NumberNode) {
                $items[]  = $resolved;
                $changed  = true;
            } else {
                $items[] = $item;
            }
        }

        return $changed ? new ListNode($items, $list->separator, $list->bracketed) : $list;
    }

    private function evaluateCalcSubLists(ListNode $list, Environment $env): ListNode
    {
        $items   = [];
        $changed = false;

        foreach ($list->items as $item) {
            if ($item instanceof ListNode && $item->separator === 'space') {
                $evaluated = $this->arithmeticListEvaluator->evaluate($item, true, $env, true);

                if ($evaluated instanceof NumberNode) {
                    $items[] = $evaluated;
                    $changed = true;

                    continue;
                }
            }

            $items[] = $item;
        }

        return $changed ? new ListNode($items, $list->separator, $list->bracketed) : $list;
    }

    private function mapConstant(string $value): ?NumberNode
    {
        return match (strtolower(trim($value))) {
            'pi'        => new NumberNode(M_PI),
            'e'         => new NumberNode(M_E),
            'infinity'  => new NumberNode(fdiv(1.0, 0.0)),
            '-infinity' => new NumberNode(fdiv(-1.0, 0.0)),
            'nan'       => new NumberNode(fdiv(0.0, 0.0)),
            default     => null,
        };
    }
}
