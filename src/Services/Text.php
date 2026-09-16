<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Exceptions\SassThrowable;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\MediaQuery;
use Bugo\SCSS\Utils\StringEscapeDecoder;
use Bugo\SCSS\Utils\StringHelper;

use function count;
use function ctype_alpha;
use function ctype_digit;
use function ctype_space;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function max;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
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
        $resolved = $this->unwrapRedundantSupportsParentheses($condition);
        $resolved = $this->interpolateText($resolved, $env);
        $resolved = $this->normalizeCssLogicalOperators($resolved);
        $resolved = $this->normalizeSupportsFeatureDeclarations($resolved, $env);
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
        $prelude  = $this->normalizeCssLogicalOperators($prelude);
        $prelude  = $this->collapseNegatedGeneralQueries($prelude);

        $resolved = str_contains($prelude, '#{')
            ? $this->interpolateText($prelude, $env)
            : $prelude;

        $resolved = $this->replaceVariableReferencesInText($resolved, $env);

        return $this->stripPreludeComments($resolved);
    }

    public function normalizeMediaQueryPrelude(string $prelude): string
    {
        $prelude = $this->unwrapRedundantNotParentheses($prelude);

        $padded = $this->padMediaQueryOperators($prelude);

        $queries = MediaQuery::parseList($padded);

        if ($queries === null) {
            $stripped = $this->stripAllComments($padded);

            if ($stripped !== '') {
                $queries = MediaQuery::parseList($stripped);
            }

            return $queries === null ? $padded : MediaQuery::serializeList($queries);
        }

        return MediaQuery::serializeList($queries);
    }

    public function evaluateMediaFeatureOperands(string $prelude, Environment $env): string
    {
        $length = strlen($prelude);
        $result = '';
        $index  = 0;

        while ($index < $length) {
            if ($prelude[$index] !== '(') {
                $result .= $prelude[$index];

                $index++;

                continue;
            }

            $start = $index + 1;
            $depth = 1;
            $close = -1;

            for ($i = $start; $i < $length; $i++) {
                if ($prelude[$i] === '(') {
                    $depth++;

                    continue;
                }

                if ($prelude[$i] === ')') {
                    $depth--;

                    if ($depth === 0) {
                        $close = $i;

                        break;
                    }
                }
            }

            if ($close === -1) {
                $result .= substr($prelude, $index);

                break;
            }

            $inner   = substr($prelude, $start, $close - $start);
            $result .= '(' . $this->evaluateFeatureGroup($inner, $env) . ')';
            $index   = $close + 1;
        }

        return $result;
    }

    public function collapseWhitespaceInPrelude(string $prelude): string
    {
        if (str_contains($prelude, "\n") || str_contains($prelude, "\r")) {
            return $prelude;
        }

        return $this->collapseWhitespace($prelude);
    }

    public function normalizePlainCssMediaQueryPrelude(string $prelude): string
    {
        return $this->normalizeMediaQueryPrelude($this->normalizeCssLogicalOperators($prelude));
    }

    public function normalizeCssImportQuery(string $import, Environment $env): string
    {
        [$source, $query] = $this->splitCssImportSourceAndQuery($import);

        if ($query === '') {
            return $import;
        }

        $normalized = $this->normalizeImportQueryMedia($query, $env);

        return $source . ($normalized === '' ? '' : ' ' . $normalized);
    }

    public function stripAllComments(string $text): string
    {
        $result = '';
        $length = strlen($text);
        $i      = 0;

        while ($i < $length) {
            if ($text[$i] === '/' && ($text[$i + 1] ?? '') === '*') {
                $end = strpos($text, '*/', $i + 2);

                if ($end === false) {
                    break;
                }

                $i = $end + 2;

                continue;
            }

            $result .= $text[$i];
            $i++;
        }

        return trim($result);
    }

    public function stripLeadingComments(string $text): string
    {
        $text = ltrim($text);

        while (str_starts_with($text, '/*')) {
            $end = strpos($text, '*/', 2);

            if ($end === false) {
                $text = '';

                break;
            }

            $text = ltrim(substr($text, $end + 2));
        }

        return $text;
    }

    public function stripCommentsExceptTrailing(string $text): string
    {
        $trimmed = rtrim($text);

        if (str_ends_with($trimmed, '*/')) {
            $open = $this->findLoudCommentOpen($trimmed, strlen($trimmed));

            if ($open !== false) {
                $head   = substr($trimmed, 0, $open);
                $tail   = substr($trimmed, $open);
                $result = '';
                $length = strlen($head);
                $i      = 0;

                while ($i < $length) {
                    if ($head[$i] === '/' && ($head[$i + 1] ?? '') === '*') {
                        $i = (int) strpos($head, '*/', $i + 2) + 2;

                        continue;
                    }

                    $result .= $head[$i];

                    $i++;
                }

                if (trim($result) !== '') {
                    return $result . $tail;
                }
            }
        }

        return $this->stripAllComments($text);
    }

    public function replaceInterpolations(string $value, Environment $env, bool $decoded = false): string
    {
        if (! $decoded) {
            $value = StringEscapeDecoder::protectHashes($value);
        }

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
                $char = $value[$cursor];

                if ($char === '/' && ($value[$cursor + 1] ?? '') === '*') {
                    $commentEnd = strpos($value, '*/', $cursor + 2);

                    $cursor = $commentEnd === false ? $length : $commentEnd + 2;

                    continue;
                }

                if ($char === '"' || $char === "'") {
                    $cursor = StringEscapeDecoder::skipQuotedChunk($value, $cursor);

                    continue;
                }

                if ($char === '\\' && $cursor + 1 < $length) {
                    $cursor += 2;

                    continue;
                }

                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
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

    private function findLoudCommentOpen(string $text, int $end): int|false
    {
        $open   = false;
        $i      = 0;
        $length = strlen($text);

        while ($i < $length) {
            if ($text[$i] === '/' && ($text[$i + 1] ?? '') === '*') {
                $close = strpos($text, '*/', $i + 2);

                if ($close === false) {
                    break;
                }

                if ($close + 2 <= $end && ltrim(substr($text, $close + 2, $end - $close - 2)) === '') {
                    $open = $i;
                }

                $i = $close + 2;

                continue;
            }

            $i++;
        }

        return $open;
    }

    private function stripPreludeComments(string $text): string
    {
        $text   = StringHelper::trimPreservingEscapeTerminator($text);
        $length = strlen($text);

        if ($length === 0) {
            return '';
        }

        /** @var list<array{text: string, comment: bool, significant: bool}> $chunks */
        $chunks = [];
        $i      = 0;

        while ($i < $length) {
            $isLoud = $text[$i] === '/' && ($text[$i + 1] ?? '') === '*';

            if (! $isLoud) {
                $start = $i;

                while ($i < $length && ! ($text[$i] === '/' && ($text[$i + 1] ?? '') === '*')) {
                    $i++;
                }

                $chunks[] = [
                    'text'        => substr($text, $start, $i - $start),
                    'comment'     => false,
                    'significant' => trim(substr($text, $start, $i - $start)) !== '',
                ];

                continue;
            }

            $end = strpos($text, '*/', $i + 2);

            if ($end === false) {
                $chunks[] = [
                    'text'        => substr($text, $i),
                    'comment'     => true,
                    'significant' => false,
                ];

                break;
            }

            $chunks[] = [
                'text'        => substr($text, $i, $end + 2 - $i),
                'comment'     => true,
                'significant' => false,
            ];

            $i = $end + 2;
        }

        $count            = count($chunks);
        $afterSignificant = false;
        $hasValueAfter    = [];

        for ($index = $count - 1; $index >= 0; $index--) {
            $hasValueAfter[$index] = $afterSignificant;

            if (! $chunks[$index]['comment'] && $chunks[$index]['significant']) {
                $afterSignificant = true;
            }
        }

        /** @var list<string> $kept */
        $kept      = [];
        $valueSeen = false;

        foreach ($chunks as $index => $chunk) {
            if (! $chunk['comment']) {
                if (! $chunk['significant']) {
                    continue;
                }

                $kept[]    = StringHelper::trimPreservingEscapeTerminator($chunk['text']);
                $valueSeen = true;

                continue;
            }

            if ($valueSeen && ! $hasValueAfter[$index]) {
                $kept[] = $chunk['text'];
            }
        }

        return implode(' ', $kept);
    }

    private function unwrapRedundantSupportsParentheses(string $condition): string
    {
        $result = '';
        $length = strlen($condition);
        $index  = 0;

        while ($index < $length) {
            $char = $condition[$index];

            if ($char === '"' || $char === "'") {
                $end = $this->findQuotedLiteralEnd($condition, $index);

                $result .= substr($condition, $index, $end - $index);
                $index   = $end;

                continue;
            }

            if ($char !== '(') {
                $result .= $char;
                $index++;

                continue;
            }

            $closePos = $this->findMatchingParenthesis($condition, $index);

            if ($closePos === null) {
                $result .= substr($condition, $index);

                break;
            }

            if ($this->isSupportsFunctionCall($condition, $index)) {
                $result .= substr($condition, $index, $closePos - $index + 1);
                $index   = $closePos + 1;

                continue;
            }

            $inner = $this->unwrapRedundantSupportsParentheses(
                substr($condition, $index + 1, $closePos - $index - 1),
            );

            $result .= $this->isWrappedBySingleOuterParentheses(trim($inner))
                ? trim($inner)
                : '(' . $inner . ')';

            $index = $closePos + 1;
        }

        return $result;
    }

    private function findQuotedLiteralEnd(string $text, int $start): int
    {
        $length = strlen($text);
        $quote  = $text[$start];

        for ($i = $start + 1; $i < $length; $i++) {
            if ($text[$i] === '\\') {
                $i++;

                continue;
            }

            if ($text[$i] === $quote) {
                return $i + 1;
            }
        }

        return $length;
    }

    private function unwrapRedundantNotParentheses(string $prelude): string
    {
        $prelude = StringHelper::trimPreservingEscapeTerminator($prelude);

        if ($this->isWrappedBySingleOuterParentheses($prelude)) {
            $inner = trim(substr($prelude, 1, -1));

            if (str_starts_with($inner, 'not ')) {
                return $inner;
            }
        }

        return $prelude;
    }

    private function padMediaQueryOperators(string $prelude): string
    {
        $result = '';
        $length = strlen($prelude);
        $i      = 0;

        while ($i < $length) {
            if ($prelude[$i] === '#' && ($prelude[$i + 1] ?? '') === '{') {
                $depth   = 1;
                $result .= $prelude[$i] . $prelude[$i + 1];

                $i += 2;

                while ($i < $length && $depth > 0) {
                    if ($prelude[$i] === '{') {
                        $depth++;
                    } elseif ($prelude[$i] === '}') {
                        $depth--;
                    }

                    $result .= $prelude[$i];

                    $i++;
                }

                continue;
            }

            if (! ctype_alpha($prelude[$i])) {
                $result .= $prelude[$i];

                $i++;

                continue;
            }

            $start = $i;

            while ($i < $length && ctype_alpha($prelude[$i])) {
                $i++;
            }

            $word = substr($prelude, $start, $i - $start);

            if (
                ! in_array(strtolower($word), ['and', 'or', 'not'], true)
                || $start === 0
                || in_array($prelude[$start - 1], [')', ']'], true) === false
            ) {
                $result .= $word;

                continue;
            }

            $result .= ' ' . $word;
        }

        return StringHelper::trimPreservingEscapeTerminator($result);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitCssImportSourceAndQuery(string $import): array
    {
        $trimmed = trim($import);
        $length  = strlen($trimmed);

        if ($length === 0) {
            return ['', ''];
        }

        $char = $trimmed[0];

        if ($char === '"' || $char === "'") {
            $end = $this->findQuotedLiteralEnd($trimmed, 0);
        } elseif (strtolower(substr($trimmed, 0, 4)) === 'url(') {
            $close = $this->findMatchingParenthesis($trimmed, 3);

            if ($close === null) {
                return [$trimmed, ''];
            }

            $end = $close + 1;
        } else {
            return [$trimmed, ''];
        }

        return [substr($trimmed, 0, $end), trim(substr($trimmed, $end))];
    }

    private function normalizeImportQueryMedia(string $query, Environment $env): string
    {
        $protected = [];
        $result    = '';
        $length    = strlen($query);
        $index     = 0;

        while ($index < $length) {
            $char = $query[$index];

            if ($char !== '(') {
                $result .= $char;

                $index++;

                continue;
            }

            $close = $this->findMatchingParenthesis($query, $index);

            if ($close === null) {
                $result .= substr($query, $index);

                break;
            }

            $group = substr($query, $index + 1, $close - $index - 1);

            if ($this->isStaticImportFeatureName($group)) {
                $result .= '(' . $group . ')';
                $index   = $close + 1;

                continue;
            }

            $resolved = $this->resolveImportQueryGroup($group, $env);
            $sentinel = "\x01" . count($protected) . "\x01";

            $protected[$sentinel] = $resolved;

            $result .= '(' . $sentinel . ')';
            $index   = $close + 1;
        }

        $normalized = $this->normalizeMediaQueryPrelude(
            $this->evaluateMediaFeatureOperands(
                $this->resolveDirectivePrelude($result, $env),
                $env,
            ),
        );

        foreach ($protected as $sentinel => $text) {
            $normalized = str_replace('(' . $sentinel . ')', '(' . $text . ')', $normalized);
        }

        return $normalized;
    }

    private function isStaticImportFeatureName(string $group): bool
    {
        $inner = trim($group);
        $colon = $this->findTopLevelColon($inner);

        if ($colon === null || $colon === 0) {
            return false;
        }

        $name     = substr($inner, 0, $colon);
        $length   = strlen($name);
        $hasAlpha = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $name[$i];

            if (ctype_alpha($char) || $char === '-' || $char === '_' || $char === '*') {
                $hasAlpha = $hasAlpha || ctype_alpha($char);

                continue;
            }

            return false;
        }

        return $hasAlpha;
    }

    private function resolveImportQueryGroup(string $group, Environment $env): string
    {
        $inner = trim($group);

        if ($inner === '') {
            return '';
        }

        $resolved = trim($this->interpolateText($inner, $env));
        $resolved = trim($this->replaceVariableReferencesInText($resolved, $env));

        if (StringHelper::isQuoted($resolved)) {
            $resolved = StringHelper::unquote($resolved);
        }

        return $resolved;
    }

    private function normalizeSupportsFeatureDeclarations(string $condition, Environment $env): string
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

            $before   = $this->stripComments(substr($condition, $offset, $openPos - $offset));
            $closePos = $this->findMatchingParenthesis($condition, $openPos);

            if ($closePos === null) {
                $result .= $before . substr($condition, $openPos);

                break;
            }

            $inner = substr($condition, $openPos + 1, $closePos - $openPos - 1);

            if ($this->isSupportsFunctionCall($condition, $openPos)) {
                $result .= $before . '(' . $this->stripLeadingSilentComment($inner) . ')';
            } else {
                $result .= $before . '(' . $this->normalizeSupportsGroup($inner, $env) . ')';
            }

            $offset = $closePos + 1;
        }

        return $result;
    }

    private function findMatchingParenthesis(string $text, int $openPos): ?int
    {
        $depth  = 0;
        $length = strlen($text);

        for ($i = $openPos; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '"' || $char === "'") {
                $i = $this->findQuotedLiteralEnd($text, $i) - 1;

                continue;
            }

            if ($char === '(') {
                $depth++;

                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function isSupportsFunctionCall(string $condition, int $openPos): bool
    {
        if ($openPos === 0) {
            return false;
        }

        $preceding = $condition[$openPos - 1];

        return ctype_alpha($preceding) || $preceding === '_' || $preceding === '-';
    }

    private function normalizeSupportsGroup(string $inner, Environment $env): string
    {
        $declaration = $this->normalizeSupportsDeclarationInner($inner, $env);

        if ($declaration !== null) {
            return $declaration;
        }

        $stripped = $this->stripLeadingCommentAndWhitespace($inner);

        if (str_starts_with($stripped, '(') || $this->startsWithNot($stripped)) {
            return $this->normalizeSupportsFeatureDeclarations($stripped, $env);
        }

        return $stripped;
    }

    private function stripLeadingCommentAndWhitespace(string $text): string
    {
        $ltrimmed = ltrim($text);

        if (str_starts_with($ltrimmed, '//')) {
            $newlinePos = strpos($ltrimmed, "\n");

            if ($newlinePos === false) {
                return '';
            }

            $ltrimmed = ltrim(substr($ltrimmed, $newlinePos + 1));
        }

        // Strip leading /* ... */
        if (str_starts_with($ltrimmed, '/*')) {
            $endPos = strpos($ltrimmed, '*/');

            if ($endPos !== false) {
                $ltrimmed = ltrim(substr($ltrimmed, $endPos + 2));
            }
        }

        return $ltrimmed;
    }

    private function stripLeadingSilentComment(string $inner): string
    {
        if (! str_starts_with($inner, '//')) {
            return $inner;
        }

        $newlinePos = strpos($inner, "\n");

        if ($newlinePos === false) {
            return '';
        }

        return substr($inner, $newlinePos);
    }

    private function normalizeSupportsDeclarationInner(string $inner, Environment $env): ?string
    {
        $trimmed = ltrim($inner);

        $colonPos = $this->findTopLevelColon($trimmed);

        if ($colonPos === null) {
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

        $value = trim($this->stripComments(substr($trimmed, $colonPos + 1)));

        if ($value === '') {
            return null;
        }

        return $this->resolveSupportsDeclarationPart($name, $env)
            . ': '
            . $this->resolveSupportsDeclarationPart($value, $env);
    }

    private function findTopLevelColon(string $text): ?int
    {
        $depth  = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '"' || $char === "'") {
                $i = $this->findQuotedLiteralEnd($text, $i) - 1;

                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === ':' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    private function resolveSupportsDeclarationPart(string $part, Environment $env): string
    {
        if (! $this->hasTopLevelArithmeticOperator($part)) {
            return $this->replaceVariableReferencesInText($part, $env);
        }

        $evaluated = $this->formatSupportsExpression($part, $env);

        if ($evaluated !== null && ! $this->hasTopLevelArithmeticOperator($evaluated)) {
            return $evaluated;
        }

        $resolved = $evaluated ?? $this->replaceVariableReferencesInText($part, $env);

        do {
            $previous = $resolved;
            $resolved = $this->collapsePlusConcatenation($resolved);
        } while ($resolved !== $previous);

        return $resolved;
    }

    private function formatSupportsExpression(string $expression, Environment $env): ?string
    {
        try {
            $node = $this->parser->parseInlineExpression($expression);

            return $this->valueFormatter->format($this->valueEvaluator->evaluate($node, $env), $env);
        } catch (SassThrowable) {
            return null;
        }
    }

    private function hasTopLevelArithmeticOperator(string $text): bool
    {
        $depth  = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '"' || $char === "'") {
                $i = $this->findQuotedLiteralEnd($text, $i) - 1;

                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;

                continue;
            }

            if ($char === ')' || $char === ']') {
                $depth--;

                continue;
            }

            if (
                $depth === 0
                && $i > 0
                && isset($text[$i + 1])
                && $text[$i - 1] === ' '
                && $text[$i + 1] === ' '
                && in_array($char, ['+', '-', '*', '/', '%'], true)
            ) {
                return true;
            }
        }

        return false;
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

        if ($this->startsWithNot($expression)) {
            return [
                'type'  => 'not',
                'child' => $this->parseSupportsExpression($this->stripNotPrefix($expression)),
            ];
        }

        if ($this->isWrappedBySingleOuterParentheses($expression)) {
            $inner = trim(substr($expression, 1, -1));

            if ($this->hasTopLevelLogicalOperator($inner) || $this->startsWithNot($inner)) {
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

        $isBareAmpersand = $valueNode instanceof StringNode
            && $valueNode->value === '&'
            && ! $valueNode->isSelectorValue;

        if ($valueNode instanceof StringNode && $valueNode->value === $expr && ! $isBareAmpersand) {
            return $expr;
        }

        $evaluated = $this->valueEvaluator->evaluate($valueNode, $env);

        return $this->formatInterpolationValue($evaluated, $env);
    }

    private function evaluateFeatureGroup(string $inner, Environment $env): string
    {
        $inner = trim($inner);

        if (
            StringHelper::isQuoted($inner)
            && $this->findQuotedLiteralEnd($inner, 0) === strlen($inner)
        ) {
            return StringHelper::unquote($inner);
        }

        foreach (['<=', '>=', '<', '>', '='] as $operator) {
            [$parts, $ops] = $this->splitMediaFeatureByOperator($inner, $operator);

            if (count($parts) < 2) {
                continue;
            }

            $result = $this->evaluateFeatureOperand($parts[0], $env);

            for ($i = 1, $n = count($parts); $i < $n; $i++) {
                $result .= ' ' . $ops[$i - 1] . ' ' . $this->evaluateFeatureOperand($parts[$i], $env);
            }

            return $result;
        }

        $colonPos = $this->findTopLevelColon($inner);

        if ($colonPos !== null) {
            $name  = trim(substr($inner, 0, $colonPos));
            $value = trim(substr($inner, $colonPos + 1));

            return $name . ': ' . $this->evaluateFeatureOperand($value, $env);
        }

        return $inner;
    }

    private function evaluateFeatureOperand(string $operand, Environment $env): string
    {
        $trimmed = trim($operand);

        if (! $this->shouldEvaluateFeatureOperand($trimmed)) {
            return $trimmed;
        }

        $valueNode = $this->parser->parseInlineExpression($trimmed);
        $evaluated = $this->valueEvaluator->evaluate($valueNode, $env);
        $formatted = $this->valueFormatter->format($evaluated, $env);

        if (trim($formatted) !== '') {
            return $formatted;
        }

        return $trimmed;
    }

    private function shouldEvaluateFeatureOperand(string $operand): bool
    {
        if ($operand === '' || ctype_digit($operand)) {
            return false;
        }

        if (str_starts_with($operand, 'if(')) {
            return true;
        }

        if ($operand[0] === '(' || $operand[0] === '[') {
            return true;
        }

        return str_contains($operand, '+') || str_contains($operand, '*');
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function splitMediaFeatureByOperator(string $text, string $operator): array
    {
        $length   = strlen($text);
        $parts    = [];
        $ops      = [];
        $depth    = 0;
        $brackets = 0;
        $quote    = '';
        $start    = 0;
        $opLength = strlen($operator);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== '') {
                if ($char === '\\') {
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '(') {
                $depth++;

                continue;
            }

            if ($char === ')') {
                $depth = max(0, $depth - 1);

                continue;
            }

            if ($char === '[') {
                $brackets++;

                continue;
            }

            if ($char === ']') {
                $brackets = max(0, $brackets - 1);

                continue;
            }

            if ($depth !== 0 || $brackets !== 0) {
                continue;
            }

            if (substr($text, $i, $opLength) === $operator) {
                $before = $i > 0 ? $text[$i - 1] : ' ';
                $after  = $text[$i + $opLength] ?? '';

                if ((ctype_space($before) || $before === '(') && ($after === ' ' || $after === '(')) {
                    $parts[] = trim(substr($text, $start, $i - $start));
                    $ops[]   = $operator;
                    $start   = $i + $opLength;
                    $i       = $start - 1;
                }
            }
        }

        if ($parts === []) {
            return [[$text], []];
        }

        $parts[] = trim(substr($text, $start));

        return [$parts, $ops];
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
        $quote  = '';

        while ($index < $length) {
            $pos = strpos($expr, '#{', $index);

            if ($pos === false) {
                $result .= substr($expr, $index);

                break;
            }

            $result .= substr($expr, $index, $pos - $index);

            $quote = $this->quoteStateBefore($expr, $index, $pos, $quote);

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

            $result .= $quote !== ''
                ? $resolved
                : '"' . StringEscapeDecoder::encodeQuotedContent($resolved, '"') . '"';
        }

        return $result;
    }

    private function quoteStateBefore(string $expr, int $from, int $until, string $initial): string
    {
        $quote = $initial;
        $index = $from;

        while ($index < $until) {
            $char = $expr[$index];

            if ($char === '\\') {
                $index += 2;

                continue;
            }

            if ($quote === '') {
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                }
            } elseif ($char === $quote) {
                $quote = '';
            }

            $index++;
        }

        return $quote;
    }

    private function isInterpolatedStringTemplate(string $expr): bool
    {
        $length = strlen($expr);
        $quote  = $expr[0];

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
            $kept  = [];

            foreach ($value->items as $item) {
                if ($item instanceof NullNode) {
                    continue;
                }

                $kept[]  = $item;
                $parts[] = $item instanceof StringNode
                    ? StringEscapeDecoder::encodeUnquotedContent($item->value)
                    : $this->formatInterpolationValue($item, $env);
            }

            $separator = match ($value->separator) {
                'comma' => ', ',
                'slash' => ' / ',
                default => ' ',
            };

            $formatted = $separator === ' '
                ? $this->joinInterpolationParts($kept, $parts)
                : implode($separator, $parts);

            if ($value->bracketed) {
                return '[' . $formatted . ']';
            }

            return $formatted;
        }

        return $this->valueFormatter->format($value, $env);
    }

    /**
     * @param array<int, AstNode> $items
     * @param list<string> $parts
     */
    private function joinInterpolationParts(array $items, array $parts): string
    {
        $formatted = '';
        $previous  = null;

        foreach ($parts as $index => $part) {
            $current = $items[$index] ?? null;

            if ($previous !== null && ! $this->isPreservedSlashDivision($previous) && ! $this->isPreservedSlashDivision($current)) {
                $formatted .= ' ';
            }

            $formatted .= $part;
            $previous   = $current;
        }

        return $formatted;
    }

    private function isPreservedSlashDivision(?AstNode $node): bool
    {
        return $node instanceof StringNode
            && ! $node->quoted
            && $node->value === '/'
            && $node->isSlashOperator;
    }

    private function collapsePlusConcatenation(string $value): string
    {
        $result = '';
        $length = strlen($value);
        $index  = 0;

        while ($index < $length) {
            if ($value[$index] === '(') {
                $isFunction = $index > 0 && (
                    ctype_alpha($value[$index - 1])
                    || $value[$index - 1] === '_'
                    || $value[$index - 1] === '-'
                );

                if ($isFunction) {
                    $depth = 1;
                    $start = $index;

                    $index++;

                    while ($index < $length && $depth > 0) {
                        if ($value[$index] === '(') {
                            $depth++;
                        } elseif ($value[$index] === ')') {
                            $depth--;
                        }

                        $index++;
                    }

                    $result .= substr($value, $start, $index - $start);

                    continue;
                }

                $result .= $value[$index];

                $index++;

                continue;
            }

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
        if (count($this->splitTopLevelByOperator($condition, 'and')) > 1
            || count($this->splitTopLevelByOperator($condition, 'or')) > 1) {
            return true;
        }

        if ($this->isWrappedBySingleOuterParentheses($condition)) {
            $inner = trim(substr($condition, 1, -1));

            return $this->hasTopLevelLogicalOperator($inner);
        }

        return false;
    }

    private function normalizeCssLogicalOperators(string $value): string
    {
        $result             = '';
        $length             = strlen($value);
        $index              = 0;
        $interpolationDepth = 0;

        while ($index < $length) {
            $char = $value[$index];

            if ($char === '"' || $char === "'") {
                $end     = $this->findQuotedLiteralEnd($value, $index);
                $result .= substr($value, $index, $end - $index);
                $index   = $end;

                continue;
            }

            if ($char === '{' && ($interpolationDepth > 0 || ($index > 0 && $value[$index - 1] === '#'))) {
                $interpolationDepth++;
            } elseif ($char === '}' && $interpolationDepth > 0) {
                $interpolationDepth--;
            }

            if ($interpolationDepth > 0) {
                $result .= $char;

                $index++;

                continue;
            }

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

    private function collapseNegatedGeneralQueries(string $prelude): string
    {
        $result = '';
        $length = strlen($prelude);
        $index  = 0;

        while ($index < $length) {
            $char = $prelude[$index];

            if ($char === '"' || $char === "'") {
                $end     = $this->findQuotedLiteralEnd($prelude, $index);
                $result .= substr($prelude, $index, $end - $index);
                $index   = $end;

                continue;
            }

            if ($char === '#' && ($prelude[$index + 1] ?? '') === '{') {
                $end     = $this->skipInterpolationSpan($prelude, $index);
                $result .= substr($prelude, $index, $end - $index);
                $index   = $end;

                continue;
            }

            if ($char === '(') {
                $close = $this->findMatchingParenthesis($prelude, $index);

                if ($close !== null) {
                    $inner = $this->collapseNegatedGeneralQueries(
                        substr($prelude, $index + 1, $close - $index - 1),
                    );

                    $collapsed = $this->collapseNegatedGroupContent($inner);

                    if ($collapsed !== $inner) {
                        $result .= $collapsed;

                        $index = $close + 1;

                        continue;
                    }

                    $result .= '(' . $inner . ')';
                    $index   = $close + 1;

                    continue;
                }
            }

            $result .= $char;

            $index++;
        }

        return $result;
    }

    private function collapseNegatedGroupContent(string $inner): string
    {
        $trimmed = trim($inner);
        $length  = strlen($trimmed);

        if ($length < 5 || strtolower(substr($trimmed, 0, 3)) !== 'not' || strspn($trimmed, " \t\r\n", 3) === 3) {
            return $inner;
        }

        $rest = ltrim(substr($trimmed, 3));

        if (! str_starts_with($rest, '(')) {
            return $inner;
        }

        if ($this->findMatchingParenthesis($rest, 0) !== strlen($rest) - 1) {
            return $inner;
        }

        $parts = $this->splitTopLevelByOperator(trim(substr($rest, 1, -1)), 'and');
        $kept  = [];

        foreach ($parts as $part) {
            if ($part !== '' && $part[0] === '(') {
                $kept[] = $part;
            }
        }

        if ($kept === []) {
            return $inner;
        }

        return 'not ' . implode(' and ', $kept);
    }

    private function skipInterpolationSpan(string $text, int $start): int
    {
        $depth  = 0;
        $length = strlen($text);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($text[$i] === '{') {
                $depth++;

                continue;
            }

            if ($text[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }

        return $length;
    }

    private function startsWithNot(string $text): bool
    {
        $collapsed = $this->collapseWhitespace($text);

        return str_starts_with(strtolower($collapsed), 'not ');
    }

    private function stripNotPrefix(string $text): string
    {
        return trim(substr($this->collapseWhitespace($text), 4));
    }

    private function collapseWhitespace(string $text): string
    {
        $result  = '';
        $length  = strlen($text);
        $pending = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === ' ' || $char === "\n" || $char === "\r" || $char === "\t") {
                $pending = true;

                continue;
            }

            if ($pending && $result !== '') {
                $result .= ' ';
            }

            $pending = false;
            $result .= $char;
        }

        return $result;
    }
}
