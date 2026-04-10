<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\DeprecatedExpressionNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyInterface;
use Closure;

final readonly class DeprecatedExpressionStrategy implements EvaluationStrategyInterface
{
    /**
     * @param Closure(AstNode, Environment, EvaluationOptions): AstNode $evaluateValue
     * @param Closure(string, AstNode, Environment, AstNode|null): void $handleDiagnosticDirective
     */
    public function __construct(
        private Closure $evaluateValue,
        private Closure $handleDiagnosticDirective,
    ) {}

    public function supports(AstNode $node): bool
    {
        return $node instanceof DeprecatedExpressionNode;
    }

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        /** @var DeprecatedExpressionNode $node */
        ($this->handleDiagnosticDirective)(
            'warn',
            new StringNode($node->message, true),
            $env,
            $node,
        );

        return ($this->evaluateValue)($node->expression, $env, $options);
    }
}
