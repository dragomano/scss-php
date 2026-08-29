<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\StringEscapeDecoder;

use function count;
use function ctype_alpha;
use function ctype_digit;
use function is_array;
use function ltrim;
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
        $resolved = $this->normalizeCssLogicalOperators($resolved);

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

        return $this->normalizeCssLogicalOperators(
            $this->replaceVariableReferencesInText($resolved, $env),
        );
    }

    public function replaceInterpolations(string $value, Environment $env): string
    {
        $value = StringEscapeDecoder::protectHashes($value);

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
        $cacheKey = $condition . '|' . $operator;

        /** @var array<string, array<int, string>> $cache */
        static $cache = [];

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

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

            if (strtolower(substr($condition, $i, $needleLength)) !== $needle) {
                continue;
            }

            $parts[] = trim(substr($condition, $start, $i - $start));

            $start = $i + $needleLength;
            $i     = $start - 1;
        }

        if ($start === 0) {
            $result = [trim($condition)];
        } else {
            $parts[] = trim(substr($condition, $start));

            $result = $parts;
        }

        $cache[$cacheKey] = $result;

        return $result;
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
                $result .= $this->stripComments(substr($condition, $offset));

                break;
            }

            $result  .= $this->stripComments(substr($condition, $offset, $openPos - $offset));
            $closePos = strpos($condition, ')', $openPos + 1);

            if ($closePos === false) {
                $result .= substr($condition, $openPos);

                break;
            }

            $inner = substr($condition, $openPos + 1, $closePos - $openPos - 1);

            $normalized = $this->normalizeSupportsDeclarationInner($inner);

            if ($normalized === null) {
                // Not a declaration — check if this is a function call
                $preceding  = ($openPos > 0) ? $condition[$openPos - 1] : '';
                $isFunction = $preceding !== '' && (
                    ctype_alpha($preceding)
                    || $preceding === '_'
                    || $preceding === '-'
                );

                if ($isFunction) {
                    // Function args: use raw text as-is (comments already handled by parseCondition)
                    $result .= '(' . $inner . ')';
                } else {
                    // "Anything" expression: strip leading comment and whitespace
                    $result .= '(' . $this->stripLeadingCommentAndWhitespace($inner) . ')';
                }
            } else {
                $result .= '(' . $normalized . ')';
            }

            $offset = $closePos + 1;
        }

        return $result;
    }

    private function stripLeadingCommentAndWhitespace(string $text): string
    {
        $ltrimmed = ltrim($text);

        // Strip leading /* ... */
        if (str_starts_with($ltrimmed, '/*')) {
            $endPos = strpos($ltrimmed, '*/');

            if ($endPos !== false) {
                $ltrimmed = ltrim(substr($ltrimmed, $endPos + 2));
            }
        }

        return $ltrimmed;
    }

    private function normalizeSupportsDeclarationInner(string $inner): ?string
    {
        $trimmed = ltrim($inner);

        $colonPos = strpos($trimmed, ':');

        if ($colonPos === false) {
            return null;
        }

        $name = trim($this->stripComments(substr($trimmed, 0, $colonPos)));

        if ($name === '') {
            return null;
        }

        // Custom properties (--*): preserve raw value structure
        if (str_starts_with($name, '--')) {
            if (! $this->isValidSupportsFeatureName($name)) {
                return null;
            }

            // colonPos is relative to $trimmed; the raw value starts right after the colon
            $rawValue = substr($trimmed, $colonPos + 1);
            $value    = $this->normalizeCustomPropertyValue($rawValue);

            return $name . ':' . $value;
        }

        // Normal properties: trim and use parseColonSeparatedPair
        $parsed = $this->parseColonSeparatedPair(trim($inner));

        if ($parsed === null) {
            return null;
        }

        $name  = trim($this->stripComments($parsed['name']));
        $value = trim($this->stripComments($parsed['value']));

        if ($name === '' || $value === '' || ! $this->isValidSupportsFeatureName($name)) {
            return null;
        }

        return $name . ': ' . $value;
    }

    private function normalizeCustomPropertyValue(string $value): string
    {
        $result = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === "\n" || $char === "\r") {
                // Skip \r\n as a single newline
                if ($char === "\r" && $i + 1 < $length && $value[$i + 1] === "\n") {
                    ++$i;
                }

                // Also strip the whitespace character immediately before the newline
                if ($result !== '') {
                    $lastChar = $result[strlen($result) - 1];

                    if ($lastChar === ' ' || $lastChar === "\t") {
                        $result = substr($result, 0, -1);
                    }
                }

                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    private function stripComments(string $text): string
    {
        $result = '';
        $length = strlen($text);
        $i      = 0;

        while ($i < $length) {
            if ($text[$i] === '/' && $i + 1 < $length && $text[$i + 1] === '*') {
                $end = strpos($text, '*/', $i + 2);

                if ($end !== false) {
                    $i = $end + 2;

                    continue;
                }

                break;
            }

            if ($text[$i] === '/' && $i + 1 < $length && $text[$i + 1] === '/') {
                $newlinePos = strpos($text, "\n", $i);

                if ($newlinePos !== false) {
                    $i = $newlinePos + 1;
                } else {
                    $i += 2;
                }

                continue;
            }

            $result .= $text[$i];

            $i++;
        }

        return $result;
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

        if (str_starts_with(strtolower($expression), 'not ')) {
            return [
                'type'  => 'not',
                'child' => $this->parseSupportsExpression(substr($expression, 4)),
            ];
        }

        if ($this->isWrappedBySingleOuterParentheses($expression)) {
            $inner = trim(substr($expression, 1, -1));

            if ($this->hasTopLevelLogicalOperator($inner) || str_starts_with(strtolower($inner), 'not ')) {
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
            if ($this->isInterpolatedStringTemplate($expr)) {
                return $this->interpolateQuotedTemplate($expr, $env);
            }

            $expr = $this->substituteNestedInterpolationsAsLiterals($expr, $env);
        }

        if ($expr[0] === '$') {
            $name = substr($expr, 1);

            if ($this->isVariableName($name)) {
                $value = $this->valueEvaluator->evaluate(new VariableReferenceNode($name), $env);

                return $this->formatInterpolationValue($value, $env);
            }
        }

        if ($this->isSingleQuotedString($expr)) {
            return StringEscapeDecoder::decodeLiteral(substr($expr, 1, -1));
        }

        $valueNode = $this->parser->parseInlineExpression($expr);

        if ($valueNode instanceof StringNode && $valueNode->value === $expr) {
            return $expr;
        }

        $evaluated = $this->valueEvaluator->evaluate($valueNode, $env);

        return $this->formatInterpolationValue($evaluated, $env);
    }

    private function interpolateQuotedTemplate(string $expr, Environment $env): string
    {
        $length      = strlen($expr);
        $result      = '';
        $staticStart = 1;
        $index       = 1;

        while ($index < $length - 1) {
            if ($expr[$index] === '\\' && $index + 1 < $length) {
                $index += 2;

                continue;
            }

            if ($expr[$index] !== '#' || ($expr[$index + 1] ?? '') !== '{') {
                $index++;

                continue;
            }

            $cursor = $index + 2;
            $depth  = 1;

            while ($cursor < $length && $depth > 0) {
                if ($expr[$cursor] === '{') {
                    $depth++;
                } elseif ($expr[$cursor] === '}') {
                    $depth--;
                }

                $cursor++;
            }

            if ($depth !== 0) {
                break;
            }

            $result .= StringEscapeDecoder::decodeLiteral(substr($expr, $staticStart, $index - $staticStart));
            $result .= $this->resolveInterpolationExpression(trim(substr($expr, $index + 2, $cursor - $index - 3)), $env);

            $index       = $cursor;
            $staticStart = $cursor;
        }

        $result .= StringEscapeDecoder::decodeLiteral(substr($expr, $staticStart, $length - 1 - $staticStart));

        return $result;
    }

    private function substituteNestedInterpolationsAsLiterals(string $expr, Environment $env): string
    {
        $length = strlen($expr);
        $index  = 0;
        $result = '';

        while ($index < $length) {
            $pos = strpos($expr, '#{', $index);

            if ($pos === false) {
                $result .= substr($expr, $index);

                break;
            }

            $result .= substr($expr, $index, $pos - $index);

            $start  = $pos + 2;
            $cursor = $start;
            $depth  = 1;

            while ($cursor < $length && $depth > 0) {
                if ($expr[$cursor] === '{') {
                    $depth++;
                } elseif ($expr[$cursor] === '}') {
                    $depth--;
                }

                $cursor++;
            }

            if ($depth !== 0) {
                $result .= substr($expr, $pos);

                break;
            }

            $inner    = trim(substr($expr, $start, $cursor - $start - 1));
            $index    = $cursor;
            $resolved = $this->resolveInterpolationExpression($inner, $env);

            $result .= '"' . StringEscapeDecoder::encodeQuotedContent($resolved, '"') . '"';
        }

        return $result;
    }

    private function isInterpolatedStringTemplate(string $expr): bool
    {
        $length = strlen($expr);

        if ($length < 2) {
            return false;
        }

        $quote = $expr[0];

        if (($quote !== '"' && $quote !== "'") || $expr[$length - 1] !== $quote) {
            return false;
        }

        $index = 1;

        while ($index < $length - 1) {
            $char = $expr[$index];

            if ($char === '\\') {
                $index += 2;

                continue;
            }

            if ($char === '#' && ($expr[$index + 1] ?? '') === '{') {
                $depth  = 1;
                $index += 2;

                while ($index < $length && $depth > 0) {
                    if ($expr[$index] === '{') {
                        $depth++;
                    } elseif ($expr[$index] === '}') {
                        $depth--;
                    }

                    $index++;
                }

                continue;
            }

            if ($char === $quote) {
                return false;
            }

            $index++;
        }

        return true;
    }

    private function isSingleQuotedString(string $expr): bool
    {
        $length = strlen($expr);

        if ($length < 2) {
            return false;
        }

        $quote = $expr[0];

        if ($quote !== '"' && $quote !== "'") {
            return false;
        }

        if ($expr[$length - 1] !== $quote) {
            return false;
        }

        for ($i = 1; $i < $length - 1; $i++) {
            $char = $expr[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($char === $quote) {
                return false;
            }
        }

        return true;
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
                $index   = $cursor;

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

    private function normalizeCssLogicalOperators(string $value): string
    {
        $result = '';
        $length = strlen($value);
        $index  = 0;

        while ($index < $length) {
            $char = $value[$index];

            if (! ctype_alpha($char)) {
                $result .= $char;
                $index++;

                continue;
            }

            $start = $index;

            while ($index < $length && ctype_alpha($value[$index])) {
                $index++;
            }

            $word = substr($value, $start, $index - $start);

            $result .= in_array(strtolower($word), ['and', 'or', 'not'], true)
                ? strtolower($word)
                : $word;
        }

        return $result;
    }
}
