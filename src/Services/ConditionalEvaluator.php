<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\StringHelper;
use Bugo\SCSS\Values\ValueFactory;

use function array_key_exists;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function in_array;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function trim;

final readonly class ConditionalEvaluator
{
    public function __construct(
        private Condition $condition,
        private Text $text,
        private AstValueEvaluatorInterface $valueEvaluator,
        private AstValueFormatterInterface $valueFormatter,
        private ComparisonListEvaluatorInterface $comparisonListEvaluator,
        private ValueFactory $valueFactory,
    ) {}

    /**
     * @param array<int, AstNode> $arguments
     */
    public function evaluateInlineIfFunction(string $name, array $arguments, Environment $env): ?AstNode
    {
        if (strtolower($name) !== 'if' || $arguments === []) {
            return null;
        }

        $condition = $arguments[0];
        $truthy    = $arguments[1] ?? $this->valueFactory->createNullNode();
        $hasElse   = array_key_exists(2, $arguments);
        $falsy     = $arguments[2] ?? $this->valueFactory->createNullNode();
        $result    = $this->evaluateInlineIfCondition($condition, $env);

        if ($result['kind'] === 'bool') {
            /** @var array{kind: 'bool', value: bool} $result */
            $value = $result['value'];

            if ($value) {
                return $this->valueEvaluator->evaluate($truthy, $env);
            }

            return $this->valueEvaluator->evaluate($falsy, $env);
        }

        $truthyText = $this->valueFormatter->format(
            $this->valueEvaluator->evaluate($truthy, $env),
            $env,
        );

        /** @var array{kind: 'css', expression: string} $result */
        $expression = 'if(' . $result['expression'] . ': ' . $truthyText;

        if ($hasElse) {
            $falsyText   = $this->valueFormatter->format(
                $this->valueEvaluator->evaluate($falsy, $env),
                $env,
            );
            $expression .= '; else: ' . $falsyText;
        }

        $expression .= ')';

        return new StringNode($expression);
    }

    /**
     * @param array<int, AstNode> $arguments
     */
    public function evaluateSpecialUrlFunction(string $name, array $arguments, Environment $env): ?AstNode
    {
        if (strtolower($name) !== 'url' || count($arguments) !== 1) {
            return null;
        }

        $argument = $arguments[0];

        if ($argument instanceof StringNode) {
            $value = $this->text->replaceInterpolations($argument->value, $env);

            if (! $argument->quoted) {
                $value = $this->text->replaceVariableReferencesInText($value, $env);
            }

            return new FunctionNode('url', [new StringNode($value, $argument->quoted)]);
        }

        $value = $this->valueFormatter->format($argument, $env);
        $value = $this->text->replaceInterpolations($value, $env);
        $value = $this->text->replaceVariableReferencesInText($value, $env);
        $value = $this->collapseUrlStringConcatenation($value);

        if (StringHelper::isQuoted($value)) {
            return new FunctionNode('url', [new StringNode(StringHelper::unquote($value), true)]);
        }

        return new FunctionNode('url', [new StringNode($value)]);
    }

    public function evaluateLogicalList(ListNode $list, Environment $env): ?AstNode
    {
        if ($list->separator !== 'space') {
            return null;
        }

        return $this->evaluateLogicalItems($list->items, $env);
    }

    /**
     * @return array{kind: 'bool', value: bool}|array{kind: 'css', expression: string}
     */
    private function evaluateInlineIfCondition(AstNode $condition, Environment $env, bool $forceBoolean = false): array
    {
        $resolved = $this->valueEvaluator->evaluate($condition, $env);

        if (
            $resolved instanceof FunctionNode
            && strtolower($resolved->name) === 'sass'
            && count($resolved->arguments) >= 1
        ) {
            return $this->evaluateInlineIfCondition($resolved->arguments[0], $env, true);
        }

        if ($resolved instanceof ListNode && $resolved->separator === 'space' && count($resolved->items) > 0) {
            return $this->evaluateInlineIfListCondition($resolved->items, $env);
        }

        if ($resolved instanceof BooleanNode) {
            return ['kind' => 'bool', 'value' => $resolved->value];
        }

        if ($resolved instanceof NullNode) {
            return ['kind' => 'bool', 'value' => false];
        }

        if ($resolved instanceof StringNode) {
            if ($forceBoolean || $resolved->quoted) {
                return ['kind' => 'bool', 'value' => $this->condition->isTruthy($resolved)];
            }

            $expression = trim($resolved->value);

            if ($this->isLikelySassBooleanCondition($expression)) {
                return ['kind' => 'bool', 'value' => $this->condition->evaluate($expression, $env)];
            }

            return ['kind' => 'css', 'expression' => $resolved->value];
        }

        if ($resolved instanceof FunctionNode) {
            return ['kind' => 'css', 'expression' => $this->valueFormatter->format($resolved, $env)];
        }

        return ['kind' => 'bool', 'value' => $this->condition->isTruthy($resolved)];
    }

    private function isLikelySassBooleanCondition(string $expression): bool
    {
        $trimmed = trim($expression);

        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '$')) {
            return true;
        }

        if (str_starts_with(strtolower($trimmed), 'not ')) {
            return true;
        }

        return str_contains($trimmed, '==')
            || str_contains($trimmed, '!=')
            || str_contains($trimmed, '>=')
            || str_contains($trimmed, '<=')
            || str_contains($trimmed, ' > ')
            || str_contains($trimmed, ' < ');
    }

    private function collapseUrlStringConcatenation(string $value): string
    {
        $parts       = [];
        $current     = '';
        $quote       = null;
        $length      = strlen($value);
        $hasOperator = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $i++;
                    $current .= $value[$i];

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote    = $char;
                $current .= $char;

                continue;
            }

            if ($char === '+') {
                $parts[]     = trim($current);
                $current     = '';
                $hasOperator = true;

                continue;
            }

            $current .= $char;
        }

        $parts[] = trim($current);

        if (! $hasOperator) {
            return trim($value);
        }

        $combined = '';
        $quoted   = false;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (StringHelper::isQuoted($part)) {
                $combined .= StringHelper::unquote($part);
                $quoted    = true;

                continue;
            }

            $combined .= $part;
        }

        if ($combined === '') {
            return trim($value);
        }

        return $quoted ? '"' . $combined . '"' : $combined;
    }

    /**
     * @param AstNode[] $items
     * @return array{kind: 'bool', value: bool}|array{kind: 'css', expression: string}
     */
    private function evaluateInlineIfListCondition(array $items, Environment $env): array
    {
        $items   = $this->normalizeLogicalOperatorFunctions($items);
        $orParts = $this->splitByOperator($items, 'or');

        if (count($orParts) > 1) {
            $cssParts = [];

            foreach ($orParts as $part) {
                $partResult = $this->evaluateInlineIfListCondition($part, $env);

                if ($partResult['kind'] === 'bool') {
                    /** @var array{kind: 'bool', value: bool} $partResult */
                    if ($partResult['value']) {
                        return ['kind' => 'bool', 'value' => true];
                    }

                    continue;
                }

                /** @var array{kind: 'css', expression: string} $partResult */
                $cssParts[] = $partResult['expression'];
            }

            if ($cssParts === []) {
                return ['kind' => 'bool', 'value' => false];
            }

            return ['kind' => 'css', 'expression' => implode(' or ', $cssParts)];
        }

        $andParts = $this->splitByOperator($items, 'and');

        if (count($andParts) > 1) {
            $cssParts = [];

            foreach ($andParts as $part) {
                $partResult = $this->evaluateInlineIfListCondition($part, $env);

                if ($partResult['kind'] === 'bool') {
                    /** @var array{kind: 'bool', value: bool} $partResult */
                    if (! $partResult['value']) {
                        return ['kind' => 'bool', 'value' => false];
                    }

                    continue;
                }

                /** @var array{kind: 'css', expression: string} $partResult */
                $cssParts[] = $partResult['expression'];
            }

            if ($cssParts === []) {
                return ['kind' => 'bool', 'value' => true];
            }

            return ['kind' => 'css', 'expression' => implode(' and ', $cssParts)];
        }

        $first = $items[0] ?? null;

        if ($first instanceof StringNode && strtolower(trim($first->value)) === 'not') {
            $rest = array_slice($items, 1);

            if ($rest === []) {
                return ['kind' => 'bool', 'value' => false];
            }

            $restResult = $this->evaluateInlineIfCondition($rest[0], $env);

            if (count($rest) > 1) {
                $restResult = $this->evaluateInlineIfListCondition($rest, $env);
            }

            if ($restResult['kind'] === 'bool') {
                /** @var array{kind: 'bool', value: bool} $restResult */
                return ['kind' => 'bool', 'value' => ! $restResult['value']];
            }

            /** @var array{kind: 'css', expression: string} $restResult */
            $expr = $restResult['expression'];

            if (str_contains($expr, ' and ') || str_contains($expr, ' or ')) {
                $expr = '(' . $expr . ')';
            }

            return ['kind' => 'css', 'expression' => 'not ' . $expr];
        }

        $comparisonResult = $this->evaluateInlineIfListComparison($items, $env);

        if ($comparisonResult !== null) {
            return ['kind' => 'bool', 'value' => $comparisonResult];
        }

        if (count($items) === 1) {
            return $this->evaluateInlineIfCondition($items[0], $env);
        }

        return [
            'kind'       => 'css',
            'expression' => $this->valueFormatter->format(new ListNode(array_values($items), 'space'), $env),
        ];
    }

    /**
     * @param AstNode[] $items
     * @return AstNode[][]
     */
    private function splitByOperator(array $items, string $operator): array
    {
        $parts   = [];
        $current = [];

        foreach ($items as $item) {
            if ($item instanceof StringNode && strtolower(trim($item->value)) === $operator) {
                if ($current !== []) {
                    $parts[] = $current;
                }

                $current = [];

                continue;
            }

            $current[] = $item;
        }

        if ($current !== []) {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * @param AstNode[] $items
     */
    private function evaluateInlineIfListComparison(array $items, Environment $env): ?bool
    {
        if (count($items) !== 3 || ! ($items[1] instanceof StringNode)) {
            return null;
        }

        $operator = trim($items[1]->value);

        if (! in_array($operator, ['==', '!=', '>=', '<=', '>', '<'], true)) {
            return null;
        }

        $left  = $this->valueEvaluator->evaluate($items[0], $env);
        $right = $this->valueEvaluator->evaluate($items[2], $env);

        return $this->condition->compare($left, $operator, $right, $env);
    }

    /**
     * @param AstNode[] $items
     */
    private function evaluateLogicalItems(array $items, Environment $env): ?AstNode
    {
        $items   = $this->normalizeLogicalOperatorFunctions($items);
        $orParts = $this->splitByOperator($items, 'or');

        if (count($orParts) > 1) {
            $last = null;

            foreach ($orParts as $part) {
                $result = $this->evaluateLogicalItems($part, $env);

                if ($result === null) {
                    return null;
                }

                if ($this->condition->isTruthy($result)) {
                    return $result;
                }

                $last = $result;
            }

            return $last;
        }

        $andParts = $this->splitByOperator($items, 'and');

        if (count($andParts) > 1) {
            $last = null;

            foreach ($andParts as $part) {
                $result = $this->evaluateLogicalItems($part, $env);

                if ($result === null) {
                    return null;
                }

                if (! $this->condition->isTruthy($result)) {
                    return $result;
                }

                $last = $result;
            }

            return $last;
        }

        $first = $items[0] ?? null;

        if ($first instanceof StringNode && strtolower(trim($first->value)) === 'not') {
            $rest = array_slice($items, 1);

            if ($rest === []) {
                return $this->valueFactory->createBooleanNode(false);
            }

            $result = $this->evaluateLogicalItems($rest, $env);

            if ($result === null) {
                return null;
            }

            return $this->valueFactory->createBooleanNode(! $this->condition->isTruthy($result));
        }

        if (count($items) === 1) {
            $item = $items[0];

            if (
                $item instanceof FunctionNode
                && strtolower($item->name) === 'sass'
                && count($item->arguments) >= 1
            ) {
                return $this->valueEvaluator->evaluate($item->arguments[0], $env);
            }

            return $item;
        }

        return $this->comparisonListEvaluator->evaluate(new ListNode(array_values($items), 'space'), $env);
    }

    /**
     * @param AstNode[] $items
     * @return AstNode[]
     */
    private function normalizeLogicalOperatorFunctions(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! $item instanceof FunctionNode || count($item->arguments) !== 1) {
                $normalized[] = $item;

                continue;
            }

            $name = strtolower($item->name);

            if (! in_array($name, ['and', 'or', 'not'], true)) {
                $normalized[] = $item;

                continue;
            }

            $normalized[] = new StringNode($name);
            $normalized[] = $item->arguments[0];
        }

        return $normalized;
    }
}
