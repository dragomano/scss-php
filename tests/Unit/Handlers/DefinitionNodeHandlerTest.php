<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\FunctionDeclarationNode;
use Bugo\SCSS\Nodes\MixinNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Runtime\Scope;
use Tests\RuntimeFactory;

it('registers functions mixins and variables in scope', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    $moduleScope = new Scope();
    $moduleScope->setVariable('tone', new NumberNode(1));

    $ctx->env->getCurrentScope()->addModule('theme', $moduleScope);

    $runtime->definition()->handleFunction(new FunctionDeclarationNode('size'), $ctx);
    $runtime->definition()->handleMixin(new MixinNode('box'), $ctx);
    $runtime->definition()->handleVariableDeclaration(
        new VariableDeclarationNode('gap', new NumberNode(12, 'px')),
        $ctx,
    );
    $runtime->definition()->handleModuleVarDeclaration(
        new ModuleVarDeclarationNode('theme', 'tone', new NumberNode(3)),
        $ctx,
    );

    expect($ctx->env->getCurrentScope()->hasFunction('size'))->toBeTrue()
        ->and($ctx->env->getCurrentScope()->hasMixin('box'))->toBeTrue()
        ->and($ctx->env->getCurrentScope()->getVariable('gap'))->toBeInstanceOf(NumberNode::class)
        ->and($moduleScope->getVariable('tone')->value)->toBe(3);
});
