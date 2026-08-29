<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\UnitConverter;
use Bugo\SCSS\Values\SassCalculation;
use Bugo\SCSS\Values\SassList;
use Bugo\SCSS\Values\SassValue;

use function ceil;
use function count;
use function exp;
use function fdiv;
use function floor;
use function fmod;
use function in_array;
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

    public function formatCalculationFunction(FunctionNode $node, Environment $env): string
    {
        $name = strtolower($node->name);

        if (
            $name !== 'calc'
            || count($node->arguments) !== 1
            || ! ($node->arguments[0] instanceof ListNode)
            || ! $this->containsGroupingMarker($node->arguments[0])
        ) {
            return $this->sassValueConverter->convert($node, $env)->toCss();
        }

        return (string) new SassCalculation($node->name, [
            $this->formatList($node->arguments[0], $env),
        ]);
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
        $first        = $arguments[0];
        $unit         = $first->unit;
        $extremeValue = (float) $first->value;

        for ($i = 1; $i < count($arguments); $i++) {
            $current = $arguments[$i];

            if (! UnitConverter::compatible($unit, $current->unit)) {
                return null;
            }

            $currentValue = (float) $current->value;

            if ($lowerName === 'max' && $currentValue > $extremeValue) {
                $extremeValue = $currentValue;
                $unit         = $current->unit ?? $unit;
            }

            if ($lowerName === 'min' && $currentValue < $extremeValue) {
                $extremeValue = $currentValue;
                $unit         = $current->unit ?? $unit;
            }
        }

        return new NumberNode($extremeValue, $unit);
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

            $normalized = new FunctionNode($node->name, $arguments);

            if (strtolower($normalized->name) === 'calc' && count($normalized->arguments) === 1) {
                $inner = $normalized->arguments[0];

                if ($insideList && $calculationContext === 'calc' && $inner instanceof ListNode) {
                    return $normalized;
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

            return new ListNode($items, $node->separator, $node->bracketed);
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

    private function formatList(ListNode $list, Environment $env): string
    {
        $items = [];

        foreach ($list->items as $item) {
            if (
                $item instanceof FunctionNode
                && strtolower($item->name) === 'calc'
                && count($item->arguments) === 1
                && $item->arguments[0] instanceof ListNode
            ) {
                $items[] = '(' . $this->formatList($item->arguments[0], $env) . ')';

                continue;
            }

            $items[] = $this->formatListItem($item, $list->separator, $env);
        }

        return (string) new SassList($items, $list->separator, $list->bracketed);
    }

    private function formatListItem(AstNode $item, string $parentSeparator, Environment $env): string
    {
        if ($item instanceof ListNode && $item->bracketed) {
            return '[' . $this->formatListValue($item->items, $item->separator, false, $env) . ']';
        }

        if ($parentSeparator === 'space' && $item instanceof ListNode && ! $item->bracketed) {
            if ($this->isSlashTriple($item)) {
                return $this->formatSlashTriple($item, $env);
            }

            return '(' . $this->formatListValue($item->items, $item->separator, false, $env) . ')';
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
            return null;
        }

        $number = $arguments[$numberIndex];
        $step   = $stepIndex !== null && isset($arguments[$stepIndex]) ? $arguments[$stepIndex] : null;

        if ($step !== null && ! ($step instanceof NumberNode)) {
            return new FunctionNode(
                'round',
                $numberIndex === 1
                    ? [new StringNode($strategy), $number, $step]
                    : [$number, $step],
            );
        }

        if (! $step instanceof NumberNode) {
            return new NumberNode((int) round((float) $number->value), $number->unit);
        }

        if ((float) $step->value === 0.0) {
            return null;
        }

        if (! UnitConverter::compatible($number->unit, $step->unit)) {
            return null;
        }

        $stepValue = UnitConverter::convert((float) $step->value, $step->unit, $number->unit);
        $scaled    = (float) $number->value / $stepValue;

        $rounded = match ($strategy) {
            'up'      => ceil($scaled),
            'down'    => floor($scaled),
            'to-zero' => $scaled < 0 ? ceil($scaled) : floor($scaled),
            default   => round($scaled),
        };

        return new NumberNode($rounded * $stepValue, $number->unit ?? $step->unit);
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
        });
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
