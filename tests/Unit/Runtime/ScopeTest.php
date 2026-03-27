<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\CallableDefinition;
use Bugo\SCSS\Runtime\Scope;

describe('Scope', function () {
    it('sets and gets a variable', function () {
        $scope = new Scope();
        $node = new StringNode('red');
        $scope->setVariable('color', $node);

        expect($scope->getVariable('color'))->toBe($node);
    });

    it('normalizes underscores to hyphens in variable names', function () {
        $scope = new Scope();
        $node = new StringNode('value');
        $scope->setVariable('my_var', $node);

        expect($scope->getVariable('my-var'))->toBe($node);
    });

    it('hasVariable() returns true for defined variable', function () {
        $scope = new Scope();
        $scope->setVariable('x', new StringNode('1'));

        expect($scope->hasVariable('x'))->toBeTrue()
            ->and($scope->hasVariable('y'))->toBeFalse();
    });

    it('getVariable() throws for undefined variable', function () {
        $scope = new Scope();

        expect(fn() => $scope->getVariable('missing'))
            ->toThrow(UndefinedSymbolException::class);
    });

    it('child scope inherits parent variables', function () {
        $parent = new Scope();
        $parent->setVariable('inherited', new StringNode('yes'));
        $child = new Scope($parent);

        expect($child->getVariable('inherited'))->toBeInstanceOf(StringNode::class);
    });

    it('getParent() returns null for root scope', function () {
        $scope = new Scope();

        expect($scope->getParent())->toBeNull();
    });

    it('getParent() returns parent scope', function () {
        $parent = new Scope();
        $child = new Scope($parent);

        expect($child->getParent())->toBe($parent);
    });

    it('getGlobalScope() returns root scope', function () {
        $root = new Scope();
        $mid = new Scope($root);
        $leaf = new Scope($mid);

        expect($leaf->getGlobalScope())->toBe($root);
    });

    it('global flag sets variable in root scope', function () {
        $root = new Scope();
        $child = new Scope($root);

        $node = new StringNode('global-value');
        $child->setVariable('gvar', $node, global: true);

        expect($root->getVariable('gvar'))->toBe($node);
    });

    it('local assignment does not leak to parent', function () {
        $parent = new Scope();
        $child = new Scope($parent);

        $child->setVariable('local', new StringNode('child-only'));

        expect($parent->hasVariable('local'))->toBeFalse();
    });

    it('default flag skips assignment when variable already exists', function () {
        $scope = new Scope();
        $original = new StringNode('original');
        $scope->setVariable('x', $original);

        $scope->setVariable('x', new StringNode('new'), default: true);

        expect($scope->getVariable('x'))->toBe($original);
    });

    it('setVariableLocal stores in current scope', function () {
        $scope = new Scope();
        $node = new StringNode('local');
        $scope->setVariableLocal('v', $node);

        expect($scope->getVariable('v'))->toBe($node);
    });

    it('setVariableLocal with default skips overriding non-null local values', function () {
        $scope = new Scope();
        $original = 'value';
        $scope->setVariableLocal('v', $original);

        $scope->setVariableLocal('v', 'new-value', default: true);

        expect($scope->getVariable('v'))->toBe($original);
    });

    it('setVariableLocal with default replaces null local values', function () {
        $scope = new Scope();
        $scope->setVariableLocal('v', null);

        $scope->setVariableLocal('v', 'new-value', default: true);

        expect($scope->getVariable('v'))->toBe('new-value');
    });

    it('getVariables() returns only variables in current scope', function () {
        $scope = new Scope();
        $scope->setVariable('a', new StringNode('1'));
        $scope->setVariable('b', new StringNode('2'));

        $vars = $scope->getVariables();
        expect($vars)->toHaveKey('a')
            ->and($vars)->toHaveKey('b');
    });

    it('getAstVariable(), getStringVariable(), and getScopeVariable() return null for mismatched stored types', function () {
        $scope = new Scope();
        $scope->setVariableLocal('plain', 'text');
        $scope->setVariableLocal('ast', new NullNode());
        $scope->setVariableLocal('string', new StringNode('value'));

        expect($scope->getAstVariable('plain'))->toBeNull()
            ->and($scope->getStringVariable('ast'))->toBeNull()
            ->and($scope->getScopeVariable('string'))->toBeNull();
    });

    it('setMixin and getMixin work', function () {
        $scope = new Scope();
        $scope->defineMixin('my-mixin', [], []);

        expect($scope->hasMixin('my-mixin'))->toBeTrue();
        $data = $scope->getMixin('my-mixin');
        expect($data->arguments)->toBe([])
            ->and($data->body)->toBe([]);
    });

    it('getMixin() throws for undefined mixin', function () {
        $scope = new Scope();

        expect(fn() => $scope->getMixin('missing'))
            ->toThrow(UndefinedSymbolException::class);
    });

    it('setMixin with global flag stores mixin in the root scope', function () {
        $root = new Scope();
        $child = new Scope($root);
        $definition = new CallableDefinition([], [], $child, 3);

        $child->setMixin('global-mixin', $definition, global: true);

        expect($root->hasMixin('global-mixin'))->toBeTrue()
            ->and($root->getMixin('global-mixin'))->toBe($definition);
    });

    it('setFunction and getFunction work', function () {
        $scope = new Scope();
        $scope->defineFunction('my-fn', [], []);

        expect($scope->hasFunction('my-fn'))->toBeTrue();
        $data = $scope->getFunction('my-fn');
        expect($data->arguments)->toBe([]);
    });

    it('getFunction() resolves definitions from parent scopes', function () {
        $parent = new Scope();
        $parent->defineFunction('shared-fn', [], []);
        $child = new Scope($parent);

        expect($child->getFunction('shared-fn'))->toBe($parent->getFunction('shared-fn'));
    });

    it('setFunction with global flag stores function in the root scope', function () {
        $root = new Scope();
        $child = new Scope($root);
        $definition = new CallableDefinition([], [], $child, 4);

        $child->setFunction('global-fn', $definition, global: true);

        expect($root->hasFunction('global-fn'))->toBeTrue()
            ->and($root->getFunction('global-fn'))->toBe($definition);
    });

    it('getFunction() throws for undefined function', function () {
        $scope = new Scope();

        expect(fn() => $scope->getFunction('missing'))
            ->toThrow(UndefinedSymbolException::class);
    });

    it('addModule and getModule work', function () {
        $scope = new Scope();
        $moduleScope = new Scope();
        $scope->addModule('math', $moduleScope);

        expect($scope->getModule('math'))->toBe($moduleScope);
    });

    it('getModule() returns null for missing module', function () {
        $scope = new Scope();

        expect($scope->getModule('nonexistent'))->toBeNull();
    });

    it('hasModuleLocal() returns true for locally added module', function () {
        $scope = new Scope();
        $scope->addModule('color', new Scope());

        expect($scope->hasModuleLocal('color'))->toBeTrue()
            ->and($scope->hasModuleLocal('other'))->toBeFalse();
    });

    it('global default assignment keeps existing non-null root variables', function () {
        $root = new Scope();
        $child = new Scope($root);
        $original = new StringNode('root');
        $root->setVariable('v', $original);

        $child->setVariable('v', new StringNode('new'), global: true, default: true);

        expect($root->getVariable('v'))->toBe($original);
    });
});
