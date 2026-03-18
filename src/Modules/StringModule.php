<?php

declare(strict_types=1);

namespace DartSass\Modules;

use DartSass\Exceptions\CompilationException;
use DartSass\Utils\StringFormatter;
use Random\RandomException;

use function array_map;
use function array_merge;
use function is_numeric;
use function is_string;
use function max;
use function mb_strlen;
use function mb_substr;
use function min;
use function preg_match;
use function preg_quote;
use function preg_split;
use function random_int;
use function range;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;

use const PREG_SPLIT_NO_EMPTY;

class StringModule extends AbstractModule
{
    public function quote(array $args): string
    {
        [$string] = $this->validateArgs($args, 1, 'quote');

        if (! is_string($string)) {
            throw new CompilationException('quote() argument must be a string');
        }

        $unquoted = $this->unquoteString($string);

        if (str_contains($unquoted, '"')) {
            return "'" . $unquoted . "'";
        }

        if ($this->isQuoted($string)) {
            return $string;
        }

        return StringFormatter::forceQuoteString($unquoted);
    }

    public function index(array $args): ?int
    {
        [$string, $substring] = $this->validateArgs($args, 2, 'index');

        if (! is_string($string)) {
            throw new CompilationException('index() first argument must be a string');
        }

        if (! is_string($substring)) {
            throw new CompilationException('index() second argument must be a string');
        }

        $string    = $this->unquoteString($string);
        $substring = $this->unquoteString($substring);

        if ($substring === '' || $substring === '"') {
            return 1;
        }

        $pos = strpos($string, $substring);

        return $pos === false ? null : $pos + 1;
    }

    public function insert(array $args): string
    {
        [$string, $insert, $index] = $this->validateArgs($args, 3, 'insert');

        if (! is_string($string)) {
            throw new CompilationException('insert() first argument must be a string');
        }

        if (! is_string($insert)) {
            throw new CompilationException('insert() second argument must be a string');
        }

        if (! is_numeric($index)) {
            throw new CompilationException('insert() third argument must be a number');
        }

        $string = $this->unquoteString($string);
        $insert = $this->unquoteString($insert);
        $index  = (int) $index;

        if ($index < 1) {
            $index = 1;
        }

        $len = $this->length([$args[0]]);
        if ($index > $len + 1) {
            $index = $len + 1;
        }

        $pos = $index - 1;

        $before = mb_substr($string, 0, $pos, 'UTF-8');
        $after  = mb_substr($string, $pos, null, 'UTF-8');

        return StringFormatter::forceQuoteString($before . $insert . $after);
    }

    public function length(array $args): int
    {
        [$string] = $this->validateArgs($args, 1, 'length');

        if (! is_string($string)) {
            throw new CompilationException('length() argument must be a string');
        }

        return mb_strlen($this->unquoteString($string), 'UTF-8');
    }

    public function slice(array $args): string
    {
        $this->validateArgRange($args, 2, 3, 'slice');

        $string  = $args[0];
        $startAt = $args[1];
        $endAt   = $args[2] ?? -1;

        if (! is_string($string)) {
            throw new CompilationException('slice() first argument must be a string');
        }

        if (! is_numeric($startAt)) {
            throw new CompilationException('slice() second argument must be a number');
        }

        if (! is_numeric($endAt)) {
            throw new CompilationException('slice() third argument must be a number');
        }

        $string  = $this->unquoteString($string);
        $startAt = (int) $startAt;
        $endAt   = (int) $endAt;

        $len = $this->length([$args[0]]);

        if ($startAt > 0) {
            $start = $startAt - 1;
        } elseif ($startAt < 0) {
            $start = $len + $startAt;
        } else {
            $start = 0;
        }

        if ($endAt === -1) {
            $end = $len;
        } elseif ($endAt > 0) {
            $end = $endAt;
        } elseif ($endAt < 0) {
            $end = $len + $endAt;
        } else {
            $end = 0;
        }

        $start = max(0, min($start, $len));
        $end   = max(0, min($end, $len));

        if ($start >= $end) {
            $result = '';
        } else {
            $result = mb_substr($string, $start, $end - $start, 'UTF-8');
        }

        return StringFormatter::forceQuoteString($result);
    }

    public function split(array $args): array
    {
        $this->validateArgRange($args, 2, 3, 'split');

        $string    = $args[0];
        $separator = $args[1];
        $limit     = $args[2] ?? null;

        if (! is_string($string)) {
            throw new CompilationException('split() first argument must be a string');
        }

        if (! is_string($separator)) {
            throw new CompilationException('split() second argument must be a string');
        }

        if ($limit !== null && ! is_numeric($limit)) {
            throw new CompilationException('split() third argument must be a number');
        }

        $string    = $this->unquoteString($string);
        $separator = $this->unquoteString($separator);
        $limit     = $limit !== null ? (int) $limit : null;

        if ($separator === '') {
            $len = $this->length([$args[0]]);

            $result = [];
            for ($i = 0; $i < $len; $i++) {
                $result[] = $this->unquoteString($this->slice([$args[0], $i + 1, $i + 1]));
            }
        } else {
            $flags = PREG_SPLIT_NO_EMPTY;

            if ($limit !== null) {
                $result = preg_split('/' . preg_quote($separator, '/') . '/u', $string, $limit, $flags);
            } else {
                $result = preg_split('/' . preg_quote($separator, '/') . '/u', $string, -1, $flags);
            }
        }

        return array_map(StringFormatter::forceQuoteString(...), $result);
    }

    public function toUpperCase(array $args): string
    {
        [$string] = $this->validateArgs($args, 1, 'to-upper-case');

        if (! is_string($string)) {
            throw new CompilationException('to-upper-case() argument must be a string');
        }

        return $this->formatCaseTransformedString($string, strtoupper($this->unquoteString($string)));
    }

    public function toLowerCase(array $args): string
    {
        [$string] = $this->validateArgs($args, 1, 'to-lower-case');

        if (! is_string($string)) {
            throw new CompilationException('to-lower-case() argument must be a string');
        }

        return $this->formatCaseTransformedString($string, strtolower($this->unquoteString($string)));
    }

    public function uniqueId(array $args): string
    {
        $this->validateArgs($args, 0, 'unique-id');

        $letters = array_merge(range('a', 'z'), range('A', 'Z'));
        $chars   = array_merge($letters, range('0', '9'));

        try {
            $id = $letters[$this->generateRandomInt(0, 51)];

            $length = $this->generateRandomInt(6, 12);

            for ($i = 1; $i < $length; $i++) {
                $id .= $chars[$this->generateRandomInt(0, 61)];
            }
        } catch (RandomException $randomException) {
            throw new CompilationException($randomException->getMessage());
        }

        return StringFormatter::forceQuoteString($id);
    }

    public function unquote(array $args): ?string
    {
        [$string] = $this->validateArgs($args, 1, 'unquote');

        if (! is_string($string)) {
            throw new CompilationException('unquote() argument must be a string');
        }

        $result = $this->unquoteString($string);

        if ($result === '') {
            return null;
        }

        return $result;
    }

    /**
     * @throws RandomException
     */
    protected function generateRandomInt(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    private function isQuoted(string $string): bool
    {
        return (str_starts_with($string, '"') && str_ends_with($string, '"'))
            || (str_starts_with($string, "'") && str_ends_with($string, "'"));
    }

    private function unquoteString(string $string): string
    {
        $unquoted = $this->isQuoted($string) ? substr($string, 1, -1) : $string;

        // Replace escape sequences
        $unquoted = str_replace(['\\n', '\\t', '\\r', '\\\\'], ["\n", "\t", "\r", '\\'], $unquoted);

        // Replace escape characters with letters for CSS output
        return str_replace(["\n", "\t", "\r"], ['n', 't', 'r'], $unquoted);
    }

    private function formatCaseTransformedString(string $source, string $transformed): string
    {
        if ($this->shouldQuoteCaseTransformedString($source)) {
            return StringFormatter::forceQuoteString($transformed);
        }

        return $transformed;
    }

    private function shouldQuoteCaseTransformedString(string $source): bool
    {
        if ($this->isQuoted($source) || $source === '') {
            return true;
        }

        return preg_match('/^[a-zA-Z_-][a-zA-Z0-9_-]*$/', $source) !== 1;
    }
}
