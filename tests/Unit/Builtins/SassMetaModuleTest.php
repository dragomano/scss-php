<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\FunctionRegistry;
use Bugo\SCSS\Builtins\SassMetaModule;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;

describe('SassMetaModule', function () {
    beforeEach(function () {
        $this->module = new SassMetaModule();
        $this->env = new Environment();
        $this->registry = new FunctionRegistry();
        $this->context = new BuiltinCallContext($this->env, $this->registry);
    });

    it('exposes metadata', function () {
        expect($this->module->getName())->toBe('meta')
            ->and($this->module->getFunctions())->toBe([
                'accepts-content',
                'calc-args',
                'calc-name',
                'call',
                'content-exists',
                'feature-exists',
                'function-exists',
                'get-function',
                'get-mixin',
                'global-variable-exists',
                'inspect',
                'keywords',
                'mixin-exists',
                'module-functions',
                'module-mixins',
                'module-variables',
                'type-of',
                'variable-exists',
            ])
            ->and($this->module->getGlobalAliases())->toHaveKeys([
                'feature-exists',
                'inspect',
                'type-of',
                'keywords',
                'calc-name',
                'calc-args',
                'global-variable-exists',
                'variable-exists',
                'function-exists',
                'mixin-exists',
                'get-function',
                'get-mixin',
                'call',
                'module-variables',
                'module-functions',
                'module-mixins',
                'content-exists',
                'accepts-content',
            ]);
    });

    it('does not expose apply or load-css as global aliases', function () {
        expect($this->module->getGlobalAliases())->not->toHaveKey('apply')
            ->and($this->module->getGlobalAliases())->not->toHaveKey('load-css');
    });

    it('evaluates accepts-content false without a mixin reference', function () {
        $acceptsContent = $this->module->call('accepts-content', [new StringNode('any')], [], $this->context);

        expect($acceptsContent->value)->toBeFalse();
    });

    it('evaluates accepts-content true for mixin with @content directive', function () {
        $this->env->getCurrentScope()->defineMixin('wrap', [], [new DirectiveNode('content')]);

        $acceptsContent = $this->module->call('accepts-content', [new StringNode('wrap')], [], $this->context);

        expect($acceptsContent->value)->toBeTrue();
    });

    it('evaluates accepts-content for mixin references returned by get-mixin', function () {
        $this->env->getCurrentScope()->defineMixin('wrap', [], [new DirectiveNode('content')]);

        $acceptsContent = $this->module->call('accepts-content', [new MixinRefNode('wrap')], [], $this->context);

        expect($acceptsContent->value)->toBeTrue();
    });

    it('evaluates calc-args', function () {
        $calc = new FunctionNode('calc', [new NumberNode(100, '%'), new StringNode('-'), new NumberNode(10, 'px')]);
        $args = $this->module->call('calc-args', [$calc], []);

        expect($args)->toBeInstanceOf(ListNode::class)
            ->and($args->separator)->toBe('comma')
            ->and(count($args->items))->toBe(3);
    });

    it('evaluates calc-name', function () {
        $calc = new FunctionNode('calc', [new NumberNode(100, '%'), new StringNode('-'), new NumberNode(10, 'px')]);
        $name = $this->module->call('calc-name', [$calc], []);

        expect($name->value)->toBe('calc');
    });

    it('evaluates call', function () {
        $this->registry->registerUse('sass:list', null);

        $fn = $this->module->call('get-function', [new StringNode('length')], ['module' => new StringNode('list')], $this->context);
        $result = $this->module->call('call', [$fn, new ListNode([new StringNode('a'), new StringNode('b')], 'space')], [], $this->context);

        expect($result->value)->toBe(2);
    });

    it('evaluates content-exists false outside include-content context', function () {
        $contentExists = $this->module->call('content-exists', [], [], $this->context);

        expect($contentExists->value)->toBeFalse();
    });

    it('evaluates content-exists true in include-content context', function () {
        $this->env->getCurrentScope()->setVariable('__meta_content_exists', new BooleanNode(true));

        $contentExists = $this->module->call('content-exists', [], [], $this->context);

        expect($contentExists->value)->toBeTrue();
    });

    it('evaluates feature-exists', function () {
        $supported = $this->module->call('feature-exists', [new StringNode('global-variable-shadowing')], []);
        $unknown = $this->module->call('feature-exists', [new StringNode('any-feature')], []);

        expect($supported->value)->toBeTrue()
            ->and($unknown->value)->toBeFalse();
    });

    it('evaluates function-exists for builtins', function () {
        $exists = $this->module->call('function-exists', [new StringNode('length')], [], $this->context);
        expect($exists->value)->toBeTrue();
    });

    it('evaluates function-exists for module', function () {
        $this->registry->registerUse('sass:list', null);

        $exists = $this->module->call('function-exists', [new StringNode('length')], ['module' => new StringNode('list')], $this->context);
        expect($exists->value)->toBeTrue();
    });

    it('evaluates get-function', function () {
        $this->registry->registerUse('sass:list', null);

        $fn = $this->module->call('get-function', [new StringNode('length')], ['module' => new StringNode('list')], $this->context);

        expect($fn)->toBeInstanceOf(StringNode::class)
            ->and($fn->value)->toContain('length');
    });

    it('evaluates get-mixin', function () {
        $moduleScope = new Scope();
        $moduleScope->defineMixin('highlight', [], []);
        $this->env->getCurrentScope()->addModule('functions', $moduleScope);

        $mixin = $this->module->call('get-mixin', [new StringNode('highlight')], ['module' => new StringNode('functions')], $this->context);

        expect($mixin->name)->toBe('functions.highlight');
    });

    it('evaluates global-variable-exists', function () {
        $this->env->getCurrentScope()->setVariable('x', new NumberNode(1));

        $global = $this->module->call('global-variable-exists', [new StringNode('x')], [], $this->context);

        expect($global->value)->toBeTrue();
    });

    it('evaluates inspect', function () {
        $value = new MapNode([
            ['key' => new StringNode('a'), 'value' => new NumberNode(1)],
            ['key' => new StringNode('b'), 'value' => new ListNode([new StringNode('x'), new StringNode('y')], 'comma')],
        ]);
        $result = $this->module->call('inspect', [$value], []);

        expect($result->value)->toBe('(a: 1, b: x, y)');
    });

    it('evaluates keywords for map and non-map values', function () {
        $map = new MapNode([['key' => new StringNode('a'), 'value' => new NumberNode(1)]]);
        $mapResult = $this->module->call('keywords', [$map], []);
        $emptyResult = $this->module->call('keywords', [new ListNode([])], []);

        expect($mapResult)->toBe($map)
            ->and($emptyResult)->toBeInstanceOf(MapNode::class)
            ->and($emptyResult->pairs)->toBe([]);
    });

    it('evaluates mixin-exists', function () {
        $moduleScope = new Scope();
        $moduleScope->defineMixin('highlight', [], []);
        $this->env->getCurrentScope()->addModule('functions', $moduleScope);

        $exists = $this->module->call('mixin-exists', [new StringNode('highlight')], ['module' => new StringNode('functions')], $this->context);

        expect($exists->value)->toBeTrue();
    });

    it('evaluates module-functions for builtins', function () {
        $this->registry->registerUse('sass:list', null);

        $result = $this->module->call('module-functions', [new StringNode('list')], [], $this->context);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and($result->pairs[0]['key']->value)->toBe('append')
            ->and($result->pairs[0]['value'])->toBeInstanceOf(FunctionNode::class);
    });

    it('evaluates module-mixins', function () {
        $moduleScope = new Scope();
        $moduleScope->defineMixin('highlight', [], []);
        $this->env->getCurrentScope()->addModule('functions', $moduleScope);

        $mixins = $this->module->call('module-mixins', [new StringNode('functions')], [], $this->context);

        expect($mixins)->toBeInstanceOf(MapNode::class)
            ->and($mixins->pairs[0]['key']->value)->toBe('highlight');
    });

    it('evaluates module-variables', function () {
        $moduleScope = new Scope();
        $moduleScope->setVariable('primary-color', new ColorNode('#112233'));
        $this->env->getCurrentScope()->addModule('theme', $moduleScope);

        $result = $this->module->call('module-variables', [new StringNode('theme')], [], $this->context);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and($result->pairs[0]['key']->value)->toBe('primary-color');
    });

    it('evaluates type-of variants', function () {
        expect($this->module->call('type-of', [new NumberNode(1, 'px')], [])->value)->toBe('number')
            ->and($this->module->call('type-of', [new ColorNode('#112233')], [])->value)->toBe('color')
            ->and($this->module->call('type-of', [new ListNode([new StringNode('a')])], [])->value)->toBe('list')
            ->and($this->module->call('type-of', [new MapNode([])], [])->value)->toBe('map')
            ->and($this->module->call('type-of', [new FunctionNode('fn')], [])->value)->toBe('function')
            ->and($this->module->call('type-of', [new StringNode('abc')], [])->value)->toBe('string');
    });

    it('evaluates variable-exists', function () {
        $this->env->getCurrentScope()->setVariable('x', new NumberNode(1));

        $local = $this->module->call('variable-exists', [new StringNode('x')], [], $this->context);

        expect($local->value)->toBeTrue();
    });
});
