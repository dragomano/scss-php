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
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\CssNamedColors;
use Bugo\SCSS\Values\AstValueTransformer;

use function array_merge;
use function in_array;
use function str_contains;
use function strtolower;
use function trim;

final readonly class CssArgumentEvaluator
{
    private const OPERATORS = ['==', '!=', '>=', '<=', '>', '<', 'and', 'or', 'not'];

    public function __construct(
        private AstValueEvaluatorInterface $valueEvaluator,
        private CalculationArgumentNormalizerInterface $calculationArgumentNormalizer,
        private ?AstValueEvaluatorInterface $skipConcatenationEvaluator = null,
    ) {}

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function expandCallArguments(array $arguments, Environment $env, bool $skipConcatenation = false): array
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

        $positional = [];
        $spread     = [];
        $named      = [];

        if ($allPositional) {
            foreach ($arguments as $argument) {
                $positional[] = $this->evaluateArgument($argument, $env, $skipConcatenation);
            }

            return $positional;
        }

        foreach ($arguments as $argument) {
            if ($argument instanceof SpreadArgumentNode) {
                $spreadValue = $this->evaluateArgument($argument->value, $env, $skipConcatenation);

                foreach ($this->expandSpreadValue($spreadValue) as $spreadArgument) {
                    $spread[] = $spreadArgument instanceof NamedArgumentNode
                        ? new NamedArgumentNode(
                            $spreadArgument->name,
                            $this->evaluateArgument($spreadArgument->value, $env, $skipConcatenation),
                        )
                        : $this->evaluateArgument($spreadArgument, $env, $skipConcatenation);
                }

                continue;
            }

            if ($argument instanceof NamedArgumentNode) {
                $named[] = new NamedArgumentNode(
                    $argument->name,
                    $this->evaluateArgument($argument->value, $env, $skipConcatenation),
                );

                continue;
            }

            $positional[] = $this->evaluateArgument($argument, $env, $skipConcatenation);
        }

        return array_merge($positional, $spread, $named);
    }

    private function evaluateArgument(AstNode $node, Environment $env, bool $skipConcatenation): AstNode
    {
        if ($skipConcatenation && $this->skipConcatenationEvaluator !== null) {
            return $this->skipConcatenationEvaluator->evaluate($node, $env);
        }

        return $this->valueEvaluator->evaluate($node, $env);
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function expandCssCallArguments(array $arguments, Environment $env, bool $skipConcatenation = false): array
    {
        $expanded = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof SpreadArgumentNode) {
                $spread = $this->evaluateArgument($argument->value, $env, $skipConcatenation);

                foreach ($this->expandSpreadValue($spread) as $spreadArgument) {
                    $expanded[] = $spreadArgument instanceof NamedArgumentNode
                        ? new NamedArgumentNode(
                            $spreadArgument->name,
                            $this->evaluateFallbackCssArgument($spreadArgument->value, $env, $skipConcatenation),
                        )
                        : $this->evaluateFallbackCssArgument($spreadArgument, $env, $skipConcatenation);
                }

                continue;
            }

            if ($argument instanceof NamedArgumentNode) {
                $expanded[] = new NamedArgumentNode(
                    $argument->name,
                    $this->evaluateFallbackCssArgument($argument->value, $env, $skipConcatenation),
                );

                continue;
            }

            $expanded[] = $this->evaluateFallbackCssArgument($argument, $env, $skipConcatenation);
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
                $key = $pair->key;

                if (! ($key instanceof StringNode)) {
                    throw new SassErrorException('Keyword argument names from spread maps must be strings, ' . $key::class . ' given.');
                }

                $expanded[] = new NamedArgumentNode($key->value, $pair->value);
            }

            return $expanded;
        }

        return [$spread];
    }

    private function evaluateFallbackCssArgument(AstNode $node, Environment $env, bool $skipConcatenation = false): AstNode
    {
        if ($node instanceof ListNode && count($node->items) === 3 && $this->isSlashTriple($node)) {
            if ($this->isLiteralSlashTriple($node) && $node->parenthesized === 0) {
                return $node;
            }

            return $this->evaluateArgument($node, $env, $skipConcatenation);
        }

        if (! $this->shouldPreserveCssArgument($node)) {
            return $this->evaluateArgument($node, $env, $skipConcatenation);
        }

        if ($node instanceof ListNode) {
            [$items, $changed] = $this->evaluateFallbackItems($node->items, $env);

            return $changed
                ? new ListNode($items, $node->separator, $node->bracketed, $node->parenthesized)
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
            $value = $this->evaluateFallbackCssArgument($node->value, $env, $skipConcatenation);

            return $value === $node->value
                ? $node
                : new NamedArgumentNode($node->name, $value);
        }

        /** @var FunctionNode $node */
        $arguments = $this->expandCssCallArguments($node->arguments, $env, $skipConcatenation);

        return new FunctionNode(
            name: $node->name,
            arguments: $this->calculationArgumentNormalizer->normalize($node->name, $arguments),
            parenthesized: $node->parenthesized,
        );
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
     * @param list<MapPair> $pairs
     * @return array{0: list<MapPair>, 1: bool}
     */
    private function evaluateFallbackPairs(array $pairs, Environment $env): array
    {
        $evaluatedPairs = [];
        $changed        = false;

        foreach ($pairs as $pair) {
            $evaluatedKey   = $this->evaluateFallbackCssArgument($pair->key, $env);
            $evaluatedValue = $this->evaluateFallbackCssArgument($pair->value, $env);

            if ($evaluatedKey !== $pair->key || $evaluatedValue !== $pair->value) {
                $changed = true;
            }

            $evaluatedPairs[] = new MapPair($evaluatedKey, $evaluatedValue);
        }

        return [$evaluatedPairs, $changed];
    }

    private function isSlashTriple(ListNode $node): bool
    {
        if (! in_array($node->separator, ['space', '/'], true)) {
            return false;
        }

        [$first, $mid, $last] = $node->items;

        return $mid instanceof StringNode && $mid->value === '/';
    }

    private function isLiteralSlashTriple(ListNode $node): bool
    {
        [$first, $mid, $last] = $node->items;

        return $first instanceof NumberNode
            && $first->isLiteral
            && $mid instanceof StringNode
            && $mid->value === '/'
            && $last instanceof NumberNode
            && $last->isLiteral;
    }

    private function shouldPreserveCssArgument(AstNode $node): bool
    {
        if ($node instanceof ListNode) {
            if ($node->parenthesized > 0) {
                return true;
            }

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
                    $this->shouldPreserveCssArgument($pair->key)
                    || $this->shouldPreserveCssArgument($pair->value)
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
            if ($node instanceof ColorNode) {
                $hex = $this->resolveNamedColorHex($node->value);

                return $hex === null ? $node : new ColorNode($hex);
            }

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
