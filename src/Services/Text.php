<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;

use function count;
use function ctype_alpha;
use function ctype_digit;
use function is_array;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strspn;
use function strtolower;
use function substr;
use function trim;

final readonly class Text
{
    public function __construct(
        private ParserInterface $parser,
        private AstValueEvaluatorInterface $valueEvaluator,
        private AstValueFormatterInterface $valueFormatter,
    ) {}

    public function interpolateText(string $text, Environment $env): string
    {
        if (! str_contains($text, '#{')) {
            return $text;
        }

        $resolved  = $text;
        $maxPasses = 10;

        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $previous = $resolved;
            $resolved = $this->replaceInterpolations($resolved, $env);

            if ($resolved === $previous) {
                break;
            }
        }

        return $resolved;
    }

    public function resolveSupportsCondition(string $condition, Environment $env): string
    {
        $resolved = $this->interpolateText($condition, $env);
        $resolved = $this->replaceVariableReferencesInText($resolved, $env);

        do {
            $previous = $resolved;
            $resolved = $this->collapsePlusConcatenation($resolved);
        } while ($resolved !== $previous);

        $resolved = $this->normalizeSupportsFeatureDeclarations($resolved);
        $resolved = trim($resolved);

        if (
            ! $this->hasTopLevelLogicalOperator($resolved)
            && ! str_contains(strtolower($resolved), 'not')
        ) {
            return $resolved;
        }

        $parsed = $this->parseSupportsExpression($resolved);

        return trim($this->renderSupportsExpression($parsed, true));
    }

    public function resolveDirectivePrelude(string $prelude, Environment $env): string
    {
        $resolved = str_contains($prelude, '#{')
            ? $this->interpolateText($prelude, $env)
            : $prelude;

        return $this->replaceVariableReferencesInText($resolved, $env);
    }

    public function replaceInterpolations(string $value, Environment $env): string
    {
        $result = '';
        $length = strlen($value);
        $index  = 0;

        while ($index < $length) {
            $pos = strpos($value, '#{', $index);

            if ($pos === false) {
                $result .= substr($value, $index);

                break;
            }

            if ($pos > $index) {
                $result .= substr($value, $index, $pos - $index);
            }

            $start  = $pos + 2;
            $cursor = $start;
            $depth  = 1;

            while ($cursor < $length && $depth > 0) {
                if ($value[$cursor] === '{') {
                    $depth++;
                } elseif ($value[$cursor] === '}') {
                    $depth--;
                }

                $cursor++;
            }

            if ($depth !== 0) {
                $result .= substr($value, $pos);

                break;
            }

            $expr  = trim(substr($value, $start, $cursor - $start - 1));
            $index = $cursor;

            $result .= $this->resolveInterpolationExpression($expr, $env);
        }

        return $result;
    }

    public function replaceVariableReferencesInText(string $value, Environment $env): string
    {
        $result = '';
        $length = strlen($value);
        $index  = 0;

        while ($index < $length) {
            $pos = strpos($value, '$', $index);

            if ($pos === false) {
                $result .= substr($value, $index);

                break;
            }

            if ($pos > $index) {
                $result .= substr($value, $index, $pos - $index);
            }

            $nameLen = strspn(
                $value,
                'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-.',
                $pos + 1,
            );

            if ($nameLen === 0) {
                $result .= '$';
                $index   = $pos + 1;

                continue;
            }

            $name     = substr($value, $pos + 1, $nameLen);
            $resolved = $this->valueEvaluator->evaluate(new VariableReferenceNode($name), $env);
            $index    = $pos + 1 + $nameLen;

            if ($resolved instanceof StringNode) {
                $result .= $resolved->value;
            } else {
                $result .= $this->valueFormatter->format($resolved, $env);
            }
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function splitTopLevelByOperator(string $condition, string $operator): array
    {
        $parts  = [];
        $start  = 0;
        $depth  = 0;
        $length = strlen($condition);
        $needle = ' ' . $operator . ' ';

        $needleLength = strlen($needle);

        for ($i = 0; $i < $length; $i++) {
            $char = $condition[$i];

            if (in_array($char, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($char, [')', ']', '}'], true)) {
                $depth--;

                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            if (substr($condition, $i, $needleLength) !== $needle) {
                continue;
            }

            $parts[] = trim(substr($condition, $start, $i - $start));

            $start = $i + $needleLength;
            $i     = $start - 1;
        }

        if ($start === 0) {
            return [trim($condition)];
        }

        $parts[] = trim(substr($condition, $start));

        return $parts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractStringKeyedArrayItems(mixed $value): array
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = [];

        if (! is_array($value)) {
            return $items;
        }

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $items[] = $item;
        }

        return $items;
    }

    public function isWrappedBySingleOuterParentheses(string $condition): bool
    {
        $condition = trim($condition);

        if (! str_starts_with($condition, '(') || ! str_ends_with($condition, ')')) {
            return false;
        }

        $depth  = 0;
        $length = strlen($condition);

        for ($i = 0; $i < $length; $i++) {
            $char = $condition[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($depth === 0 && $i < $length - 1) {
                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * @return array{name: string, value: string}|null
     */
    public function parseColonSeparatedPair(string $input): ?array
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        $colonPosition = strpos($input, ':');

        if ($colonPosition === false) {
            return null;
        }

        $name  = trim(substr($input, 0, $colonPosition));
        $value = trim(substr($input, $colonPosition + 1));

        return ['name' => $name, 'value' => $value];
    }

    private function normalizeSupportsFeatureDeclarations(string $condition): string
    {
        $result = '';
        $offset = 0;
        $length = strlen($condition);

        while ($offset < $length) {
            $openPos = strpos($condition, '(', $offset);

            if ($openPos === false) {
                $result .= substr($condition, $offset);

                break;
            }

            $result  .= substr($condition, $offset, $openPos - $offset);
            $closePos = strpos($condition, ')', $openPos + 1);

            if ($closePos === false) {
                $result .= substr($condition, $openPos);

                break;
            }

            $inner = substr($condition, $openPos + 1, $closePos - $openPos - 1);

            $normalized = $this->normalizeSupportsDeclarationInner($inner);

            if ($normalized === null) {
                $result .= substr($condition, $openPos, $closePos - $openPos + 1);
            } else {
                $result .= '(' . $normalized . ')';
            }

            $offset = $closePos + 1;
        }

        return $result;
    }

    private function normalizeSupportsDeclarationInner(string $inner): ?string
    {
        $parsed = $this->parseColonSeparatedPair($inner);

        if ($parsed === null) {
            return null;
        }

        $name  = $parsed['name'];
        $value = $parsed['value'];

        if ($name === '' || $value === '' || ! $this->isValidSupportsFeatureName($name)) {
            return null;
        }

        return $name . ': ' . $value;
    }

    private function isValidSupportsFeatureName(string $name): bool
    {
        $first = $name[0];

        if (! ctype_alpha($first) && $first !== '_' && $first !== '-') {
            return false;
        }

        $nameLength = strlen($name);

        for ($i = 1; $i < $nameLength; $i++) {
            $char = $name[$i];

            if (! ctype_alpha($char) && ! ctype_digit($char) && $char !== '_' && $char !== '-') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSupportsExpression(string $expression): array
    {
        $expression = trim($expression);

        $orParts = $this->splitTopLevelByOperator($expression, 'or');

        if (count($orParts) > 1) {
            return $this->makeSupportsBinaryNode('or', $orParts);
        }

        $andParts = $this->splitTopLevelByOperator($expression, 'and');

        if (count($andParts) > 1) {
            return $this->makeSupportsBinaryNode('and', $andParts);
        }

        if (str_starts_with($expression, 'not ')) {
            return [
                'type'  => 'not',
                'child' => $this->parseSupportsExpression(substr($expression, 4)),
            ];
        }

        if ($this->isWrappedBySingleOuterParentheses($expression)) {
            $inner = trim(substr($expression, 1, -1));

            if ($this->hasTopLevelLogicalOperator($inner) || str_starts_with($inner, 'not ')) {
                $node = $this->parseSupportsExpression($inner);

                $node['grouped'] = true;

                return $node;
            }
        }

        return ['type' => 'atom', 'value' => $expression];
    }

    /**
     * @param array<int, string> $parts
     * @return array<string, mixed>
     */
    private function makeSupportsBinaryNode(string $operator, array $parts): array
    {
        /** @var array<int, array<string, mixed>> $children */
        $children = [];

        foreach ($parts as $part) {
            $node = $this->parseSupportsExpression($part);

            if (($node['type'] ?? null) === $operator) {
                $typedChildren = $this->extractStringKeyedArrayItems($node['children'] ?? null);

                foreach ($typedChildren as $child) {
                    $children[] = $child;
                }

                continue;
            }

            $children[] = $node;
        }

        return ['type' => $operator, 'children' => $children];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderSupportsExpression(array $node, bool $isTopLevel = false): string
    {
        $type = 'atom';

        if (isset($node['type']) && is_string($node['type'])) {
            $type = $node['type'];
        }

        if ($type === 'atom') {
            $value = '';

            if (isset($node['value']) && is_string($node['value'])) {
                $value = $node['value'];
            }

            return trim($value);
        }

        if ($type === 'not') {
            $childNode = ['type' => 'atom', 'value' => ''];

            if (isset($node['child']) && is_array($node['child'])) {
                /** @var array<string, mixed> $childNode */
                $childNode = $node['child'];
            }

            $inner = $this->renderSupportsExpression($childNode);
            $text  = 'not ' . $inner;

            if (! $isTopLevel) {
                return '(' . $text . ')';
            }

            return $text;
        }

        $joiner     = $type === 'or' ? ' or ' : ' and ';
        $parts      = [];
        $childNodes = [];

        if (isset($node['children']) && is_array($node['children'])) {
            $childNodes = $this->extractStringKeyedArrayItems($node['children']);
        }

        foreach ($childNodes as $child) {
            /** @var array<string, mixed> $child */
            $childText = $this->renderSupportsExpression($child);
            $childType = 'atom';

            if (isset($child['type']) && is_string($child['type'])) {
                $childType = $child['type'];
            }

            $needsParentheses = false;

            if (
                $childType === 'not'
                || ($type === 'or' && $childType === 'and')
                || ($type === 'and' && $childType === 'or')
            ) {
                $needsParentheses = true;
            }

            if ($needsParentheses && ! $this->isWrappedBySingleOuterParentheses($childText)) {
                $childText = '(' . $childText . ')';
            }

            $parts[] = $childText;
        }

        $text = implode($joiner, $parts);

        if (! $isTopLevel && ($node['grouped'] ?? false) === true) {
            return '(' . $text . ')';
        }

        return $text;
    }

    private function resolveInterpolationExpression(string $expr, Environment $env): string
    {
        if ($expr === '') {
            return '';
        }

        if (str_contains($expr, '#{')) {
            $expr = $this->interpolateText($expr, $env);
        }

        if ($expr[0] === '$') {
            $name = substr($expr, 1);

            if ($this->isVariableName($name)) {
                $value = $this->valueEvaluator->evaluate(new VariableReferenceNode($name), $env);

                return $this->formatInterpolationValue($value, $env);
            }
        }

        if (
            (str_starts_with($expr, '"') && str_ends_with($expr, '"'))
            || (str_starts_with($expr, "'") && str_ends_with($expr, "'"))
        ) {
            return substr($expr, 1, -1);
        }

        $ast = $this->parser->parse(".__tmp__ { __tmp__: $expr; }");

        $firstChild     = $ast->children[0] ?? null;
        $firstRuleChild = $firstChild instanceof RuleNode
            ? $firstChild->children[0] ?? null
            : null;

        if ($firstRuleChild instanceof DeclarationNode) {
            $valueNode = $this->valueEvaluator->evaluate($firstRuleChild->value, $env);

            return $this->formatInterpolationValue($valueNode, $env);
        }

        return $expr;
    }

    private function formatInterpolationValue(AstNode $value, Environment $env): string
    {
        if ($value instanceof StringNode) {
            return $value->value;
        }

        if ($value instanceof ListNode || $value instanceof ArgumentListNode) {
            $parts = [];

            foreach ($value->items as $item) {
                $parts[] = $this->formatInterpolationValue($item, $env);
            }

            $separator = match ($value->separator) {
                'comma' => ', ',
                'slash' => ' / ',
                default => ' ',
            };

            $formatted = implode($separator, $parts);

            if ($value->bracketed) {
                return '[' . $formatted . ']';
            }

            return $formatted;
        }

        return $this->valueFormatter->format($value, $env);
    }

    private function collapsePlusConcatenation(string $value): string
    {
        $result = '';
        $length = strlen($value);
        $index  = 0;

        while ($index < $length) {
            if (! $this->isVariableNameChar($value[$index])) {
                $result .= $value[$index];

                $index++;

                continue;
            }

            $left   = '';
            $cursor = $index;

            while ($cursor < $length && $this->isVariableNameChar($value[$cursor])) {
                $left .= $value[$cursor];

                $cursor++;
            }

            $spaceCursor = $cursor;

            while ($spaceCursor < $length && $value[$spaceCursor] === ' ') {
                $spaceCursor++;
            }

            if ($spaceCursor >= $length || $value[$spaceCursor] !== '+') {
                $result .= $left;

                $index = $cursor;

                continue;
            }

            $spaceCursor++;

            while ($spaceCursor < $length && $value[$spaceCursor] === ' ') {
                $spaceCursor++;
            }

            if ($spaceCursor >= $length || ! $this->isVariableNameChar($value[$spaceCursor])) {
                $result .= $left;
                $result .= substr($value, $cursor, $spaceCursor - $cursor);
                $index   = $spaceCursor;

                continue;
            }

            $right       = '';
            $rightCursor = $spaceCursor;

            while ($rightCursor < $length && $this->isVariableNameChar($value[$rightCursor])) {
                $right .= $value[$rightCursor];

                $rightCursor++;
            }

            $result .= $left . $right;
            $index   = $rightCursor;
        }

        return $result;
    }

    private function isVariableName(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        $length = strlen($name);

        for ($i = 0; $i < $length; $i++) {
            if (! $this->isVariableNameChar($name[$i])) {
                return false;
            }
        }

        return true;
    }

    private function isVariableNameChar(string $char): bool
    {
        return ctype_alpha($char) || ctype_digit($char) || $char === '_' || $char === '-' || $char === '.';
    }

    private function hasTopLevelLogicalOperator(string $condition): bool
    {
        return count($this->splitTopLevelByOperator($condition, 'and')) > 1
            || count($this->splitTopLevelByOperator($condition, 'or')) > 1;
    }
}
