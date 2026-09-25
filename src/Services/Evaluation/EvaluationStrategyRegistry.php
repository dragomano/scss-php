<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;

use function array_key_exists;

final class EvaluationStrategyRegistry
{
    /** @var array<class-string<AstNode>, EvaluationStrategyInterface|null> */
    private array $strategyByNodeClass = [];

    /** @param list<EvaluationStrategyInterface> $strategies */
    public function __construct(private readonly array $strategies) {}

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        $nodeClass = $node::class;

        if (! isset($this->strategyByNodeClass[$nodeClass]) && ! array_key_exists($nodeClass, $this->strategyByNodeClass)) {
            $this->strategyByNodeClass[$nodeClass] = $this->findStrategy($node);
        }

        $strategy = $this->strategyByNodeClass[$nodeClass];

        return $strategy === null ? $node : $strategy->evaluate($node, $env, $options);
    }

    private function findStrategy(AstNode $node): ?EvaluationStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($node)) {
                return $strategy;
            }
        }

        return null;
    }
}
