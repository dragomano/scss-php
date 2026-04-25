<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Runtime\Environment;

final readonly class VariableDeclarationApplier implements VariableDeclarationApplierInterface
{
    public function __construct(
        private ModuleVariableAssignerInterface $moduleVariableAssigner,
        private AstValueEvaluatorInterface $valueEvaluator,
    ) {}

    public function apply(AstNode $node, Environment $env): bool
    {
        if ($node instanceof VariableDeclarationNode) {
            $evaluatedValue = $this->valueEvaluator->evaluate($node->value, $env);
            $currentScope   = $env->getCurrentScope();

            if ($node->global) {
                $moduleScopeTarget = $currentScope->getScopeVariable('__module_global_target');

                if ($moduleScopeTarget !== null && $moduleScopeTarget->hasVariable($node->name)) {
                    $moduleScopeTarget->setVariableLocal($node->name, $evaluatedValue, $node->default);

                    return true;
                }

                $currentScope->setVariable(
                    $node->name,
                    $evaluatedValue,
                    true,
                    $node->default,
                );

                return true;
            }

            $currentScope->setVariableLocal(
                $node->name,
                $evaluatedValue,
                $node->default,
            );

            return true;
        }

        if ($node instanceof ModuleVarDeclarationNode) {
            $this->moduleVariableAssigner->assign($node, $env);

            return true;
        }

        return false;
    }
}
