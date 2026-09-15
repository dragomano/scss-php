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

    it('writes global declarations through to the imported variable origin', function () {
        $env         = new Environment();
        $originScope = new Scope();

        $env->getCurrentScope()->trackImportedVariable('imported', $originScope, 'original');

        $applied = $this->applier->apply(
            new VariableDeclarationNode('imported', new StringNode('slash'), true),
            $env,
        );

        expect($applied)->toBeTrue()
            ->and($originScope->getVariable('original'))->toBeInstanceOf(NumberNode::class)
            ->and($env->getGlobalScope()->hasVariable('imported'))->toBeFalse();
    });

    it('writes local declarations through to the forwarded variable origin', function () {
        $env         = new Environment();
        $originScope = new Scope();

        $env->getCurrentScope()->trackForwardedVariable('forwarded', $originScope, 'original');

        $applied = $this->applier->apply(
            new VariableDeclarationNode('forwarded', new StringNode('slash')),
            $env,
        );

        expect($applied)->toBeTrue()
            ->and($originScope->getVariable('original'))->toBeInstanceOf(NumberNode::class)
            ->and($env->getCurrentScope()->hasVariable('forwarded'))->toBeFalse();
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
