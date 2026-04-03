<?php

declare(strict_types=1);

namespace Bugo\SCSS\Normalizers;

use Bugo\SCSS\Syntax;

use function array_pop;
use function ctype_alnum;
use function ctype_digit;
use function end;
use function implode;
use function in_array;
use function intdiv;
use function ltrim;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strpos;
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
        $eol        = $this->detectLineEnding($source);
        $lines      = $this->splitByLineBreaks($source);
        $indentSize = $this->detectIndentSize($lines);

        /** @var list<string> $result */
        $result = [];

        /** @var list<array{level: int}> $stack */
        $stack = [];

        $pendingEmptyLines  = [];
        $inMultilineComment = false;

        $lineCount = count($lines);

        for ($index = 0; $index < $lineCount; $index++) {
            $rawLine = $lines[$index];
            $line    = rtrim($rawLine, "\r\n");
            $trimmed = ltrim($line);

            if ($trimmed === '') {
                $pendingEmptyLines[] = '';

                continue;
            }

            if ($inMultilineComment) {
                $result[] = $line;

                if (str_contains($trimmed, '*/')) {
                    $inMultilineComment = false;
                }

                continue;
            }

            if (str_starts_with($trimmed, '/*')) {
                $this->flushPendingEmptyLines($result, $pendingEmptyLines);

                $result[] = $line;

                if (! str_contains($trimmed, '*/')) {
                    $inMultilineComment = true;
                }

                continue;
            }

            if (str_starts_with($trimmed, '//')) {
                $this->flushPendingEmptyLines($result, $pendingEmptyLines);

                $result[] = $line;

                continue;
            }

            $leadingSpaces = strlen($line) - strlen($trimmed);
            $level         = intdiv($leadingSpaces, $indentSize);

            while (! empty($stack) && end($stack)['level'] >= $level) {
                /** @var array{level: int} $block */
                $block = array_pop($stack);

                $result[] = $this->indent($block['level'], $indentSize) . '}';
            }

            if ($level === 0) {
                $this->flushPendingEmptyLines($result, $pendingEmptyLines);
            }

            $pendingEmptyLines = [];

            [$trimmed, $index] = $this->mergeParenthesizedDeclaration($trimmed, $level, $index, $lines, $indentSize);
            [$trimmed, $index] = $this->mergeDirectiveHeader($trimmed, $level, $index, $lines, $indentSize);
            [$trimmed, $index] = $this->mergeSingleLineDirectiveParenthesizedCall(
                $trimmed,
                $level,
                $index,
                $lines,
                $indentSize,
            );

            if (str_ends_with(rtrim($trimmed), ',')) {
                $result[] = $this->indent($level, $indentSize) . rtrim($trimmed);

                continue;
            }

            if (str_starts_with($trimmed, '=')) {
                $result[] = $this->indent($level, $indentSize) . '@mixin ' . substr($trimmed, 1) . ' {';

                $stack[] = ['level' => $level];
            } elseif (str_starts_with($trimmed, '+')) {
                $result[] = $this->indent($level, $indentSize) . '@include ' . substr($trimmed, 1) . ';';
            } elseif ($this->isSingleLineDirective($trimmed)) {
                $endsWithSemicolon = str_ends_with(rtrim($trimmed), ';');

                $result[] = $this->indent($level, $indentSize) . $trimmed . ($endsWithSemicolon ? '' : ';');
            } elseif ($this->startsWithAtKeyword($trimmed, ['media'])) {
                $result[] = $this->indent($level, $indentSize) . $this->ensureBlockHeaderHasOpeningBrace($trimmed);

                $stack[] = ['level' => $level];
            } elseif ($this->isBlockHeader($trimmed)) {
                $result[] = $this->indent($level, $indentSize) . $this->ensureBlockHeaderHasOpeningBrace($trimmed);

                $stack[] = ['level' => $level];
            } else {
                $result[] = $this->indent($level, $indentSize) . rtrim($trimmed, ';') . ';';
            }
        }

        while (! empty($stack)) {
            /** @var array{level: int} $block */
            $block = array_pop($stack);

            $result[] = $this->indent($block['level'], $indentSize) . '}';
        }

        return implode($eol, $result);
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

    private function indent(int $level, int $indentSize): string
    {
        return str_repeat(' ', $level * $indentSize);
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

        if (! str_contains($candidate, ':') || ! str_ends_with($candidate, '(')) {
            return [$trimmed, $index];
        }

        return $this->mergeParenthesizedContinuation($candidate, $level, $index, $lines, $indentSize);
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

        if (! in_array($header, self::DIRECTIVE_HEADER_KEYWORDS, true)) {
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

            if ($nextLevel <= $level || ! $this->looksLikeDirectiveHeaderContinuation($nextTrimmed)) {
                break;
            }

            $header .= ' ' . $nextTrimmed;
            $index++;
        }

        return [$header, $index];
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
    private function mergeParenthesizedContinuation(
        string $merged,
        int $level,
        int $index,
        array $lines,
        int $indentSize,
    ): array {
        $depth = $this->parenthesisBalance($merged);

        $max = count($lines);

        while ($depth > 0 && $index + 1 < $max) {
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

            if ($nextLevel === $level && ! str_starts_with($nextTrimmed, ')')) {
                break;
            }

            $merged .= ' ' . $nextTrimmed;

            $depth += $this->parenthesisBalance($nextTrimmed);

            $index++;
        }

        return [$merged, $index];
    }

    private function looksLikeDirectiveHeaderContinuation(string $line): bool
    {
        if ($line === '') {
            return false;
        }

        if (str_starts_with($line, '$') || ctype_digit($line[0])) {
            return true;
        }

        foreach (self::DIRECTIVE_CONTINUATION_KEYWORDS as $keyword) {
            if ($line === $keyword || str_starts_with($line, $keyword . ' ')) {
                return true;
            }
        }

        return false;
    }

    private function parenthesisBalance(string $line): int
    {
        return substr_count($line, '(') - substr_count($line, ')');
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

        return ! str_contains($line, ':') || $this->containsPseudoClass($line);
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
