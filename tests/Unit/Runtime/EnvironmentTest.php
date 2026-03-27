<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;

describe('Environment', function () {
    beforeEach(function () {
        $this->env = new Environment();
    });

    it('starts with a root scope', function () {
        expect($this->env->getCurrentScope())->toBeInstanceOf(Scope::class)
            ->and($this->env->getGlobalScope())->toBe($this->env->getCurrentScope());
    });

    it('enterScope() pushes a new child scope', function () {
        $root = $this->env->getCurrentScope();

        $this->env->enterScope();

        $current = $this->env->getCurrentScope();
        expect($current)->not->toBe($root)
            ->and($current->getParent())->toBe($root);
    });

    it('exitScope() restores the previous scope', function () {
        $root = $this->env->getCurrentScope();

        $this->env->enterScope();
        $this->env->exitScope();

        expect($this->env->getCurrentScope())->toBe($root);
    });

    it('nested scopes are isolated', function () {
        $this->env->enterScope();
        $this->env->getCurrentScope()->setVariable('inner', new StringNode('only-inner'));
        $this->env->exitScope();

        expect($this->env->getCurrentScope()->hasVariable('inner'))->toBeFalse();
    });

    it('getGlobalScope() always returns root scope', function () {
        $root = $this->env->getGlobalScope();

        $this->env->enterScope();
        $this->env->enterScope();

        expect($this->env->getGlobalScope())->toBe($root);
    });

    it('enterScope() with explicit parent uses provided parent', function () {
        $separate = new Scope();
        $separate->setVariable('x', new StringNode('from-separate'));

        $this->env->enterScope($separate);

        expect($this->env->getCurrentScope()->getVariable('x'))->toBeInstanceOf(StringNode::class);

        $this->env->exitScope();
    });

    it('multiple enter/exit pairs balance correctly', function () {
        $root = $this->env->getCurrentScope();

        $this->env->enterScope();
        $this->env->enterScope();
        $this->env->exitScope();
        $this->env->exitScope();

        expect($this->env->getCurrentScope())->toBe($root);
    });

    it('exitScope() falls back to current scope parent when stack is empty', function () {
        $root = new Scope();
        $env = new Environment(new Scope($root));

        $env->exitScope();

        expect($env->getCurrentScope())->toBe($root);
    });
});
