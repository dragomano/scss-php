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
            $items   = [];
            $changed = false;

            foreach ($node->items as $item) {
                $evaluatedItem = $this->evaluateFallbackCssArgument($item, $env);

                if ($evaluatedItem !== $item) {
                    $changed = true;
                }

                $items[] = $evaluatedItem;
            }

            return $changed
                ? new ListNode($items, $node->separator, $node->bracketed)
                : $node;
        }

        if ($node instanceof ArgumentListNode) {
            $items    = [];
            $keywords = [];
            $changed  = false;

            foreach ($node->items as $item) {
                $evaluatedItem = $this->evaluateFallbackCssArgument($item, $env);

                if ($evaluatedItem !== $item) {
                    $changed = true;
                }

                $items[] = $evaluatedItem;
            }

            foreach ($node->keywords as $name => $keywordValue) {
                $evaluatedKeywordValue = $this->evaluateFallbackCssArgument($keywordValue, $env);

                if ($evaluatedKeywordValue !== $keywordValue) {
                    $changed = true;
                }

                $keywords[$name] = $evaluatedKeywordValue;
            }

            return $changed
                ? new ArgumentListNode($items, $node->separator, $node->bracketed, $keywords)
                : $node;
        }

        if ($node instanceof MapNode) {
            $pairs   = [];
            $changed = false;

            foreach ($node->pairs as $pair) {
                $evaluatedKey   = $this->evaluateFallbackCssArgument($pair['key'], $env);
                $evaluatedValue = $this->evaluateFallbackCssArgument($pair['value'], $env);

                if ($evaluatedKey !== $pair['key'] || $evaluatedValue !== $pair['value']) {
                    $changed = true;
                }

                $pairs[] = [
                    'key'   => $evaluatedKey,
                    'value' => $evaluatedValue,
                ];
            }

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
        if ($value instanceof StringNode) {
            if ($value->quoted || str_contains($value->value, '#{')) {
                return $value;
            }

            $hex = $this->resolveNamedColorHex($value->value);

            return $hex === null ? $value : new ColorNode($hex);
        }

        if ($value instanceof ListNode) {
            $items   = [];
            $changed = false;

            foreach ($value->items as $item) {
                $compressed = $this->compressNamedColorsForOutput($item);

                if ($compressed !== $item) {
                    $changed = true;
                }

                $items[] = $compressed;
            }

            return $changed
                ? new ListNode($items, $value->separator, $value->bracketed)
                : $value;
        }

        if ($value instanceof ArgumentListNode) {
            $items    = [];
            $keywords = [];
            $changed  = false;

            foreach ($value->items as $item) {
                $compressed = $this->compressNamedColorsForOutput($item);

                if ($compressed !== $item) {
                    $changed = true;
                }

                $items[] = $compressed;
            }

            foreach ($value->keywords as $name => $keywordValue) {
                $compressed = $this->compressNamedColorsForOutput($keywordValue);

                if ($compressed !== $keywordValue) {
                    $changed = true;
                }

                $keywords[$name] = $compressed;
            }

            return $changed
                ? new ArgumentListNode($items, $value->separator, $value->bracketed, $keywords)
                : $value;
        }

        if ($value instanceof MapNode) {
            $pairs   = [];
            $changed = false;

            foreach ($value->pairs as $pair) {
                $compressedKey   = $this->compressNamedColorsForOutput($pair['key']);
                $compressedValue = $this->compressNamedColorsForOutput($pair['value']);

                if ($compressedKey !== $pair['key'] || $compressedValue !== $pair['value']) {
                    $changed = true;
                }

                $pairs[] = [
                    'key'   => $compressedKey,
                    'value' => $compressedValue,
                ];
            }

            return $changed ? new MapNode($pairs) : $value;
        }

        if ($value instanceof NamedArgumentNode) {
            $compressed = $this->compressNamedColorsForOutput($value->value);

            return $compressed === $value->value
                ? $value
                : new NamedArgumentNode($value->name, $compressed);
        }

        if ($value instanceof FunctionNode) {
            $arguments = [];
            $changed   = false;

            foreach ($value->arguments as $argument) {
                $compressed = $this->compressNamedColorsForOutput($argument);

                if ($compressed !== $argument) {
                    $changed = true;
                }

                $arguments[] = $compressed;
            }

            return $changed ? new FunctionNode($value->name, $arguments) : $value;
        }

        return $value;
    }

    public function resolveNamedColorHex(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return CssNamedColors::NAMED_HEX[strtolower($value)] ?? null;
    }
}
