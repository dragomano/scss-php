<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Nodes\FunctionDeclarationNode;
use Bugo\SCSS\Nodes\MixinNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Module;

use function in_array;
use function str_replace;
use function strtolower;

final readonly class DefinitionNodeHandler
{
    private const DEPRECATED_FUNCTION_NAMES = ['url', 'expression', 'element'];

    public function __construct(
        private Evaluator $evaluation,
        private Module $module,
        private Context $context,
    ) {}

    public function handleFunction(FunctionDeclarationNode $node, TraversalContext $ctx): string
    {
        if ($this->isDeprecatedName($node->name)) {
            $this->context->logWarning("Invalid function name: \"$node->name\"", $node->line);
        }

        $ctx->env->getCurrentScope()->defineFunction(
            $node->name,
            $node->arguments,
            $node->body,
            false,
            null,
            $node->line,
        );

        return '';
    }

    public function handleMixin(MixinNode $node, TraversalContext $ctx): string
    {
        $ctx->env->getCurrentScope()->defineMixin(
            $node->name,
            $node->arguments,
            $node->body,
            false,
            null,
            $node->line,
        );

        return '';
    }

    public function handleModuleVarDeclaration(ModuleVarDeclarationNode $node, TraversalContext $ctx): string
    {
        $this->module->assignModuleVariable($node, $ctx->env);

        return '';
    }

    public function handleVariableDeclaration(VariableDeclarationNode $node, TraversalContext $ctx): string
    {
        $scope = $ctx->env->getCurrentScope();

        $value = $this->evaluation->evaluateValueWithSlashDivision($node->value, $ctx->env);

        if ($node->global) {
            $scope->setVariable($node->name, $value, true, $node->default, $node->line);
        } else {
            $scope->setVariableLocal($node->name, $value, $node->default, $node->line);
        }

        $origin = $scope->findImportedVariableOrigin($node->name);

        $isForwardedOrigin = $origin !== null
            && $scope->findForwardedVariableOrigin($node->name) !== null;

        if ($origin !== null && ($this->isGlobalAssignment($node, $scope) || $isForwardedOrigin)) {
            /** @psalm-var mixed $value */
            $value       = $scope->getVariable($node->name);
            $originScope = $origin['scope'];
            $originName  = $origin['name'];

            while (true) {
                $originScope->setVariableLocal($originName, $value);

                $forwarded = $originScope->findForwardedVariableOrigin($originName);

                if ($forwarded === null) {
                    break;
                }

                $originScope = $forwarded['scope'];
                $originName  = $forwarded['name'];
            }
        }

        return '';
    }

    private function isGlobalAssignment(VariableDeclarationNode $node, Scope $scope): bool
    {
        if ($node->global) {
            return true;
        }

        $global = $scope->getGlobalScope();

        if ($scope === $global) {
            return true;
        }

        return $scope->isFlowControlScope() && $scope->getParent() === $global;
    }

    private function isDeprecatedName(string $name): bool
    {
        return in_array(
            strtolower(str_replace('_', '-', $name)),
            self::DEPRECATED_FUNCTION_NAMES,
            true,
        );
    }
}
