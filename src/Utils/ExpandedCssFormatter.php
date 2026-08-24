<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use Bugo\SCSS\Services\Render;

use function implode;
use function max;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

final readonly class ExpandedCssFormatter
{
    public function format(string $css): string
    {
        $css = $this->flattenNestedRules($css);

        return $this->ensureBlankLinesBetweenRootRules($css);
    }

    private function flattenNestedRules(string $css): string
    {
        $result = '';

        foreach ($this->splitTopLevelStatements($css) as $statement) {
            $result .= $statement['type'] === 'rule'
                ? $this->flattenRule($statement['header'], $statement['body'], $statement['raw'])
                : $statement['raw'];

            $result .= $statement['trailing'];
        }

        return $result;
    }

    private function flattenRule(string $header, string $body, string $raw): string
    {
        if (str_starts_with(trim($header), '@')) {
            return $raw;
        }

        $statements = $this->splitTopLevelStatements($body);
        $hasNested  = false;
        $hasContent = false;

        foreach ($statements as $statement) {
            if ($statement['type'] === 'rule') {
                $hasNested = true;
            } elseif (trim($statement['raw']) !== '') {
                $hasContent = true;
            }
        }

        if (! $hasNested || $hasContent) {
            return $raw;
        }

        foreach ($statements as $statement) {
            if ($statement['type'] === 'rule' && str_starts_with($statement['header'], '@')) {
                return $raw;
            }

            if ($statement['type'] === 'rule' && ! $this->isSingleLineBlock($statement['raw'])) {
                return $raw;
            }
        }

        $chunks = [];

        foreach ($statements as $statement) {
            if ($statement['type'] !== 'rule') {
                continue;
            }

            $nestedHeader = $header . ' ' . $statement['header'];
            $chunks[]     = $this->flattenRule($nestedHeader, $statement['body'], $nestedHeader . ' {' . $statement['body'] . '}');
        }

        return implode('', $chunks);
    }

    private function isSingleLineBlock(string $raw): bool
    {
        $open  = strpos($raw, '{');
        $close = strpos($raw, '}');

        if ($open === false || $close === false || $close < $open) {
            return false;
        }

        return ! str_contains(substr($raw, $open, $close - $open), "\n");
    }

    private function ensureBlankLinesBetweenRootRules(string $css): string
    {
        $statements = $this->splitTopLevelStatements($css);
        $result     = '';

        foreach ($statements as $index => $statement) {
            $raw = $statement['raw'];

            if ($index === 0) {
                $result .= $raw;

                continue;
            }

            $previous  = $statements[$index - 1];
            $gap       = substr($css, $previous['end'], $statement['start'] - $previous['end']);
            $separator = "\n\n";

            if (str_contains($gap, Render::CONTINUATION_MARK)) {
                $separator = "\n";
            } elseif ($this->isInlineStatement($previous)) {
                $separator = "\n";
            } elseif ($this->isAtRuleStatement($previous)) {
                $separator = "\n";
            }

            $result .= $separator . $raw;
        }

        return $result;
    }

    /**
     * @param array{type: string, header: string, body: string, raw: string, start: int, end: int, trailing: string} $statement
     */
    private function isAtRuleStatement(array $statement): bool
    {
        return str_starts_with(trim($statement['header']), '@');
    }

    /**
     * @param array{type: string, header: string, body: string, raw: string, start: int, end: int, trailing: string} $statement
     */
    private function isInlineStatement(array $statement): bool
    {
        $raw = trim($statement['raw']);

        if (str_starts_with($raw, '/*')) {
            return true;
        }

        return str_starts_with($raw, '@import');
    }

    /**
     * @return list<array{type: string, header: string, body: string, raw: string, start: int, end: int, trailing: string}>
     */
    private function splitTopLevelStatements(string $css): array
    {
        $statements = [];
        $length     = strlen($css);
        $i          = 0;

        while ($i < $length) {
            $start    = $i;
            $current  = $this->readStatement($css, $i);
            $end      = $current['end'];
            $next     = $end;
            $trailing = '';

            while ($next < $length && ($css[$next] === "\n" || $css[$next] === "\r" || $css[$next] === Render::CONTINUATION_MARK)) {
                $trailing .= $css[$next];

                $next++;
            }

            $statements[] = [
                'type'     => $current['type'],
                'header'   => $current['header'],
                'body'     => $current['body'],
                'raw'      => substr($css, $start, $end - $start),
                'start'    => $start,
                'end'      => $end,
                'trailing' => $trailing,
            ];

            $i = $next;
        }

        return $statements;
    }

    /**
     * @return array{type: string, header: string, body: string, end: int}
     */
    private function readStatement(string $css, int $start): array
    {
        $length     = strlen($css);
        $i          = $start;
        $inString   = false;
        $quote      = '';
        $parenDepth = 0;

        while ($i < $length) {
            $char = $css[$i];

            if ($inString) {
                if ($char === '\\') {
                    $i += 2;

                    continue;
                }

                if ($char === $quote) {
                    $inString = false;
                }

                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $quote    = $char;

                $i++;

                continue;
            }

            if ($char === '(') {
                $parenDepth++;
                $i++;

                continue;
            }

            if ($char === ')') {
                $parenDepth = max(0, $parenDepth - 1);

                $i++;

                continue;
            }

            if ($char === '/' && $i + 1 < $length && $css[$i + 1] === '*') {
                $end = strpos($css, '*/', $i + 2);

                if ($i === $start) {
                    return [
                        'type'   => 'raw',
                        'header' => '',
                        'body'   => '',
                        'end'    => $end === false ? $length : $end + 2,
                    ];
                }

                $i = $end === false ? $length : $end + 2;

                continue;
            }

            if ($char === '{' && $parenDepth === 0) {
                [$body, $end] = $this->readBalancedBody($css, $i);

                return [
                    'type'   => 'rule',
                    'header' => trim(substr($css, $start, $i - $start)),
                    'body'   => $body,
                    'end'    => $end,
                ];
            }

            if ($char === ';' && $parenDepth === 0) {
                break;
            }

            $i++;
        }

        return [
            'type'   => 'raw',
            'header' => '',
            'body'   => '',
            'end'    => $i < $length ? $i + 1 : $length,
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readBalancedBody(string $css, int $openIndex): array
    {
        $length    = strlen($css);
        $depth     = 0;
        $i         = $openIndex;
        $inString  = false;
        $quote     = '';
        $bodyStart = null;

        while ($i < $length) {
            $char = $css[$i];

            if ($inString) {
                if ($char === '\\') {
                    $i += 2;

                    continue;
                }

                if ($char === $quote) {
                    $inString = false;
                }

                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $quote    = $char;

                $i++;

                continue;
            }

            if ($char === '/' && $i + 1 < $length && $css[$i + 1] === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i   = $end === false ? $length : $end + 2;

                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    $bodyStart = $i + 1;
                }

                $depth++;
                $i++;

                continue;
            }

            if ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return [$bodyStart === null ? '' : substr($css, $bodyStart, $i - $bodyStart), $i + 1];
                }
            }

            $i++;
        }

        return ['', $length];
    }
}
