<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyInterface;
use Closure;

use function str_contains;

final readonly class StringNodeStrategy implements EvaluationStrategyInterface
{
    /**
     * @param Closure(Environment): ?StringNode $getCurrentParentSelector
     * @param Closure(): AstNode $createNullNode
     * @param Closure(string, Environment): string $replaceInterpolations
     */
    public function __construct(
        private Closure $getCurrentParentSelector,
        private Closure $createNullNode,
        private Closure $replaceInterpolations,
    ) {}

    public function supports(AstNode $node): bool
    {
        return $node instanceof StringNode;
    }

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        /** @var StringNode $node */
        if (! $node->quoted && $node->value === '&') {
            $selectorValue = ($this->getCurrentParentSelector)($env);

            if ($selectorValue !== null) {
                return $selectorValue;
            }

            return ($this->createNullNode)();
        }

        if (! str_contains($node->value, '#{')) {
            return $node;
        }

        return new StringNode(
            ($this->replaceInterpolations)($node->value, $env),
            $node->quoted,
        );
    }
}
