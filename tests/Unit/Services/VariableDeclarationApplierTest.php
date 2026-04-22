<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Services\AstValueEvaluatorInterface;
use Bugo\SCSS\Services\ModuleVariableAssignerInterface;
use Bugo\SCSS\Services\VariableDeclarationApplier;

describe('VariableDeclarationApplier', function () {
    beforeEach(function () {
        $this->assignedModuleDeclaration = null;
        $this->moduleAssignmentEnv       = null;

        $this->valueEvaluator = new class implements AstValueEvaluatorInterface {
            public function evaluate(AstNode $node, Environment $env): AstNode
            {
                if ($node instanceof StringNode && $node->value === 'slash') {
                    return new NumberNode(2);
                }

                return $node;
            }
        };

        $this->moduleVariableAssigner = new class ($this) implements ModuleVariableAssignerInterface {
            public function __construct(private readonly object $testCase) {}

            public function assign(ModuleVarDeclarationNode $node, Environment $env): void
            {
                $this->testCase->assignedModuleDeclaration = $node;
                $this->testCase->moduleAssignmentEnv       = $env;
            }
        };

        $this->applier = new VariableDeclarationApplier(
            $this->moduleVariableAssigner,
            $this->valueEvaluator,
        );
    });

    it('applies local and global variable declarations through the evaluated value', function () {
        $env = new Environment();

        $localApplied = $this->applier->apply(
            new VariableDeclarationNode('local', new StringNode('slash')),
            $env,
        );
        $globalApplied = $this->applier->apply(
            new VariableDeclarationNode('global', new StringNode('slash'), true),
            $env,
        );

        expect($localApplied)->toBeTrue()
            ->and($globalApplied)->toBeTrue()
            ->and($env->getCurrentScope()->getVariable('local'))->toBeInstanceOf(NumberNode::class)
            ->and($env->getGlobalScope()->getVariable('global'))->toBeInstanceOf(NumberNode::class);
    });

    it('writes global defaults into the module target when the variable already exists there', function () {
        $env         = new Environment();
        $moduleScope = new Scope();

        $moduleScope->setVariableLocal('theme-color', new StringNode('existing'));
        $env->getCurrentScope()->setVariableLocal('__module_global_target', $moduleScope);

        $applied = $this->applier->apply(
            new VariableDeclarationNode('theme-color', new StringNode('slash'), true, true),
            $env,
        );

        expect($applied)->toBeTrue()
            ->and($moduleScope->getVariable('theme-color'))->toBeInstanceOf(StringNode::class);

        /** @var StringNode $value */
        $value = $moduleScope->getVariable('theme-color');

        expect($value->value)->toBe('existing')
            ->and($env->getGlobalScope()->hasVariable('theme-color'))->toBeFalse();
    });

    it('delegates module variable declarations to the assigner', function () {
        $env  = new Environment();
        $node = new ModuleVarDeclarationNode('theme', 'accent', new StringNode('blue'));

        $applied = $this->applier->apply($node, $env);

        expect($applied)->toBeTrue()
            ->and($this->assignedModuleDeclaration)->toBe($node)
            ->and($this->moduleAssignmentEnv)->toBe($env);
    });

    it('returns false for unsupported nodes', function () {
        $applied = $this->applier->apply(new StringNode('noop'), new Environment());

        expect($applied)->toBeFalse();
    });
});
