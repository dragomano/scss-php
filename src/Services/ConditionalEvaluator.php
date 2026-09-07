<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\NameNormalizer;
use Bugo\SCSS\Utils\StringHelper;
use Bugo\SCSS\Values\ValueFactory;
use Throwable;

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

        return $this->evaluateInlineIfFunctionInner($name, $arguments, $env);
    }

    /**
     * @param array<int, AstNode> $arguments
     */
    private function evaluateInlineIfFunctionInner(string $name, array $arguments, Environment $env): AstNode
    {

        $decoded  = $this->decodeIfArguments($arguments, $env);
        $clauses  = $decoded['clauses'];
        $else     = $decoded['else'];
        $cssParts = [];
        $isCss    = false;

        foreach ($clauses as [$condition, $value]) {
            $result = $this->evaluateInlineIfCondition($condition, $env);

            if ($result['kind'] === 'bool') {
                /** @var array{kind: 'bool', value: bool} $result */
                if ($result['value']) {
                    return $this->valueEvaluator->evaluate($value, $env);
                }

                continue;
            }

            /** @var array{kind: 'css', expression: string} $result */
            $isCss = true;

            $valueText = $this->valueFormatter->format(
                $this->valueEvaluator->evaluate($value, $env),
                $env,
            );

            $cssParts[] = $result['expression'] . ': ' . $valueText;
        }

        if ($isCss) {
            $expression = 'if(' . implode('; ', $cssParts);

            if ($else !== null) {
                $expression .= '; else: ' . $this->valueFormatter->format(
                    $this->valueEvaluator->evaluate($else, $env),
                    $env,
                );
            }

            $expression .= ')';

            return new StringNode($expression);
        }

        return $else !== null
            ? $this->valueEvaluator->evaluate($else, $env)
            : $this->valueFactory->createNullNode();
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    private function expandSpreadArguments(array $arguments, Environment $env): array
    {
        $containsSpread = false;

        foreach ($arguments as $argument) {
            if ($argument instanceof SpreadArgumentNode) {
                $containsSpread = true;

                break;
            }
        }

        if (! $containsSpread) {
            return $arguments;
        }

        $expanded = [];

        foreach ($arguments as $argument) {
            if (! $argument instanceof SpreadArgumentNode) {
                $expanded[] = $argument;

                continue;
            }

            $value = $this->valueEvaluator->evaluate($argument->value, $env);

            if ($value instanceof ListNode || $value instanceof ArgumentListNode) {
                foreach ($value->items as $item) {
                    $expanded[] = $item instanceof NamedArgumentNode
                        ? $item
                        : $this->valueEvaluator->evaluate($item, $env);
                }

                continue;
            }

            $expanded[] = $this->valueEvaluator->evaluate($value, $env);
        }

        return $expanded;
    }

    /**
     * @param array<int, AstNode> $arguments
     * @param Environment $env
     * @return array{clauses: array<array{0: AstNode, 1: AstNode}>, else: AstNode|null}
     */
    private function decodeIfArguments(array $arguments, Environment $env): array
    {
        $arguments = $this->expandSpreadArguments($arguments, $env);

        $named      = [];
        $positional = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof NamedArgumentNode) {
                $named[NameNormalizer::normalize($argument->name)] = $argument->value;
            } else {
                $positional[] = $argument;
            }
        }

        if ($named !== []) {
            $condition          = $named['condition'] ?? $positional[0] ?? null;
            $conditionFromNamed = isset($named['condition']);
            $remaining          = $conditionFromNamed ? $positional : array_slice($positional, 1);
            $ifTrue             = $named['if-true'] ?? $remaining[0] ?? null;
            $ifFalse            = $named['if-false'] ?? $remaining[1] ?? null;

            if ($condition !== null && $ifTrue !== null) {
                return [
                    'clauses' => [[$condition, $ifTrue]],
                    'else'    => $ifFalse,
                ];
            }
        }

        $hasSentinel = false;

        foreach ($arguments as $argument) {
            if ($argument instanceof StringNode && $argument->value === '__else__') {
                $hasSentinel = true;

                break;
            }
        }

        if (! $hasSentinel && count($arguments) === 3) {
            return [
                'clauses' => [[$arguments[0], $arguments[1]]],
                'else'    => $arguments[2],
            ];
        }

        $clauses = [];
        $else    = null;
        $count   = count($arguments);
        $index   = 0;

        while ($index < $count) {
            $argument = $arguments[$index];

            if ($argument instanceof StringNode && $argument->value === '__else__') {
                $else ??= $arguments[$index + 1] ?? null;
                $index += 2;

                continue;
            }

            $clauses[] = [$argument, $arguments[$index + 1] ?? $this->valueFactory->createNullNode()];
            $index     += 2;
        }

        return ['clauses' => $clauses, 'else' => $else];
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
        $condition       = $this->normalizeRawConnectorIfs($condition, $env);
        $isParenthesized = ($condition instanceof ListNode || $condition instanceof FunctionNode) && $condition->parenthesized;

        if (
            $condition instanceof ListNode
            && $condition->separator === 'space'
            && count($condition->items) > 0
            && $this->hasLogicalOperator($condition->items)
        ) {
            $result = $this->evaluateInlineIfListCondition($condition->items, $env);

            if ($result['kind'] === 'css') {
                /** @var array{kind: 'css', expression: string} $result */
                $result['expression'] = $this->wrapParensIfNeeded($isParenthesized, $result['expression']);
            }

            return $result;
        }

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

            return ['kind' => 'css', 'expression' => $this->wrapParensIfNeeded($isParenthesized, $resolved->value)];
        }

        if ($resolved instanceof FunctionNode) {
            return ['kind' => 'css', 'expression' => $this->wrapParensIfNeeded($isParenthesized, $this->valueFormatter->format($resolved, $env))];
        }

        return ['kind' => 'bool', 'value' => $this->condition->isTruthy($resolved)];
    }

    private function wrapParensIfNeeded(bool $isParenthesized, string $expression): string
    {
        if (! $isParenthesized) {
            return $expression;
        }

        return '(' . trim($expression) . ')';
    }

    private function stripRedundantRawParens(string $expression): string
    {
        $expression = trim($expression);

        if ($expression === '' || $expression[0] !== '(' || $expression[strlen($expression) - 1] !== ')') {
            return $expression;
        }

        $depth = 0;

        for ($i = 0; $i < strlen($expression); $i++) {
            if ($expression[$i] === '(') {
                $depth++;
            } elseif ($expression[$i] === ')') {
                $depth--;

                if ($depth === 0 && $i !== strlen($expression) - 1) {
                    return $expression;
                }
            }
        }

        return substr($expression, 1, -1);
    }

    private function normalizeRawConnectorIfs(AstNode $node, Environment $env): AstNode
    {
        if ($node instanceof FunctionNode && strtolower($node->name) === 'if') {
            $decoded = $this->decodeIfArguments($node->arguments, $env);

            if (count($decoded['clauses']) === 0 && $decoded['else'] !== null) {
                return new StringNode(
                    'if(else: '
                    . $this->valueFormatter->format($this->valueEvaluator->evaluate($decoded['else'], $env), $env)
                    . ')',
                );
            }

            return new FunctionNode(
                $node->name,
                array_map(fn(AstNode $argument): AstNode => $this->normalizeRawConnectorIfs($argument, $env), $node->arguments),
            );
        }

        if ($node instanceof ListNode) {
            $items = array_map(
                fn(AstNode $item): AstNode => $this->normalizeRawConnectorIfs($item, $env),
                $node->items,
            );

            $items = $this->mergeInterpolatedFunctionCalls($items);

            return new ListNode(array_values($items), $node->separator, $node->bracketed, $node->parenthesized);
        }

        if ($node instanceof StringNode && str_contains($node->value, '#{')) {
            try {
                $value = $this->text->replaceInterpolations($node->value, $env);
            } catch (Throwable) {
                $value = $node->value;
            }

            return new StringNode($value, $node->quoted);
        }

        return $node;
    }

    /**
     * An interpolation like `#{css}()` is parsed as a bare string followed by an
     * empty parenthesized group; merge them into a single function call.
     *
     * @param AstNode[] $items
     * @return AstNode[]
     */
    private function mergeInterpolatedFunctionCalls(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $previous = $merged === [] ? null : $merged[count($merged) - 1];

            if (
                $previous instanceof StringNode
                && ! $previous->quoted
                && (
                    ($item instanceof ListNode && $item->parenthesized && count($item->items) === 0)
                    || ($item instanceof MapNode && count($item->pairs) === 0)
                )
            ) {
                $merged[count($merged) - 1] = new FunctionNode($previous->value, []);

                continue;
            }

            $merged[] = $item;
        }

        return $merged;
    }

    /**
     * @param AstNode[] $items
     */
    private function hasLogicalOperator(array $items): bool
    {
        /** @var AstNode $item */
        foreach ($items as $item) {
            if (
                $item instanceof StringNode
                && in_array(strtolower(trim($item->value)), ['and', 'or', 'not'], true)
            ) {
                return true;
            }

            if (
                $item instanceof FunctionNode
                && in_array(strtolower($item->name), ['and', 'or', 'not'], true)
                && count($item->arguments) >= 1
            ) {
                return true;
            }
        }

        return false;
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

            if (count($cssParts) === 1) {
                $cssParts[0] = $this->stripRedundantRawParens($cssParts[0]);
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

            if (count($cssParts) === 1) {
                $cssParts[0] = $this->stripRedundantRawParens($cssParts[0]);
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
