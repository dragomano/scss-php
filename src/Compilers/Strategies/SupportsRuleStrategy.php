<?php

declare(strict_types=1);

namespace DartSass\Compilers\Strategies;

use DartSass\Parsers\Nodes\AstNode;
use DartSass\Parsers\Nodes\NodeType;
use DartSass\Parsers\Nodes\SupportsNode;
use InvalidArgumentException;

use function count;
use function implode;
use function rtrim;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

readonly class SupportsRuleStrategy implements RuleCompilationStrategy
{
    public function canHandle(NodeType $ruleType): bool
    {
        return $ruleType === NodeType::SUPPORTS;
    }

    public function compile(
        SupportsNode|AstNode $node,
        string $parentSelector,
        int $currentNestingLevel,
        ...$params
    ): string {
        $evaluateExpression     = $params[0] ?? null;
        $compileDeclarations    = $params[1] ?? null;
        $compileAst             = $params[2] ?? null;
        $evaluateInterpolations = $params[3] ?? null;
        $formatValue            = $params[4] ?? null;

        if (
            ! $evaluateExpression
            || ! $compileDeclarations
            || ! $compileAst
            || ! $evaluateInterpolations
            || ! $formatValue
        ) {
            throw new InvalidArgumentException(
                'Missing required parameters for @supports rule compilation'
            );
        }

        $query = $node->query;
        $query = $evaluateInterpolations($query);
        $query = $evaluateExpression($query);
        $query = $formatValue($query);
        $query = $this->canonicalizeQuery($query);

        $bodyNestingLevel = $currentNestingLevel + 1;
        $bodyDeclarations = $node->body['declarations'] ?? [];
        $bodyNested       = $node->body['nested'] ?? [];

        $declarationsCss = '';
        if (! empty($bodyDeclarations) && ! empty($parentSelector)) {
            $declarationsCss = $compileDeclarations($bodyDeclarations, $parentSelector, $bodyNestingLevel + 1);
            $indent          = str_repeat('  ', $bodyNestingLevel);
            $declarationsCss = $indent . $parentSelector . " {\n" . $declarationsCss . $indent . "}\n";
        } elseif (! empty($bodyDeclarations)) {
            $declarationsCss = $compileDeclarations($bodyDeclarations, $parentSelector, $bodyNestingLevel);
        }

        if (! empty($bodyNested)) {
            $nestedCss = $compileAst($bodyNested, $parentSelector, $bodyNestingLevel);
        } else {
            $nestedCss = '';
        }

        $body   = rtrim($declarationsCss . $nestedCss);
        $indent = str_repeat('  ', $currentNestingLevel);

        return "$indent@supports $query {\n$body\n$indent}\n";
    }

    private function canonicalizeQuery(string $query): string
    {
        $query = $this->flattenLogicalChain($query, 'and');

        return $this->flattenLogicalChain($query, 'or');
    }

    private function flattenLogicalChain(string $query, string $operator): string
    {
        $parts = $this->splitTopLevelLogical($query, $operator);

        if (count($parts) <= 1) {
            return $query;
        }

        $flattened = [];

        foreach ($parts as $part) {
            foreach ($this->collectLogicalParts($part, $operator) as $candidate) {
                $flattened[] = $candidate;
            }
        }

        return implode(" $operator ", $flattened);
    }

    private function collectLogicalParts(string $expression, string $operator): array
    {
        $expression = trim($expression);
        $parts      = $this->splitTopLevelLogical($expression, $operator);

        if (count($parts) > 1) {
            $flattened = [];

            foreach ($parts as $part) {
                foreach ($this->collectLogicalParts($part, $operator) as $candidate) {
                    $flattened[] = $candidate;
                }
            }

            return $flattened;
        }

        if (! $this->isWrappedInParentheses($expression)) {
            return [$expression];
        }

        $inner = trim(substr($expression, 1, -1));

        if ($inner === '' || str_starts_with(strtolower($inner), 'not ')) {
            return [$expression];
        }

        $opposite = $operator === 'and' ? 'or' : 'and';

        if ($this->hasTopLevelLogicalOperator($inner, $opposite)) {
            return [$expression];
        }

        $innerParts = $this->splitTopLevelLogical($inner, $operator);

        if (count($innerParts) <= 1) {
            return [$expression];
        }

        $flattened = [];

        foreach ($innerParts as $part) {
            foreach ($this->collectLogicalParts($part, $operator) as $candidate) {
                $flattened[] = $candidate;
            }
        }

        return $flattened;
    }

    private function hasTopLevelLogicalOperator(string $expression, string $operator): bool
    {
        return count($this->splitTopLevelLogical($expression, $operator)) > 1;
    }

    private function splitTopLevelLogical(string $expression, string $operator): array
    {
        $expression = trim($expression);
        $delimiter  = " $operator ";
        $depth      = 0;
        $buffer     = '';
        $parts      = [];
        $length     = strlen($expression);
        $delimLen   = strlen($delimiter);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($depth === 0 && substr($expression, $i, $delimLen) === $delimiter) {
                $parts[] = trim($buffer);
                $buffer  = '';
                $i      += $delimLen - 1;

                continue;
            }

            $buffer .= $char;
        }

        if ($buffer !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }

    private function isWrappedInParentheses(string $expression): bool
    {
        if (! str_starts_with($expression, '(') || ! str_ends_with($expression, ')')) {
            return false;
        }

        $depth  = 0;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($depth === 0 && $i < $length - 1) {
                return false;
            }
        }

        return true;
    }
}
