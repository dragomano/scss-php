<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function array_diff;
use function count;
use function ctype_alnum;
use function ctype_alpha;
use function ctype_xdigit;
use function implode;
use function max;
use function ord;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * A parsed CSS media query: `[modifier] [type] [and condition]...` or just conditions.
 *
 * @phpstan-type Condition string
 */
final readonly class MediaQuery
{
    /**
     * @param list<string> $conditions
     */
    public function __construct(
        public ?string $modifier,
        public ?string $type,
        public array $conditions,
    ) {}

    /**
     * @return list<self>|null null when the prelude can't be parsed
     */
    public static function parseList(string $prelude): ?array
    {
        $parts = self::splitTopLevel($prelude, ',');
        $queries = [];

        foreach ($parts as $part) {
            $query = self::parseQuery(StringHelper::trimPreservingEscapeTerminator($part));

            if ($query === null) {
                return null;
            }

            $queries[] = $query;
        }

        return $queries === [] ? null : $queries;
    }

    /**
     * @param list<self> $queries
     */
    public static function serializeList(array $queries): string
    {
        $serialized = [];

        foreach ($queries as $query) {
            $serialized[] = $query->toString();
        }

        return implode(', ', $serialized);
    }

    /**
     * Merges nested media queries.
     *
     * @param list<self> $outer
     * @param list<self> $inner
     * @return list<self>|null null when the intersection can't be represented,
     * an empty list when there is no matching context at all
     */
    public static function mergeLists(array $outer, array $inner): ?array
    {
        $merged = [];

        foreach ($outer as $outerQuery) {
            foreach ($inner as $innerQuery) {
                $result = $outerQuery->merge($innerQuery);

                if ($result instanceof MergeOutcome) {
                    if ($result === MergeOutcome::INVALID) {
                        return null;
                    }

                    continue;
                }

                $merged[] = $result;
            }
        }

        return $merged;
    }

    public function matchesAllTypes(): bool
    {
        return $this->type === null || strtolower($this->type) === 'all';
    }

    /**
     * @return self|MergeOutcome
     */
    public function merge(self $other): self|MergeOutcome
    {
        $ourModifier   = $this->modifier === null ? null : strtolower($this->modifier);
        $ourType       = $this->type === null ? null : strtolower($this->type);
        $theirModifier = $other->modifier === null ? null : strtolower($other->modifier);
        $theirType     = $other->type === null ? null : strtolower($other->type);

        if ($ourType === null && $theirType === null) {
            return new self(null, null, [...$this->conditions, ...$other->conditions]);
        }

        if (($ourModifier === 'not') !== ($theirModifier === 'not')) {
            if ($ourType === $theirType) {
                $negativeConditions = $ourModifier === 'not' ? $this->conditions : $other->conditions;
                $positiveConditions = $ourModifier === 'not' ? $other->conditions : $this->conditions;

                if (self::isSubsetOf($negativeConditions, $positiveConditions)) {
                    return MergeOutcome::EMPTY;
                }

                return MergeOutcome::INVALID;
            }

            if ($this->matchesAllTypes() || $other->matchesAllTypes()) {
                return MergeOutcome::INVALID;
            }

            if ($ourModifier === 'not') {
                $modifier   = $theirModifier;
                $type       = $theirType;
                $conditions = $other->conditions;
            } else {
                $modifier   = $ourModifier;
                $type       = $ourType;
                $conditions = $this->conditions;
            }
        } elseif ($ourModifier === 'not') {
            if ($ourType !== $theirType) {
                return MergeOutcome::INVALID;
            }

            if (count($this->conditions) > count($other->conditions)) {
                $moreConditions  = $this->conditions;
                $fewerConditions = $other->conditions;
            } else {
                $moreConditions  = $other->conditions;
                $fewerConditions = $this->conditions;
            }

            if (! self::isSubsetOf($fewerConditions, $moreConditions)) {
                return MergeOutcome::INVALID;
            }

            $modifier   = $ourModifier;
            $type       = $ourType;
            $conditions = $moreConditions;
        } elseif ($this->matchesAllTypes()) {
            $modifier   = $theirModifier;
            $type       = $other->matchesAllTypes() && $ourType === null ? null : $theirType;
            $conditions = [...$this->conditions, ...$other->conditions];
        } elseif ($other->matchesAllTypes()) {
            $modifier   = $ourModifier;
            $type       = $ourType;
            $conditions = [...$this->conditions, ...$other->conditions];
        } elseif ($ourType !== $theirType) {
            return MergeOutcome::EMPTY;
        } else {
            $modifier   = $ourModifier ?? $theirModifier;
            $type       = $ourType;
            $conditions = [...$this->conditions, ...$other->conditions];
        }

        return new self(
            $modifier === $ourModifier ? $this->modifier : $other->modifier,
            $type === $ourType ? $this->type : $other->type,
            $conditions,
        );
    }

    public function toString(): string
    {
        $result = '';

        if ($this->modifier !== null) {
            $result .= $this->modifier . ' ';
        }

        if ($this->type !== null) {
            $result .= $this->type;
        }

        if ($this->conditions === []) {
            return $result;
        }

        if ($result !== '') {
            $result .= ' and ';
        }

        return $result . implode(' and ', $this->conditions);
    }

    private static function parseQuery(string $query): ?self
    {
        if ($query === '') {
            return null;
        }

        if ($query[0] === '(') {
            $conditions = self::splitConditions($query);

            if ($conditions === null || $conditions === []) {
                return null;
            }

            return new self(null, null, $conditions);
        }

        $length = strlen($query);
        $first  = self::readIdentifier($query, 0);

        if ($first === null) {
            return null;
        }

        [$ident1, $pos] = $first;

        $isNot = strtolower($ident1) === 'not';
        $i     = self::skipWhitespace($query, $pos);

        if ($i >= $length) {
            return $isNot ? null : new self(null, $ident1, []);
        }

        if ($isNot && ! self::isIdentifierStart($query[$i])) {
            if ($query[$i] !== '(') {
                return null;
            }

            $conditions = self::splitConditions($query);

            if ($conditions === null || $conditions === []) {
                return null;
            }

            return new self(null, null, $conditions);
        }

        $second = self::readIdentifier($query, $i);

        if ($second === null) {
            return null;
        }

        [$ident2, $pos2] = $second;

        if (strtolower($ident2) === 'and') {
            $modifier = null;
            $type     = $ident1;
            $i        = self::skipWhitespace($query, $pos2);
        } else {
            $modifier = $ident1;
            $type     = $ident2;
            $i        = self::skipWhitespace($query, $pos2);

            if ($i >= $length) {
                return new self($modifier, $type, []);
            }

            $keywordToken = self::readIdentifier($query, $i);

            if ($keywordToken === null) {
                return null;
            }

            [$keyword, $pos3] = $keywordToken;

            if (strtolower($keyword) !== 'and') {
                return null;
            }

            $i = self::skipWhitespace($query, $pos3);
        }

        if ($i >= $length) {
            return null;
        }

        $rest = substr($query, $i);
        $conditions = self::splitConditions($rest);

        if ($conditions === null || $conditions === []) {
            return null;
        }

        return new self($modifier, $type, $conditions);
    }

    /**
     * Scans $text while tracking quotes, parens and brackets, invoking $matchBoundary
     * at each top-level position.
     *
     * @param callable(string, int, int): int $matchBoundary takes the text, current index,
     * and text length; returns the length of the matched boundary (0 if none)
     * @return list<array{0: int, 1: int}> pairs of [position, boundary length]
     */
    private static function scanTopLevelBoundaries(string $text, callable $matchBoundary): array
    {
        $length   = strlen($text);
        $points   = [];
        $i        = 0;
        $depth    = 0;
        $brackets = 0;
        $quote    = '';

        while ($i < $length) {
            $char = $text[$i];

            if ($quote !== '') {
                if ($char === '\\') {
                    $i += 2;

                    continue;
                }

                if ($char === $quote) {
                    $quote = '';
                }

                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                $i++;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === '[') {
                $brackets++;
            } elseif ($char === ']') {
                $brackets = max(0, $brackets - 1);
            } elseif ($depth === 0 && $brackets === 0) {
                $boundaryLength = $matchBoundary($text, $i, $length);

                if ($boundaryLength > 0) {
                    $points[] = [$i, $boundaryLength];

                    $i += $boundaryLength;

                    continue;
                }
            }

            $i++;
        }

        return $points;
    }

    /**
     * @return list<string>
     */
    private static function splitTopLevel(string $text, string $separator): array
    {
        $points = self::scanTopLevelBoundaries(
            $text,
            static fn(string $text, int $i, int $length): int => $text[$i] === $separator ? 1 : 0,
        );

        $parts = [];
        $start = 0;

        foreach ($points as [$point, $boundaryLength]) {
            $parts[] = substr($text, $start, $point - $start);
            $start   = $point + $boundaryLength;
        }

        $parts[] = substr($text, $start);

        return $parts;
    }

    /**
     * @return list<string>|null
     */
    private static function splitConditions(string $text): ?array
    {
        $points = self::scanTopLevelBoundaries(
            $text,
            static function (string $text, int $i, int $length): int {
                if ($text[$i] !== 'a' && $text[$i] !== 'A') {
                    return 0;
                }

                if ($i + 3 > $length || strtolower(substr($text, $i, 3)) !== 'and') {
                    return 0;
                }

                if ($i === 0 || ! self::isWhitespace($text[$i - 1])) {
                    return 0;
                }

                if ($i + 3 >= $length || ! self::isWhitespace($text[$i + 3])) {
                    return 0;
                }

                return 3;
            },
        );

        if ($points === []) {
            return [trim($text)];
        }

        $conditions = [];
        $start      = 0;

        foreach ($points as [$point, $boundaryLength]) {
            $condition = trim(substr($text, $start, $point - $start));

            if ($condition === '') {
                return null;
            }

            $conditions[] = $condition;
            $start        = $point + $boundaryLength;
        }

        $condition = trim(substr($text, $start));

        if ($condition === '') {
            return null;
        }

        $conditions[] = $condition;

        return $conditions;
    }

    /**
     * @return array{0: string, 1: int}|null the identifier and the offset after it
     */
    private static function readIdentifier(string $text, int $start): ?array
    {
        $length = strlen($text);
        $i      = $start;
        $result = '';

        if ($i >= $length || ! self::isIdentifierStart($text[$i])) {
            return null;
        }

        while ($i < $length) {
            $char = $text[$i];

            if ($char === '\\') {
                $escapeEnd = $i + 1;

                if ($escapeEnd < $length && ctype_xdigit($text[$escapeEnd])) {
                    while ($escapeEnd < $length && $escapeEnd - $i - 1 < 6 && ctype_xdigit($text[$escapeEnd])) {
                        $escapeEnd++;
                    }

                    if ($escapeEnd < $length && self::isWhitespace($text[$escapeEnd])) {
                        $escapeEnd++;
                    }
                } elseif ($escapeEnd < $length) {
                    $escapeEnd++;
                }

                $result .= substr($text, $i, $escapeEnd - $i);

                $i = $escapeEnd;

                continue;
            }

            if (ctype_alnum($char) || $char === '-' || $char === '_' || ord($char) > 127) {
                $result .= $char;

                $i++;

                continue;
            }

            break;
        }

        return [$result, $i];
    }

    private static function isIdentifierStart(string $char): bool
    {
        return ctype_alpha($char) || $char === '-' || $char === '_' || ord($char) > 127;
    }

    private static function skipWhitespace(string $text, int $start): int
    {
        $length = strlen($text);

        while ($start < $length && self::isWhitespace($text[$start])) {
            $start++;
        }

        return $start;
    }

    private static function isWhitespace(string $char): bool
    {
        return $char === ' ' || $char === "\n" || $char === "\r" || $char === "\t";
    }

    /**
     * @param list<string> $subset
     * @param list<string> $superset
     */
    private static function isSubsetOf(array $subset, array $superset): bool
    {
        return array_diff($subset, $superset) === [];
    }
}
