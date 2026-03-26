<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\Scope;
use Tests\ReflectionAccessor;
use Tests\RuntimeFactory;

it('handles local mixin includes', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx = RuntimeFactory::context();
    $ctx->env->getCurrentScope()->defineMixin('box', [], [
        new DeclarationNode('color', new StringNode('red')),
    ]);

    expect($runtime->block()->handleInclude(new IncludeNode(null, 'box'), $ctx))->toBe('color: red;');
});

it('handles meta.apply and regular rule blocks', function () {
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);

    $ctx = RuntimeFactory::context();
    $ctx->env->getCurrentScope()->defineMixin('sized', [
        new ArgumentNode('width'),
    ], [
        new DeclarationNode('width', new VariableReferenceNode('width')),
    ]);
    $ctx->env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode('.wrap'));
    $ctx->env->getCurrentScope()->addModule('meta', new Scope());

    $runtimeContext = new ReflectionAccessor($runtime);
    $ctxObject = $runtimeContext->getProperty('ctx');
    $ctxObject->functionRegistry->registerUse('sass:meta', 'meta');

    $apply = $runtime->block()->handleInclude(
        new IncludeNode('meta', 'apply', [new StringNode('sized'), new NumberNode(20, 'px')]),
        $ctx
    );

    $rule = $runtime->block()->handleRule(
        new RuleNode('.box', [new DeclarationNode('color', new StringNode('red'))]),
        RuntimeFactory::context()
    );

    $expected = /** @lang text */ <<<'CSS'
    .box {
      color: red;
    }
    CSS;

    expect($apply)->toBe('width: 20px;')
        ->and($rule)->toEqualCss($expected);
});
