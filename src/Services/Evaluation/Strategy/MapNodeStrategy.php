<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyInterface;
use Closure;

use function array_slice;

final readonly class MapNodeStrategy implements EvaluationStrategyInterface
{
    /**
     * @param Closure(AstNode, Environment, EvaluationOptions): AstNode $evaluateValue
     */
    public function __construct(private Closure $evaluateValue) {}

    public function supports(AstNode $node): bool
    {
        return $node instanceof MapNode;
    }

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        /** @var MapNode $node */
        $pairs   = null;
        $pairIdx = 0;
        $default = EvaluationOptions::default();

        foreach ($node->pairs as $pair) {
            $evaluatedKey   = ($this->evaluateValue)($pair->key, $env, $default);
            $evaluatedValue = ($this->evaluateValue)($pair->value, $env, $default);

            if ($pairs !== null) {
                $pairs[] = new MapPair($evaluatedKey, $evaluatedValue);
            } elseif ($evaluatedKey !== $pair->key || $evaluatedValue !== $pair->value) {
                $pairs   = $pairIdx > 0 ? array_slice($node->pairs, 0, $pairIdx) : [];
                $pairs[] = new MapPair($evaluatedKey, $evaluatedValue);
            }

            $pairIdx++;
        }

        if ($pairs === null) {
            return $node;
        }

        return new MapNode($pairs);
    }
}
