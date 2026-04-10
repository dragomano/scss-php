<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyInterface;
use Closure;

final readonly class ArgumentListNodeStrategy implements EvaluationStrategyInterface
{
    use LazilyEvaluatesItems;

    /**
     * @param Closure(AstNode, Environment, EvaluationOptions): AstNode $evaluateValue
     */
    public function __construct(private Closure $evaluateValue) {}

    public function supports(AstNode $node): bool
    {
        return $node instanceof ArgumentListNode;
    }

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        /** @var ArgumentListNode $node */
        $default = EvaluationOptions::default();

        $items = self::lazilyEvaluateItems(
            $node->items,
            fn(AstNode $item): AstNode => ($this->evaluateValue)($item, $env, $default),
        );

        $keywords = null;

        foreach ($node->keywords as $name => $keywordValue) {
            $evaluatedKeywordValue = ($this->evaluateValue)($keywordValue, $env, $default);

            if ($keywords !== null) {
                $keywords[$name] = $evaluatedKeywordValue;
            } elseif ($evaluatedKeywordValue !== $keywordValue) {
                $keywords        = $node->keywords;
                $keywords[$name] = $evaluatedKeywordValue;
            }
        }

        if ($items === null && $keywords === null) {
            return $node;
        }

        return new ArgumentListNode(
            $items ?? $node->items,
            $node->separator,
            $node->bracketed,
            $keywords ?? $node->keywords,
        );
    }
}
