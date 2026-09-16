<?php

declare(strict_types=1);

namespace Bugo\SCSS\Normalizers;

use Bugo\SCSS\Exceptions\InvalidSyntaxException;
use Bugo\SCSS\Syntax;

use function array_pop;
use function count;
use function ctype_alnum;
use function ctype_alpha;
use function ctype_digit;
use function end;
use function implode;
use function in_array;
use function intdiv;
use function ltrim;
use function ord;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function substr_count;
use function trim;

final readonly class SassNormalizer implements SourceNormalizer
{
    private const SINGLE_LINE_DIRECTIVES = [
        'import',
        'use',
        'forward',
        'charset',
        'extend',
        'return',
        'debug',
        'warn',
        'error',
    ];

    private const BLOCK_HEADER_DIRECTIVES = [
        'if',
        'else',
        'for',
        'each',
        'while',
        'media',
        'supports',
        'keyframes',
        'function',
        'mixin',
        'include',
    ];

    private const BLOCK_HEADER_CHARS = ['.', '#', '&', '%', '['];

    private const PSEUDO_CLASSES = [
        'hover',
        'active',
        'focus',
        'has',
        'first-child',
        'nth-child',
        'nth-of-type',
        'not',
    ];

    private const DIRECTIVE_HEADER_KEYWORDS = ['@for', '@each', '@while', '@if'];

    private const DIRECTIVE_CONTINUATION_KEYWORDS = ['from', 'to', 'through', 'in'];

    public function supports(Syntax $syntax): bool
    {
        return $syntax === Syntax::SASS;
    }

    public function normalize(string $source): string
    {
        $this->validateSyntax($source);

        $eol        = $this->detectLineEnding($source);
        $lines      = $this->splitByLineBreaks($source);
        $indentSize = $this->detectIndentSize($lines);

        /** @var list<string> $result */
        $result = [];

        /** @var list<array{level: int, spaces: int}> $stack */
        $stack = [];

        $pendingEmptyLines = [];

        $lineCount = count($lines);

        for ($index = 0; $index < $lineCount; $index++) {
            $rawLine = $lines[$index];
            $line    = rtrim($rawLine, "\r\n");
            $trimmed = ltrim($line);

            if ($trimmed === '') {
                $pendingEmptyLines[] = '';

                continue;
            }

            if (str_starts_with($trimmed, '/*')) {
                $this->flushPendingEmptyLines($result, $pendingEmptyLines);

                $commentLevel = intdiv(strlen($line) - strlen($trimmed), $indentSize);

                [$commentLines, $index] = $this->collectLoudComment(
                    $index,
                    $lines,
                    $commentLevel,
                    $indentSize,
                );

                foreach ($commentLines as $commentLine) {
                    $result[] = $commentLine;
                }

                continue;
            }

            if (str_starts_with($trimmed, '//')) {
                $this->flushPendingEmptyLines($result, $pendingEmptyLines);

                $commentLine = $line;

                while ($this->hasUnclosedInterpolation($commentLine) && $index + 1 < $lineCount) {
                    $commentLine .= ' ' . ltrim($lines[$index + 1]);

                    $index++;
                }

                $result[] = $commentLine;

                continue;
            }

            $leadingSpaces = strlen($line) - strlen($trimmed);
            $level         = intdiv($leadingSpaces, $indentSize);

            while (! empty($stack) && end($stack)['spaces'] >= $leadingSpaces) {
                /** @var array{level: int, spaces: int} $block */
                $block = array_pop($stack);

                $result[] = $this->indent($block['level'], $indentSize) . '}';
            }

            if ($level === 0) {
                $this->flushPendingEmptyLines($result, $pendingEmptyLines);
            }

            $pendingEmptyLines = [];

            $trimmed = $this->stripTrailingLineComment($trimmed);
            $trimmed = $this->stripLeadingEscape($trimmed);

            [$trimmed, $index] = $this->mergeEscapedNewlineContinuation($trimmed, $index, $lines);
            [$trimmed, $index] = $this->mergeCustomPropertyDeclaration(
                $trimmed,
                $level,
                $index,
                $lines,
                $indentSize,
            );
            [$trimmed, $index] = $this->mergeParenthesizedDeclaration($trimmed, $level, $index, $lines, $indentSize);
            [$trimmed, $index] = $this->mergeBracketedDeclaration($trimmed, $level, $index, $lines, $indentSize);
            [$trimmed, $index] = $this->mergeDirectiveHeader($trimmed, $level, $index, $lines, $indentSize);
            [$trimmed, $index] = $this->mergeBareSingleLineDirective($trimmed, $level, $index, $lines, $indentSize);
            [$trimmed, $index] = $this->mergeOperatorContinuation($trimmed, $level, $index, $lines, $indentSize);
            [$trimmed, $index] = $this->mergeSingleLineDirectiveParenthesizedCall(
                $trimmed,
                $level,
                $index,
                $lines,
                $indentSize,
            );
            [$trimmed, $index] = $this->mergeBlockHeaderParenContinuation(
                $trimmed,
                $level,
                $index,
                $lines,
                $indentSize,
            );
            [$trimmed, $index] = $this->mergeInterpolationContinuation(
                $trimmed,
                $level,
                $index,
                $lines,
                $indentSize,
            );

            [$trimmed, $index] = $this->mergeSelectorCommaContinuation(
                $trimmed,
                $level,
                $index,
                $lines,
                $indentSize,
            );

            if (
                str_ends_with(rtrim($trimmed), ',')
                && ! $this->isSingleLineDirective($trimmed)
                && ! $this->isTrailingCommaDirectiveHeader($trimmed, $level, $index, $lines, $indentSize)
            ) {
                $result[] = $this->indent($level, $indentSize) . rtrim($trimmed);

                continue;
            }

            if ($trimmed === '=' && $this->nextIndentedLineHasContent($lines, $index, $level, $indentSize)) {
                $result[] = $this->indent($level, $indentSize) . '@mixin ' . ltrim($lines[$index + 1]) . ' {';
                $stack[]  = ['level' => $level, 'spaces' => $leadingSpaces];

                $index++;

                continue;
            }

            if ($this->isMixinDefinitionLine($trimmed)) {
                $result[] = $this->indent($level, $indentSize) . '@mixin ' . substr($trimmed, 1) . ' {';
                $stack[]  = ['level' => $level, 'spaces' => $leadingSpaces];
            } elseif ($this->isMixinIncludeLine($trimmed)) {
                if ($this->nextIndentedLineHasContent($lines, $index, $level, $indentSize)) {
                    $result[] = $this->indent($level, $indentSize) . '@include ' . substr($trimmed, 1) . ' {';
                    $stack[]  = ['level' => $level, 'spaces' => $leadingSpaces];
                } else {
                    $result[] = $this->indent($level, $indentSize) . '@include ' . substr($trimmed, 1) . ';';
                }
            } elseif ($this->isSingleLineDirective($trimmed)) {
                $endsWithSemicolon = str_ends_with(rtrim($trimmed), ';');

                $result[] = $this->indent($level, $indentSize) . $trimmed . ($endsWithSemicolon ? '' : ';');
            } elseif ($this->startsWithAtKeyword($trimmed, ['media'])) {
                $result[] = $this->indent($level, $indentSize) . $this->ensureBlockHeaderHasOpeningBrace($trimmed);

                $stack[] = ['level' => $level, 'spaces' => $leadingSpaces];
            } elseif ($this->isUnknownAtRule($trimmed) && ! $this->nextIndentedLineHasContent($lines, $index, $level, $indentSize)) {
                $result[] = $this->indent($level, $indentSize) . $trimmed . ';';
            } elseif ($this->isBlockHeader($trimmed)) {
                $header = $this->ensureBlockHeaderHasOpeningBrace($trimmed);

                if ($trimmed === '@include') {
                    $nextLine    = $lines[$index + 1] ?? '';
                    $nextTrimmed = ltrim($nextLine);

                    if ($nextTrimmed !== '' && $this->lineLevel($nextLine, $indentSize) > $level) {
                        $header = '@include ' . $nextTrimmed . '{';

                        $index++;
                    }
                }

                $result[] = $this->indent($level, $indentSize) . $header;
                $stack[]  = ['level' => $level, 'spaces' => $leadingSpaces];
            } elseif ($this->hasNestedPropertyChildren($trimmed, $level, $index, $lines, $indentSize)) {
                $result[] = $this->indent($level, $indentSize) . $trimmed . ' {';
                $stack[]  = ['level' => $level, 'spaces' => $leadingSpaces];
            } else {
                $result[] = $this->indent($level, $indentSize) . rtrim($trimmed, ';') . ';';
            }
        }

        while (! empty($stack)) {
            /** @var array{level: int, spaces: int} $block */
            $block = array_pop($stack);

            $result[] = $this->indent($block['level'], $indentSize) . '}';
        }

        return implode($eol, $result);
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: list<string>, 1: int}
     */
    private function collectLoudComment(int $index, array $lines, int $level, int $indentSize): array
    {
        $first  = rtrim($lines[$index], "\r\n");
        $inBase = strlen($first) - strlen(ltrim($first));
        $head   = rtrim($first);
        $prefix = $this->indent($level, $indentSize);
        $max    = count($lines);

        if ($this->loudCommentHeadIsEmpty(ltrim($head)) && $index + 1 < $max) {
            $next = ltrim(rtrim($lines[$index + 1], "\r\n"));

            if ($next !== '') {
                $head .= ' ' . $next;

                $index++;
            }
        }

        $collected = [$prefix . ltrim($head)];

        $closePos = strpos(ltrim($head), '*/');

        if ($closePos !== false) {
            $collected = [$prefix . $this->stripCommentAfterLoudCommentClose(ltrim($head), $closePos)];

            return [$collected, $index];
        }

        while ($index + 1 < $max) {
            $line = rtrim($lines[$index + 1], "\r\n");

            if (trim($line) === '') {
                if (! $this->hasDeeperLoudCommentLineAhead($lines, $index + 1, $inBase)) {
                    break;
                }
            } elseif ($this->leadingSpaceCount($line) <= $inBase) {
                break;
            }

            $index++;

            /** @var string $previous */
            $previous = end($collected);

            if ($this->hasUnclosedInterpolation($previous)) {
                array_pop($collected);

                $line        = $previous . ' ' . ltrim($line);
                $collected[] = $line;
            } else {
                $collected[] = $this->reindentLoudCommentLine($line, $inBase, $prefix, $indentSize);
            }

            if (str_contains($line, '*/')) {
                return [$collected, $index];
            }
        }

        /** @var string $last */
        $last = array_pop($collected);

        $collected[] = rtrim($last) . ' */';

        return [$collected, $index];
    }

    private function stripCommentAfterLoudCommentClose(string $head, int $closePos): string
    {
        $tail      = substr($head, $closePos + 2);
        $tailStart = ltrim($tail);

        if (! str_starts_with($tailStart, '/*') && ! str_starts_with($tailStart, '//')) {
            return $head;
        }

        return substr($head, 0, $closePos + 2);
    }

    private function reindentLoudCommentLine(string $line, int $inBase, string $prefix, int $indentSize): string
    {
        $body = ltrim($line);
        $star = $inBase === 0 ? ' *' : '*';

        if ($body === '') {
            return $prefix . $star;
        }

        $relative = strlen($line) - strlen($body) - $inBase;
        $padding  = $relative - $indentSize;

        if ($padding < 1) {
            $padding = 1;
        }

        return $prefix . $star . str_repeat(' ', $padding) . $body;
    }

    /**
     * @param array<int, string> $lines
     */
    private function hasDeeperLoudCommentLineAhead(array $lines, int $index, int $inBase): bool
    {
        $max = count($lines);

        for ($cursor = $index; $cursor < $max; $cursor++) {
            $line = rtrim($lines[$cursor], "\r\n");

            if (trim($line) === '') {
                continue;
            }

            return $this->leadingSpaceCount($line) > $inBase;
        }

        return false;
    }

    private function loudCommentHeadIsEmpty(string $trimmed): bool
    {
        $body = str_starts_with($trimmed, '/*!') ? substr($trimmed, 3) : substr($trimmed, 2);

        return trim($body) === '';
    }

    /**
     * @param list<string> $result
     * @param list<string> $pendingEmptyLines
     */
    private function flushPendingEmptyLines(array &$result, array &$pendingEmptyLines): void
    {
        foreach ($pendingEmptyLines as $empty) {
            $result[] = $empty;
        }

        $pendingEmptyLines = [];
    }

    private function validateSyntax(string $source): void
    {
        $this->validateDirectiveHeaderContinuations($source);

        $line                = 1;
        $length              = strlen($source);
        $parenStack          = [];
        $inSingleLineComment = false;
        $inMultilineComment  = false;
        $stringQuote         = null;
        $stringStartLine     = 0;
        $escaped             = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            $next = $i + 1 < $length ? $source[$i + 1] : null;

            if ($char === "\n") {
                $line++;

                $inSingleLineComment = false;
            }

            if ($inSingleLineComment) {
                continue;
            }

            if ($inMultilineComment) {
                if ($char === '*' && $next === '/') {
                    $inMultilineComment = false;

                    $i++;
                }

                continue;
            }

            if ($stringQuote !== null) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === $stringQuote) {
                    $stringQuote = null;
                }

                continue;
            }

            if ($char === '/' && $next === '/') {
                $inSingleLineComment = true;

                $i++;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $inMultilineComment = true;

                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $stringQuote     = $char;
                $stringStartLine = $line;

                continue;
            }

            if ($char === '(') {
                $parenStack[] = $line;

                continue;
            }

            if ($char === ')') {
                if ($parenStack === []) {
                    throw InvalidSyntaxException::unexpectedClosingParenthesis($line);
                }

                array_pop($parenStack);
            }
        }

        if ($stringQuote !== null) {
            throw InvalidSyntaxException::unterminatedString($stringStartLine);
        }

        if ($parenStack !== []) {
            throw InvalidSyntaxException::expectedClosingParenthesis(end($parenStack));
        }
    }

    private function validateDirectiveHeaderContinuations(string $source): void
    {
        $lines = $this->splitByLineBreaks($source);
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line    = rtrim($lines[$index], "\r\n");
            $trimmed = ltrim($line);

            if (! in_array(rtrim($trimmed), self::DIRECTIVE_HEADER_KEYWORDS, true)) {
                continue;
            }

            $lineLevel = $this->leadingSpaceCount($line);

            if ($index + 1 >= $count || trim($lines[$index + 1]) !== '') {
                continue;
            }

            for ($nextIndex = $index + 2; $nextIndex < $count; $nextIndex++) {
                $nextLine    = rtrim($lines[$nextIndex], "\r\n");
                $nextTrimmed = ltrim($nextLine);

                if ($nextTrimmed === '') {
                    continue;
                }

                if (
                    $this->leadingSpaceCount($nextLine) > $lineLevel
                    && $this->looksLikeDirectiveHeaderContinuation($nextTrimmed)
                ) {
                    throw InvalidSyntaxException::separatedDirectiveHeaderContinuation($index + 1, rtrim($trimmed));
                }

                break;
            }
        }
    }

    private function indent(int $level, int $indentSize): string
    {
        return str_repeat(' ', $level * $indentSize);
    }

    private function stripLeadingEscape(string $line): string
    {
        if (! str_starts_with($line, '\\')) {
            return $line;
        }

        $next = $line[1] ?? '';

        if ($next === '' || ctype_alnum($next) || $next === '_' || $next === ' ') {
            return $line;
        }

        return substr($line, 1);
    }

    private function stripTrailingLineComment(string $line): string
    {
        if ($this->isCustomPropertyDeclaration($line)) {
            return $line;
        }

        $position = $this->findSilentCommentStart($line);

        if ($position !== null) {
            return $position === 0 ? $line : rtrim(substr($line, 0, $position));
        }

        return $this->stripTrailingLoudComment($line);
    }

    private function stripTrailingLoudComment(string $line): string
    {
        $trimmed = rtrim($line);

        if (! str_ends_with($trimmed, '*/')) {
            return $line;
        }

        $start = $this->findLastLoudCommentStart($trimmed);

        if ($start === null || $start === 0) {
            return $line;
        }

        $head = rtrim(substr($trimmed, 0, $start));

        return $head === '' ? $line : $head;
    }

    private function findLastLoudCommentStart(string $line): ?int
    {
        $length  = strlen($line);
        $quote   = null;
        $escaped = false;
        $start   = null;

        for ($index = 0; $index < $length; $index++) {
            $char = $line[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char !== '/' || ($line[$index + 1] ?? '') !== '*') {
                continue;
            }

            $start = $index;
            $index += 2;

            while ($index < $length) {
                if ($line[$index] === '*' && ($line[$index + 1] ?? '') === '/') {
                    $index++;

                    break;
                }

                $index++;
            }
        }

        return $start;
    }

    private function findSilentCommentStart(string $line): ?int
    {
        $length  = strlen($line);
        $quote   = null;
        $inLoud  = false;
        $escaped = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $line[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($inLoud) {
                if ($char === '*' && ($line[$index + 1] ?? '') === '/') {
                    $inLoud = false;

                    $index++;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '(' && $this->isUrlCallEnd($line, $index)) {
                $index = $this->skipToMatchingParenthesis($line, $index);

                continue;
            }

            if ($char !== '/') {
                continue;
            }

            $next = $line[$index + 1] ?? '';

            if ($next === '*') {
                $inLoud = true;

                $index++;

                continue;
            }

            if ($next === '/') {
                return $index;
            }
        }

        return null;
    }

    private function isUrlCallEnd(string $line, int $parenIndex): bool
    {
        $end = $parenIndex;

        while ($end > 0) {
            $char = $line[$end - 1];

            if (! ctype_alnum($char) && $char !== '-' && $char !== '_') {
                break;
            }

            $end--;
        }

        return strtolower(substr($line, $end, $parenIndex - $end)) === 'url';
    }

    private function skipToMatchingParenthesis(string $line, int $parenIndex): int
    {
        $length = strlen($line);
        $depth  = 0;

        for ($index = $parenIndex; $index < $length; $index++) {
            $char = $line[$index];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return $length;
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeOperatorContinuation(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $candidate = rtrim($trimmed);

        if (! $this->endsWithContinuationOperator($candidate)) {
            return [$trimmed, $index];
        }

        if (
            strlen($candidate) === 1
            && $this->nextIndentedLineHasContent($lines, $index, $level, $indentSize)
        ) {
            return [$trimmed, $index];
        }

        $max = count($lines);

        while ($index + 1 < $max) {
            $nextLine    = rtrim($lines[$index + 1], "\r\n");
            $nextTrimmed = ltrim($nextLine);

            if ($nextTrimmed === '') {
                break;
            }

            $leadingSpaces = strlen($nextLine) - strlen($nextTrimmed);
            $nextLevel     = intdiv($leadingSpaces, $indentSize);

            if ($nextLevel < $level) {
                break;
            }

            if ($nextLevel === $level && ! str_contains($candidate, ':')) {
                break;
            }

            $candidate .= ' ' . $nextTrimmed;

            $index++;

            if (! $this->endsWithContinuationOperator($candidate)) {
                break;
            }
        }

        return [$candidate, $index];
    }

    private function endsWithContinuationOperator(string $line): bool
    {
        $last = $line[strlen($line) - 1] ?? '';

        if ($last === '+' || $last === '-' || $last === '*' || $last === '/') {
            if ($line === '--') {
                return false;
            }

            return true;
        }

        if ($last === '!' && str_contains($line, ':')) {
            return true;
        }

        foreach ([' not', ' and', ' or', ' %'] as $suffix) {
            if (str_ends_with($line, $suffix) || $line === trim($suffix)) {
                return true;
            }
        }

        return false;
    }

    private function hasUnclosedInterpolation(string $text): bool
    {
        return substr_count($text, '#{') > substr_count($text, '}');
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeEscapedNewlineContinuation(string $trimmed, int $index, array $lines): array
    {
        $candidate = $trimmed;
        $max       = count($lines);

        while (str_ends_with($candidate, '\\') && $index + 1 < $max) {
            $candidate .= "\n" . rtrim($lines[$index + 1], "\r\n");

            $index++;
        }

        return [$candidate, $index];
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeCustomPropertyDeclaration(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        if (! $this->isCustomPropertyDeclaration($trimmed)) {
            return [$trimmed, $index];
        }

        $candidate = rtrim($trimmed);
        $max       = count($lines);

        while ($this->hasUnclosedGroup($candidate) && $index + 1 < $max) {
            $nextLine = rtrim($lines[$index + 1], "\r\n");

            if (ltrim($nextLine) === '') {
                break;
            }

            $candidate .= "\n" . rtrim($nextLine);

            $index++;
        }

        return [$candidate, $index];
    }

    private function isCustomPropertyDeclaration(string $line): bool
    {
        if (! str_starts_with($line, '--')) {
            return false;
        }

        $colon = strpos($line, ':');

        return $colon !== false && $colon >= 2;
    }

    /**
     * @param array<int, string> $lines
     */
    private function isTrailingCommaDirectiveHeader(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): bool {
        if (! str_starts_with($trimmed, '@')) {
            return false;
        }

        return $this->nextIndentedLineHasContent($lines, $index, $level, $indentSize);
    }

    /**
     * @param array<int, string> $lines
     */
    private function hasNestedPropertyChildren(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): bool {
        if (
            str_starts_with($trimmed, '@')
            || str_starts_with($trimmed, '$')
            || str_ends_with(rtrim($trimmed), ';')
            || $this->isCustomPropertyDeclaration($trimmed)
            || str_contains($trimmed, "\n")
        ) {
            return false;
        }

        if ($this->findFirstColon($trimmed) === null) {
            return false;
        }

        if (! $this->nextIndentedLineHasContent($lines, $index, $level, $indentSize)) {
            return false;
        }

        return $this->isNestedPropertyChild(ltrim($lines[$index + 1] ?? ''));
    }

    private function isNestedPropertyChild(string $line): bool
    {
        if ($line === '' || str_starts_with($line, '$')) {
            return false;
        }

        if (str_starts_with($line, '@') || $this->isMixinIncludeLine($line)) {
            return true;
        }

        if (in_array($line[0], self::BLOCK_HEADER_CHARS, true)) {
            return false;
        }

        return $this->findFirstColon($line) !== null;
    }

    private function hasUnclosedGroup(string $line): bool
    {
        $depth   = 0;
        $length  = strlen($line);
        $quote   = null;
        $escaped = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $line[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            }
        }

        return $depth > 0;
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeParenthesizedDeclaration(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $candidate = rtrim($trimmed);

        if (! str_contains($candidate, ':')) {
            return [$trimmed, $index];
        }

        $hasOpenParen = str_ends_with($candidate, '(') || $this->parenthesisBalance($candidate) > 0;

        if (! $hasOpenParen) {
            return [$trimmed, $index];
        }

        return $this->mergeParenthesizedContinuation($candidate, $level, $index, $lines, $indentSize);
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeBracketedDeclaration(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $candidate = rtrim($trimmed);

        if ($this->bracketBalance($candidate) <= 0) {
            return [$trimmed, $index];
        }

        if (! str_contains($candidate, ':') && str_starts_with($candidate, '@')) {
            return [$trimmed, $index];
        }

        return $this->mergeBracketedContinuation($candidate, $level, $index, $lines, $indentSize);
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeDirectiveHeader(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $header = rtrim($trimmed);

        if (! $this->isDirectiveHeaderCandidate($header)) {
            return [$trimmed, $index];
        }

        $consumed = false;

        $max = count($lines);

        while ($index + 1 < $max) {
            $nextLine    = rtrim($lines[$index + 1], "\r\n");
            $nextTrimmed = ltrim($nextLine);

            if ($nextTrimmed === '') {
                if ($consumed) {
                    break;
                }

                throw InvalidSyntaxException::incompleteDirectiveHeader($index + 1, $header);
            }

            $leadingSpaces = strlen($nextLine) - strlen($nextTrimmed);
            $nextLevel     = intdiv($leadingSpaces, $indentSize);

            $isPlainTail = $this->headerEndsWithIn($header) && ! str_contains($nextTrimmed, ':');

            if ($nextLevel <= $level || ! ($this->looksLikeDirectiveHeaderContinuation($nextTrimmed) || $isPlainTail)) {
                break;
            }

            $header .= ' ' . $nextTrimmed;

            $consumed = true;

            $index++;
        }

        return [$header, $index];
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeBareSingleLineDirective(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $candidate = rtrim($trimmed);

        if (! $this->isBareSingleLineDirective($candidate)) {
            return [$trimmed, $index];
        }

        $max = count($lines);

        while ($index + 1 < $max) {
            $nextLine    = rtrim($lines[$index + 1], "\r\n");
            $nextTrimmed = ltrim($nextLine);

            if ($nextTrimmed === '' || $this->lineLevel($nextLine, $indentSize) <= $level) {
                break;
            }

            $candidate .= ' ' . $nextTrimmed;

            $index++;
        }

        return [$candidate, $index];
    }

    private function isBareSingleLineDirective(string $line): bool
    {
        return $this->isSingleLineDirective($line)
            && ! str_contains($line, ' ')
            && ! str_contains($line, "\t");
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeSingleLineDirectiveParenthesizedCall(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $candidate = rtrim($trimmed);

        if (! $this->isSingleLineDirective($candidate) || $this->parenthesisBalance($candidate) <= 0) {
            return [$trimmed, $index];
        }

        return $this->mergeParenthesizedContinuation($candidate, $level, $index, $lines, $indentSize);
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeBlockHeaderParenContinuation(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $candidate = rtrim($trimmed);

        if ($this->parenthesisBalance($candidate) <= 0 || ! $this->isBlockHeader($candidate)) {
            return [$trimmed, $index];
        }

        $preserveLineBreaks = $this->startsWithAtKeyword($candidate, ['supports']);

        $depth = $this->parenthesisBalance($candidate);
        $line  = $index + 1;
        $max   = count($lines);

        while ($depth > 0 && $index + 1 < $max) {
            $nextLine = rtrim($lines[$index + 1], "\r\n");

            if (trim($nextLine) === '') {
                throw InvalidSyntaxException::expectedClosingParenthesis($line);
            }

            if ($preserveLineBreaks) {
                $candidate .= "\n" . $nextLine;
            } else {
                $nextTrimmed = ltrim($nextLine);

                $candidate .= $this->parenthesizedContinuationSeparator($candidate, $nextTrimmed) . $nextTrimmed;
            }

            $depth += $this->parenthesisBalance($nextLine);

            $index++;
        }

        return [$candidate, $index];
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeSelectorCommaContinuation(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $candidate = rtrim($trimmed);

        if (! str_ends_with($candidate, ',') || str_contains($candidate, ':') || str_starts_with($candidate, '@')) {
            return [$trimmed, $index];
        }

        $max = count($lines);

        while (str_ends_with($candidate, ',') && $index + 1 < $max) {
            $nextLine    = rtrim($lines[$index + 1], "\r\n");
            $nextTrimmed = ltrim($nextLine);

            if ($nextTrimmed === '') {
                break;
            }

            $leadingSpaces = strlen($nextLine) - strlen($nextTrimmed);

            if (intdiv($leadingSpaces, $indentSize) <= $level) {
                break;
            }

            $candidate .= "\n" . $nextTrimmed;

            $index++;
        }

        return [$candidate, $index];
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeInterpolationContinuation(
        string $trimmed,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $candidate = rtrim($trimmed);

        if (! $this->hasUnclosedInterpolation($candidate)) {
            return [$trimmed, $index];
        }

        $max = count($lines);

        while ($this->hasUnclosedInterpolation($candidate) && $index + 1 < $max) {
            $nextLine    = rtrim($lines[$index + 1], "\r\n");
            $nextTrimmed = ltrim($nextLine);

            if ($nextTrimmed === '') {
                break;
            }

            $leadingSpaces = strlen($nextLine) - strlen($nextTrimmed);

            if (intdiv($leadingSpaces, $indentSize) <= $level) {
                break;
            }

            $candidate .= $this->interpolationContinuationSeparator($candidate, $nextTrimmed) . $nextTrimmed;

            $index++;
        }

        return [$candidate, $index];
    }

    private function interpolationContinuationSeparator(string $merged, string $continuation): string
    {
        if (str_ends_with($merged, '#{') || str_starts_with($continuation, '}')) {
            return '';
        }

        return ' ';
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeParenthesizedContinuation(
        string $merged,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $depth = $this->parenthesisBalance($merged);
        $line  = $index + 1;
        $max   = count($lines);

        while ($depth > 0 && $index + 1 < $max) {
            $nextTrimmed = trim($lines[$index + 1]);

            $index++;

            if ($nextTrimmed === '') {
                continue;
            }

            $merged .= $this->parenthesizedContinuationSeparator($merged, $nextTrimmed) . $nextTrimmed;

            $depth += $this->parenthesisBalance($nextTrimmed);
        }

        if ($depth > 0 || $this->isEmptyParenthesizedValue($merged)) {
            throw InvalidSyntaxException::expectedClosingParenthesis($line);
        }

        return [$merged, $index];
    }

    private function isEmptyParenthesizedValue(string $merged): bool
    {
        $colon = $this->findFirstColon($merged);

        if ($colon === null) {
            return false;
        }

        return trim(substr($merged, $colon + 1)) === '()';
    }

    private function parenthesizedContinuationSeparator(string $merged, string $continuation): string
    {
        if (str_ends_with($merged, '(') || str_starts_with($continuation, ')')) {
            return '';
        }

        return ' ';
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: int}
     */
    private function mergeBracketedContinuation(
        string $merged,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $depth = $this->bracketBalance($merged);

        $selectorLike = ! str_contains($merged, ':') && ! str_contains($merged, '@');

        $max = count($lines);

        while ($depth > 0 && $index + 1 < $max) {
            $nextLine    = rtrim($lines[$index + 1], "\r\n");
            $nextTrimmed = ltrim($nextLine);

            if ($nextTrimmed === '') {
                break;
            }

            $leadingSpaces = strlen($nextLine) - strlen($nextTrimmed);
            $nextLevel     = intdiv($leadingSpaces, $indentSize);

            if (! $selectorLike && $nextLevel <= $level) {
                break;
            }

            $merged .= $this->bracketedContinuationSeparator($merged, $nextTrimmed) . $nextTrimmed;

            $depth += $this->bracketBalance($nextTrimmed);

            $index++;
        }

        return [$merged, $index];
    }

    private function looksLikeDirectiveHeaderContinuation(string $line): bool
    {
        if (str_starts_with($line, '$') || ctype_digit($line[0]) || str_starts_with($line, ',')) {
            return true;
        }

        foreach (self::DIRECTIVE_CONTINUATION_KEYWORDS as $keyword) {
            if ($line === $keyword || str_starts_with($line, $keyword . ' ')) {
                return true;
            }
        }

        return false;
    }

    private function isDirectiveHeaderCandidate(string $header): bool
    {
        if (in_array($header, self::DIRECTIVE_HEADER_KEYWORDS, true)) {
            return true;
        }

        if ($this->startsWithAtKeyword($header, ['for'])) {
            return ! $this->isCompleteForHeader($header);
        }

        if ($this->startsWithAtKeyword($header, ['each'])) {
            return ! $this->isCompleteEachHeader($header);
        }

        return false;
    }

    private function isCompleteForHeader(string $header): bool
    {
        $trimmed = rtrim($this->stripTrailingComment($header));
        $pos     = strrpos($trimmed, ' ');
        $last    = $pos === false ? $trimmed : substr($trimmed, $pos + 1);

        if (! ctype_digit($last[0] ?? '')) {
            return false;
        }

        return str_contains($trimmed, 'from')
            && (str_contains($trimmed, 'through') || str_contains($trimmed, ' to'));
    }

    private function stripTrailingComment(string $line): string
    {
        $trimmed   = rtrim($line);
        $silentPos = strpos($trimmed, '//');

        if ($silentPos !== false) {
            return rtrim(substr($trimmed, 0, $silentPos));
        }

        if (! str_ends_with($trimmed, '*/')) {
            return $trimmed;
        }

        $loudStart = strrpos($trimmed, '/*');

        return $loudStart === false ? $trimmed : rtrim(substr($trimmed, 0, $loudStart));
    }

    private function isCompleteEachHeader(string $header): bool
    {
        $trimmed = rtrim($header);

        return str_contains($trimmed, 'in')
            && ! str_ends_with($trimmed, 'in')
            && ! str_ends_with($trimmed, 'in,');
    }

    private function headerEndsWithIn(string $header): bool
    {
        $trimmed = rtrim($header);

        return $trimmed === 'in' || str_ends_with($trimmed, ' in');
    }

    private function isMixinDefinitionLine(string $line): bool
    {
        return $line !== '' && $line[0] === '=' && ltrim(substr($line, 1)) !== '';
    }

    private function isMixinIncludeLine(string $line): bool
    {
        $second = $line[1] ?? '';

        return $line !== '' && $line[0] === '+' && $second !== '' && $second !== ' ' && $second !== "\t";
    }

    private function lineLevel(string $line, int $indentSize): int
    {
        $trimmed = ltrim($line);

        return intdiv(strlen($line) - strlen($trimmed), $indentSize);
    }

    /**
     * @param array<int, string> $lines
     */
    private function nextIndentedLineHasContent(array $lines, int $index, int $level, int $indentSize): bool
    {
        $nextLine = $lines[$index + 1] ?? '';

        return ltrim($nextLine) !== '' && $this->lineLevel($nextLine, $indentSize) > $level;
    }

    private function parenthesisBalance(string $line): int
    {
        return substr_count($line, '(') - substr_count($line, ')');
    }

    private function bracketBalance(string $line): int
    {
        return substr_count($line, '[') - substr_count($line, ']');
    }

    private function bracketedContinuationSeparator(string $merged, string $continuation): string
    {
        $last = $merged[strlen($merged) - 1];

        if ($last === '[' || $continuation[0] === ']') {
            return '';
        }

        return ' ';
    }

    private function detectLineEnding(string $source): string
    {
        $patterns = [
            "\r\n" => "\r\n",
            "\r"   => "\r",
        ];

        foreach ($patterns as $search => $replace) {
            if (str_contains($source, $search)) {
                return $replace;
            }
        }

        return "\n";
    }

    /**
     * @param array<int, string> $lines
     */
    private function detectIndentSize(array $lines): int
    {
        $sizes = [2];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $leadingSpaces = $this->leadingSpaceCount($line);

            if ($leadingSpaces > 0) {
                $sizes[] = $leadingSpaces;
            }
        }

        sort($sizes);

        return $sizes[0];
    }

    private function isSingleLineDirective(string $line): bool
    {
        return $this->startsWithAtKeyword($line, self::SINGLE_LINE_DIRECTIVES);
    }

    private function isUnknownAtRule(string $line): bool
    {
        return str_starts_with($line, '@')
            && ! $this->startsWithAtKeyword($line, self::BLOCK_HEADER_DIRECTIVES)
            && ! $this->startsWithAtKeyword($line, ['media']);
    }

    private function isBlockHeader(string $line): bool
    {
        if ($this->startsWithAtKeyword($line, self::BLOCK_HEADER_DIRECTIVES)) {
            return true;
        }

        if ($line !== '' && in_array($line[0], self::BLOCK_HEADER_CHARS, true)) {
            return true;
        }

        if (str_ends_with($line, ':')) {
            return true;
        }

        return ! str_contains($line, ':')
            || $this->containsPseudoClass($line)
            || $this->looksLikeIndentedPseudoSelector($line);
    }

    private function looksLikeIndentedPseudoSelector(string $line): bool
    {
        if (str_starts_with($line, '@') || str_starts_with($line, '--')) {
            return false;
        }

        $colon = $this->findFirstColon($line);

        if ($colon === null) {
            return false;
        }

        return $colon === 0 || $this->isIdentifierStartAt($line, $colon + 1);
    }

    private function findFirstColon(string $line): ?int
    {
        $length  = strlen($line);
        $quote   = null;
        $escaped = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $line[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === ':') {
                return $index;
            }
        }

        return null;
    }

    private function isIdentifierStartAt(string $line, int $position): bool
    {
        $char = $line[$position] ?? '';

        if ($char === '#') {
            return ($line[$position + 1] ?? '') === '{';
        }

        return $char !== ''
            && (ctype_alpha($char)
                || $char === '_'
                || $char === '-'
                || $char === '\\'
                || ord($char) >= 0x80);
    }

    private function ensureBlockHeaderHasOpeningBrace(string $line): string
    {
        return str_ends_with(rtrim($line), '{') ? rtrim($line) : $line . ' {';
    }

    /**
     * @return array<int, string>
     */
    private function splitByLineBreaks(string $source): array
    {
        $lines   = [];
        $current = '';
        $length  = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === "\r") {
                $lines[] = $current;
                $current = '';

                if ($i + 1 < $length && $source[$i + 1] === "\n") {
                    $i++;
                }

                continue;
            }

            if ($char === "\n") {
                $lines[] = $current;
                $current = '';

                continue;
            }

            if ($char === "\f") {
                $lines[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $lines[] = $current;

        return $lines;
    }

    private function leadingSpaceCount(string $line): int
    {
        $length = strlen($line);
        $count  = 0;

        while ($count < $length && ($line[$count] === ' ' || $line[$count] === "\t")) {
            $count++;
        }

        return $count;
    }

    /**
     * @param array<int, string> $keywords
     */
    private function startsWithAtKeyword(string $line, array $keywords): bool
    {
        if (! str_starts_with($line, '@')) {
            return false;
        }

        $rest = substr($line, 1);

        foreach ($keywords as $keyword) {
            if (! str_starts_with($rest, $keyword)) {
                continue;
            }

            $length = strlen($keyword);

            if (strlen($rest) === $length) {
                return true;
            }

            $next = $rest[$length];

            if (! ctype_alnum($next) && $next !== '_') {
                return true;
            }
        }

        return false;
    }

    private function containsPseudoClass(string $line): bool
    {
        foreach (self::PSEUDO_CLASSES as $pseudoClass) {
            $needle   = ':' . $pseudoClass;
            $position = 0;

            while (($position = strpos($line, $needle, $position)) !== false) {
                $nextIndex = $position + strlen($needle);

                if ($nextIndex >= strlen($line)) {
                    return true;
                }

                $next = $line[$nextIndex];

                if (! ctype_alnum($next) && $next !== '_' && $next !== '-') {
                    return true;
                }

                $position++;
            }
        }

        return false;
    }
}
