<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Services\AstEvaluator;
use Tests\RuntimeFactory;

it('evaluates root definitions into the environment scope', function () {
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);

    $scss = <<<'SCSS'
        $color: red;
        @mixin box() {}
        @function tone() { @return $color; }
    SCSS;

    $ast = RuntimeFactory::parse($scss);
    $env = new Environment();

    $runtime->ast()->evaluate($ast, $env);

    expect($env->getCurrentScope()->hasVariable('color'))->toBeTrue()
        ->and($env->getCurrentScope()->hasMixin('box'))->toBeTrue()
        ->and($env->getCurrentScope()->hasFunction('tone'))->toBeTrue();
});

it('evaluates @use nodes during ast prepass', function () {
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);

    $ast = RuntimeFactory::parse('@use "_functions.scss";');
    $env = new Environment();

    $runtime->ast()->evaluate($ast, $env);

    expect($env->getCurrentScope()->getModule('functions'))->toBeInstanceOf(Scope::class);
});

it('throws when module service is requested before initialization', function () {
    $evaluator = new AstEvaluator();
    $env = new Environment();

    expect(fn() => $evaluator->evaluate(new UseNode('_functions.scss', null), $env))->toThrow(
        LogicException::class,
        'Module service is not initialized.'
    );
});
