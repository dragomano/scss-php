<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\CssNamedColors;
use Bugo\SCSS\Values\AstValueTransformer;
use Closure;

use function in_array;
use function str_contains;
use function strtolower;
use function trim;

final readonly class CssArgumentEvaluator
{
    private const OPERATORS = ['==', '!=', '>=', '<=', '>', '<', 'and', 'or', 'not'];

    /**
     * @param Closure(AstNode, Environment): AstNode $evaluateValue
     * @param Closure(string, Environment): AstNode $resolveVariable
     * @param Closure(string, array<int, AstNode>): array<int, AstNode> $normalizeCalculationArguments
     */
    public function __construct(
        private Closure $evaluateValue,
        private Closure $resolveVariable,
        private Closure $normalizeCalculationArguments
    ) {}

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function expandCallArguments(array $arguments, Environment $env): array
    {
        if ($arguments === []) {
            return [];
        }

        $allPositional = true;

        foreach ($arguments as $argument) {
            if ($argument instanceof SpreadArgumentNode || $argument instanceof NamedArgumentNode) {
                $allPositional = false;

                break;
            }
        }

        $expanded = [];

        if ($allPositional) {
            foreach ($arguments as $argument) {
                $expanded[] = ($this->evaluateValue)($argument, $env);
            }

            return $expanded;
        }

        foreach ($arguments as $argument) {
            if ($argument instanceof SpreadArgumentNode) {
                $spread = ($this->evaluateValue)($argument->value, $env);

                foreach ($this->expandSpreadValue($spread) as $spreadArgument) {
                    $expanded[] = $spreadArgument;
                }

                continue;
            }

            if ($argument instanceof NamedArgumentNode) {
                $expanded[] = new NamedArgumentNode(
                    $argument->name,
                    ($this->evaluateValue)($argument->value, $env)
                );

                continue;
            }

            $expanded[] = ($this->evaluateValue)($argument, $env);
        }

        return $expanded;
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function expandCssCallArguments(array $arguments, Environment $env): array
    {
        $expanded = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof SpreadArgumentNode) {
                $spread = ($this->evaluateValue)($argument->value, $env);

                foreach ($this->expandSpreadValue($spread) as $spreadArgument) {
                    $expanded[] = $spreadArgument instanceof NamedArgumentNode
                        ? new NamedArgumentNode(
                            $spreadArgument->name,
                            $this->evaluateFallbackCssArgument($spreadArgument->value, $env)
                        )
                        : $this->evaluateFallbackCssArgument($spreadArgument, $env);
                }

                continue;
            }

            if ($argument instanceof NamedArgumentNode) {
                $expanded[] = new NamedArgumentNode(
                    $argument->name,
                    $this->evaluateFallbackCssArgument($argument->value, $env)
                );

                continue;
            }

            $expanded[] = $this->evaluateFallbackCssArgument($argument, $env);
        }

        return $expanded;
    }

    /**
     * @return array<int, AstNode>
     */
    public function expandSpreadValue(AstNode $spread): array
    {
        if ($spread instanceof ArgumentListNode) {
            $expanded = $spread->items;

            foreach ($spread->keywords as $name => $value) {
                $expanded[] = new NamedArgumentNode($name, $value);
            }

            return $expanded;
        }

        if ($spread instanceof ListNode) {
            return $spread->items;
        }

        if ($spread instanceof MapNode) {
            $expanded = [];

            foreach ($spread->pairs as $pair) {
                $key = $pair['key'];

                if (! ($key instanceof StringNode)) {
                    throw new SassErrorException('Keyword argument names from spread maps must be strings, ' . $key::class . ' given.');
                }

                $expanded[] = new NamedArgumentNode($key->value, $pair['value']);
            }

            return $expanded;
        }

        return [$spread];
    }

    private function evaluateFallbackCssArgument(AstNode $node, Environment $env): AstNode
    {
        if (! $this->shouldPreserveCssArgument($node)) {
            return ($this->evaluateValue)($node, $env);
        }

        if ($node instanceof ListNode) {
            [$items, $changed] = $this->evaluateFallbackItems($node->items, $env);

            return $changed
                ? new ListNode($items, $node->separator, $node->bracketed)
                : $node;
        }

        if ($node instanceof ArgumentListNode) {
            [$items, $itemsChanged]       = $this->evaluateFallbackItems($node->items, $env);
            [$keywords, $keywordsChanged] = $this->evaluateFallbackKeywords($node->keywords, $env);

            return $itemsChanged || $keywordsChanged
                ? new ArgumentListNode($items, $node->separator, $node->bracketed, $keywords)
                : $node;
        }

        if ($node instanceof MapNode) {
            [$pairs, $changed] = $this->evaluateFallbackPairs($node->pairs, $env);

            return $changed ? new MapNode($pairs) : $node;
        }

        if ($node instanceof NamedArgumentNode) {
            $value = $this->evaluateFallbackCssArgument($node->value, $env);

            return $value === $node->value
                ? $node
                : new NamedArgumentNode($node->name, $value);
        }

        if ($node instanceof FunctionNode) {
            $arguments = $this->expandCssCallArguments($node->arguments, $env);

            return new FunctionNode($node->name, ($this->normalizeCalculationArguments)($node->name, $arguments));
        }

        if ($node instanceof VariableReferenceNode) {
            return $this->evaluateFallbackCssArgument(($this->resolveVariable)($node->name, $env), $env);
        }

        return $node;
    }

    /**
     * @param array<int, AstNode> $items
     * @return array{0: array<int, AstNode>, 1: bool}
     */
    private function evaluateFallbackItems(array $items, Environment $env): array
    {
        $evaluatedItems = [];
        $changed        = false;

        foreach ($items as $item) {
            $evaluatedItem = $this->evaluateFallbackCssArgument($item, $env);

            if ($evaluatedItem !== $item) {
                $changed = true;
            }

            $evaluatedItems[] = $evaluatedItem;
        }

        return [$evaluatedItems, $changed];
    }

    /**
     * @param array<string, AstNode> $keywords
     * @return array{0: array<string, AstNode>, 1: bool}
     */
    private function evaluateFallbackKeywords(array $keywords, Environment $env): array
    {
        $evaluatedKeywords = [];
        $changed           = false;

        foreach ($keywords as $name => $keywordValue) {
            $evaluatedKeywordValue = $this->evaluateFallbackCssArgument($keywordValue, $env);

            if ($evaluatedKeywordValue !== $keywordValue) {
                $changed = true;
            }

            $evaluatedKeywords[$name] = $evaluatedKeywordValue;
        }

        return [$evaluatedKeywords, $changed];
    }

    /**
     * @param array<int, array{key: AstNode, value: AstNode}> $pairs
     * @return array{0: array<int, array{key: AstNode, value: AstNode}>, 1: bool}
     */
    private function evaluateFallbackPairs(array $pairs, Environment $env): array
    {
        $evaluatedPairs = [];
        $changed        = false;

        foreach ($pairs as $pair) {
            $evaluatedKey   = $this->evaluateFallbackCssArgument($pair['key'], $env);
            $evaluatedValue = $this->evaluateFallbackCssArgument($pair['value'], $env);

            if ($evaluatedKey !== $pair['key'] || $evaluatedValue !== $pair['value']) {
                $changed = true;
            }

            $evaluatedPairs[] = [
                'key'   => $evaluatedKey,
                'value' => $evaluatedValue,
            ];
        }

        return [$evaluatedPairs, $changed];
    }

    private function shouldPreserveCssArgument(AstNode $node): bool
    {
        if ($node instanceof ListNode) {
            foreach ($node->items as $item) {
                if (
                    $item instanceof StringNode
                    && in_array(trim(strtolower($item->value)), self::OPERATORS, true)
                ) {
                    return true;
                }
            }

            foreach ($node->items as $item) {
                if ($this->shouldPreserveCssArgument($item)) {
                    return true;
                }
            }
        }

        if ($node instanceof FunctionNode) {
            foreach ($node->arguments as $item) {
                if ($this->shouldPreserveCssArgument($item)) {
                    return true;
                }
            }
        }

        if ($node instanceof ArgumentListNode) {
            foreach ($node->items as $item) {
                if ($this->shouldPreserveCssArgument($item)) {
                    return true;
                }
            }

            foreach ($node->keywords as $keywordValue) {
                if ($this->shouldPreserveCssArgument($keywordValue)) {
                    return true;
                }
            }
        }

        if ($node instanceof NamedArgumentNode) {
            return $this->shouldPreserveCssArgument($node->value);
        }

        if ($node instanceof MapNode) {
            foreach ($node->pairs as $pair) {
                if (
                    $this->shouldPreserveCssArgument($pair['key'])
                    || $this->shouldPreserveCssArgument($pair['value'])
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    public function compressNamedColorsForOutput(AstNode $value): AstNode
    {
        return AstValueTransformer::map($value, function (AstNode $node): AstNode {
            if (! $node instanceof StringNode) {
                return $node;
            }

            if ($node->quoted || str_contains($node->value, '#{')) {
                return $node;
            }

            $hex = $this->resolveNamedColorHex($node->value);

            return $hex === null ? $node : new ColorNode($hex);
        });
    }

    public function resolveNamedColorHex(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return CssNamedColors::NAMED_HEX[strtolower($value)] ?? null;
    }
}
