<?php

declare(strict_types=1);

use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;
use Tests\RuntimeFactory;

it('evaluates root definitions into the environment scope', function () {
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);
    $ast = RuntimeFactory::parse(<<<'SCSS'
    $color: red;
    @mixin box() {}
    @function tone() { @return $color; }
    SCSS);
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
