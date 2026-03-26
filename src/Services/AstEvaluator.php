<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\FunctionDeclarationNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\MixinNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Runtime\Environment;
use LogicException;

final class AstEvaluator
{
    private ?Module $module = null;

    public function setModule(Module $module): void
    {
        $this->module = $module;
    }

    public function evaluate(AstNode $node, Environment $env): void
    {
        if ($node instanceof RootNode) {
            foreach ($node->children as $child) {
                $this->evaluate($child, $env);
            }

            return;
        }

        if ($node instanceof RuleNode) {
            $env->enterScope();

            foreach ($node->children as $child) {
                $this->evaluate($child, $env);
            }

            $env->exitScope();

            return;
        }

        if ($node instanceof SupportsNode) {
            $env->enterScope();

            foreach ($node->body as $child) {
                $this->evaluate($child, $env);
            }

            $env->exitScope();

            return;
        }

        if ($node instanceof UseNode) {
            $this->module()->handleUse($node, $env);

            return;
        }

        if ($node instanceof ImportNode) {
            $this->module()->handleImport($node, $env);

            return;
        }

        if ($node instanceof ForwardNode) {
            $this->module()->handleForward($node, $env);

            return;
        }

        if ($node instanceof VariableDeclarationNode) {
            $env->getCurrentScope()->setVariable(
                $node->name,
                $node->value,
                $node->global,
                $node->default,
                $node->line
            );

            return;
        }

        if ($node instanceof ModuleVarDeclarationNode) {
            $this->module()->assignModuleVariable($node, $env, false);

            return;
        }

        if ($node instanceof MixinNode) {
            $env->getCurrentScope()->defineMixin(
                $node->name,
                $node->arguments,
                $node->body,
                false,
                null,
                $node->line
            );

            return;
        }

        if ($node instanceof FunctionDeclarationNode) {
            $env->getCurrentScope()->defineFunction(
                $node->name,
                $node->arguments,
                $node->body,
                false,
                null,
                $node->line
            );
        }
    }

    private function module(): Module
    {
        if ($this->module instanceof Module) {
            return $this->module;
        }

        throw new LogicException('Module service is not initialized.');
    }
}
