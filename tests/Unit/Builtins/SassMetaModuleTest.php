<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\FunctionRegistry;
use Bugo\SCSS\Builtins\SassMetaModule;
use Bugo\SCSS\Exceptions\InvalidArgumentTypeException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\ElseIfNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;

describe('SassMetaModule', function () {
    beforeEach(function () {
        $this->module   = new SassMetaModule();
        $this->env      = new Environment();
        $this->registry = new FunctionRegistry();
        $this->context  = new BuiltinCallContext($this->env, $this->registry);
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

    it('evaluates accepts-content false without arguments or for missing mixins', function () {
        $withoutArguments = $this->module->call('accepts-content', [], [], $this->context);
        $missingMixin     = $this->module->call('accepts-content', [new MixinRefNode('missing')], [], $this->context);
        $invalidReference = $this->module->call('accepts-content', [new NumberNode(1)], [], $this->context);

        expect($withoutArguments->value)->toBeFalse()
            ->and($missingMixin->value)->toBeFalse()
            ->and($invalidReference->value)->toBeFalse();
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

    it('requires a calculation function for calc-args and calc-name', function () {
        expect(fn() => $this->module->call('calc-args', [], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('calc-name', [new StringNode('calc')], []))
            ->toThrow(MissingFunctionArgumentsException::class);
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

    it('returns a function node for unresolved meta.call and validates input', function () {
        $capturedScope = new Scope();
        $function = new FunctionNode('user-fn', capturedScope: $capturedScope);
        $result   = $this->module->call('call', [$function, new NumberNode(2)], [], $this->context);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('user-fn')
            ->and($result->capturedScope)->toBe($capturedScope)
            ->and(count($result->arguments))->toBe(1)
            ->and(fn() => $this->module->call('call', [], [], $this->context))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('call', [new NumberNode(1)], [], $this->context))
            ->toThrow(MissingFunctionArgumentsException::class);
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
        $unknown   = $this->module->call('feature-exists', [new StringNode('any-feature')], []);

        expect($supported->value)->toBeTrue()
            ->and($unknown->value)->toBeFalse();
    });

    it('requires a string feature name', function () {
        expect(fn() => $this->module->call('feature-exists', [], []))
            ->toThrow(MissingFunctionArgumentsException::class);
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

    it('evaluates function-exists for user modules and throws for unknown namespaces', function () {
        $moduleScope = new Scope();
        $moduleScope->defineFunction('custom-fn', [], []);
        $this->env->getCurrentScope()->addModule('helpers', $moduleScope);

        $exists  = $this->module->call('function-exists', [new StringNode('custom-fn')], ['module' => new StringNode('helpers')], $this->context);
        $missing = $this->module->call('function-exists', [new StringNode('missing-fn')], ['module' => new StringNode('helpers')], $this->context);

        expect($exists->value)->toBeTrue()
            ->and($missing->value)->toBeFalse()
            ->and(fn() => $this->module->call('function-exists', [new StringNode('custom-fn')], ['module' => new StringNode('unknown')], $this->context))
            ->toThrow(ModuleResolutionException::class);
    });

    it('treats captured functions as visible before the current call line', function () {
        $outerScope   = new Scope();
        $currentScope = $this->env->getCurrentScope();
        $currentScope->defineFunction('captured-fn', [], [], false, $outerScope, 20);

        $context = new BuiltinCallContext($this->env, $this->registry, callLine: 10);
        $result  = $this->module->call('function-exists', [new StringNode('captured-fn')], [], $context);

        expect($result->value)->toBeTrue();
    });

    it('evaluates get-function', function () {
        $this->registry->registerUse('sass:list', null);

        $fn = $this->module->call('get-function', [new StringNode('length')], ['module' => new StringNode('list')], $this->context);

        expect($fn)->toBeInstanceOf(StringNode::class)
            ->and($fn->value)->toContain('length');
    });

    it('evaluates get-function for user functions and throws when missing', function () {
        $this->env->getCurrentScope()->defineFunction('custom-fn', [], []);

        $userFunction = $this->module->call('get-function', [new StringNode('custom-fn')], [], $this->context);

        expect($userFunction)->toBeInstanceOf(FunctionNode::class)
            ->and($userFunction->name)->toBe('custom-fn')
            ->and(fn() => $this->module->call('get-function', [new StringNode('missing-fn')], [], $this->context))
            ->toThrow(ModuleResolutionException::class)
            ->and(fn() => $this->module->call('get-function', [new StringNode('missing-fn')], ['module' => new StringNode('helpers')], $this->context))
            ->toThrow(ModuleResolutionException::class);
    });

    it('evaluates get-mixin', function () {
        $moduleScope = new Scope();
        $moduleScope->defineMixin('highlight', [], []);
        $this->env->getCurrentScope()->addModule('functions', $moduleScope);

        $mixin = $this->module->call('get-mixin', [new StringNode('highlight')], ['module' => new StringNode('functions')], $this->context);

        expect($mixin->name)->toBe('functions.highlight');
    });

    it('throws when get-mixin target is missing', function () {
        expect(fn() => $this->module->call('get-mixin', [new StringNode('missing')], [], $this->context))
            ->toThrow(ModuleResolutionException::class)
            ->and(fn() => $this->module->call('get-mixin', [new StringNode('missing')], ['module' => new StringNode('functions')], $this->context))
            ->toThrow(ModuleResolutionException::class);
    });

    it('evaluates global-variable-exists', function () {
        $this->env->getCurrentScope()->setVariable('x', new NumberNode(1));

        $global = $this->module->call('global-variable-exists', [new StringNode('x')], [], $this->context);

        expect($global->value)->toBeTrue();
    });

    it('evaluates global-variable-exists for module variables', function () {
        $moduleScope = new Scope();
        $moduleScope->setVariable('primary-color', new ColorNode('#112233'));
        $this->env->getCurrentScope()->addModule('theme', $moduleScope);

        $global = $this->module->call('global-variable-exists', [new StringNode('primary-color')], ['module' => new StringNode('theme')], $this->context);

        expect($global->value)->toBeTrue();
    });

    it('evaluates inspect', function () {
        $value = new MapNode([
            new MapPair(new StringNode('a'), new NumberNode(1)),
            new MapPair(new StringNode('b'), new ListNode([new StringNode('x'), new StringNode('y')], 'comma')),
        ]);
        $result = $this->module->call('inspect', [$value], []);

        expect($result->value)->toBe('(a: 1, b: x, y)');
    });

    it('evaluates inspect for scalar values', function () {
        $result = $this->module->call('inspect', [new NumberNode(12, 'px')], []);

        expect($result->value)->toBe('12px');
    });

    it('evaluates keywords for map and non-map values', function () {
        $map = new MapNode([new MapPair(new StringNode('a'), new NumberNode(1))]);
        $mapResult = $this->module->call('keywords', [$map], []);
        $emptyResult = $this->module->call('keywords', [new ListNode([])], []);

        expect($mapResult)->toBe($map)
            ->and($emptyResult)->toBeInstanceOf(MapNode::class)
            ->and($emptyResult->pairs)->toBe([]);
    });

    it('evaluates keywords for argument lists', function () {
        $argumentList = new ArgumentListNode([], 'comma', false, [
            'alpha' => new NumberNode(1),
            'beta' => new StringNode('two'),
        ]);
        $result = $this->module->call('keywords', [$argumentList], []);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and(count($result->pairs))->toBe(2)
            ->and($result->pairs[0]->key->value)->toBe('alpha')
            ->and($result->pairs[1]->key->value)->toBe('beta');
    });

    it('requires arguments for keywords and type-of', function () {
        expect(fn() => $this->module->call('keywords', [], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('type-of', [], []))
            ->toThrow(MissingFunctionArgumentsException::class);
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
            ->and($result->pairs[0]->key->value)->toBe('append')
            ->and($result->pairs[0]->value)->toBeInstanceOf(FunctionNode::class);
    });

    it('throws for unknown namespaces in module export helpers', function () {
        expect(fn() => $this->module->call('module-functions', [new StringNode('unknown')], [], $this->context))
            ->toThrow(ModuleResolutionException::class)
            ->and(fn() => $this->module->call('module-mixins', [new StringNode('unknown')], [], $this->context))
            ->toThrow(ModuleResolutionException::class)
            ->and(fn() => $this->module->call('module-variables', [new StringNode('unknown')], [], $this->context))
            ->toThrow(ModuleResolutionException::class);
    });

    it('evaluates module-mixins', function () {
        $moduleScope = new Scope();
        $moduleScope->defineMixin('highlight', [], []);
        $this->env->getCurrentScope()->addModule('functions', $moduleScope);

        $mixins = $this->module->call('module-mixins', [new StringNode('functions')], [], $this->context);

        expect($mixins)->toBeInstanceOf(MapNode::class)
            ->and($mixins->pairs[0]->key->value)->toBe('highlight');
    });

    it('evaluates module-variables', function () {
        $moduleScope = new Scope();
        $moduleScope->setVariable('primary-color', new ColorNode('#112233'));
        $moduleScope->setVariableLocal('helper', 'not-an-ast-node');
        $this->env->getCurrentScope()->addModule('theme', $moduleScope);

        $result = $this->module->call('module-variables', [new StringNode('theme')], [], $this->context);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and(count($result->pairs))->toBe(1)
            ->and($result->pairs[0]->key->value)->toBe('primary-color');
    });

    it('evaluates type-of variants', function () {
        expect($this->module->call('type-of', [new NumberNode(1, 'px')], [])->value)->toBe('number')
            ->and($this->module->call('type-of', [new ColorNode('#112233')], [])->value)->toBe('color')
            ->and($this->module->call('type-of', [new ArgumentListNode()], [])->value)->toBe('arglist')
            ->and($this->module->call('type-of', [new ListNode([new StringNode('a')])], [])->value)->toBe('list')
            ->and($this->module->call('type-of', [new MapNode([])], [])->value)->toBe('map')
            ->and($this->module->call('type-of', [new FunctionNode('calc')], [])->value)->toBe('calculation')
            ->and($this->module->call('type-of', [new FunctionNode('fn')], [])->value)->toBe('function')
            ->and($this->module->call('type-of', [new MixinRefNode('wrap')], [])->value)->toBe('mixin')
            ->and($this->module->call('type-of', [new BooleanNode(true)], [])->value)->toBe('bool')
            ->and($this->module->call('type-of', [new NullNode()], [])->value)->toBe('null')
            ->and($this->module->call('type-of', [new StringNode('abc')], [])->value)->toBe('string');
    });

    it('evaluates variable-exists', function () {
        $this->env->getCurrentScope()->setVariable('x', new NumberNode(1));

        $local = $this->module->call('variable-exists', [new StringNode('x')], [], $this->context);

        expect($local->value)->toBeTrue();
    });

    it('evaluates user-defined module exports', function () {
        $moduleScope = new Scope();
        $moduleScope->defineFunction('custom-fn', [], []);
        $moduleScope->defineMixin('custom-mixin', [], []);
        $moduleScope->setVariable('answer', new NumberNode(42));
        $this->env->getCurrentScope()->addModule('helpers', $moduleScope);

        $functions = $this->module->call('module-functions', [new StringNode('helpers')], [], $this->context);
        $mixins = $this->module->call('module-mixins', [new StringNode('helpers')], [], $this->context);
        $variables = $this->module->call('module-variables', [new StringNode('helpers')], [], $this->context);
        $exists = $this->module->call('variable-exists', [new StringNode('answer')], ['module' => new StringNode('helpers')], $this->context);

        expect($functions)->toBeInstanceOf(MapNode::class)
            ->and($functions->pairs[0]->key->value)->toBe('custom-fn')
            ->and($functions->pairs[0]->value)->toBeInstanceOf(FunctionNode::class)
            ->and($mixins)->toBeInstanceOf(MapNode::class)
            ->and($mixins->pairs[0]->key->value)->toBe('custom-mixin')
            ->and($variables)->toBeInstanceOf(MapNode::class)
            ->and($variables->pairs[0]->key->value)->toBe('answer')
            ->and($exists->value)->toBeTrue();
    });

    it('evaluates accepts-content for namespaced mixin references and missing namespaced mixins', function () {
        $moduleScope = new Scope();
        $moduleScope->defineMixin('wrap', [], [new DirectiveNode('content')]);
        $this->env->getCurrentScope()->addModule('helpers', $moduleScope);

        $existing = $this->module->call('accepts-content', [new StringNode('helpers.wrap')], [], $this->context);
        $missing  = $this->module->call('accepts-content', [new StringNode('helpers.missing')], [], $this->context);

        expect($existing->value)->toBeTrue()
            ->and($missing->value)->toBeFalse();
    });

    it('detects nested content directives in directive if-elseif and rule nodes', function () {
        $this->env->getCurrentScope()->defineMixin('directive-wrap', [], [
            new DirectiveNode('media', 'screen', [new DirectiveNode('content')], true),
        ]);
        $this->env->getCurrentScope()->defineMixin('if-wrap', [], [
            new IfNode('false', [], [new ElseIfNode('true', [new DirectiveNode('content')])]),
        ]);
        $this->env->getCurrentScope()->defineMixin('rule-wrap', [], [
            new RuleNode('.child', [new DirectiveNode('content')]),
        ]);

        $directive = $this->module->call('accepts-content', [new StringNode('directive-wrap')], [], $this->context);
        $ifElseIf = $this->module->call('accepts-content', [new StringNode('if-wrap')], [], $this->context);
        $rule = $this->module->call('accepts-content', [new StringNode('rule-wrap')], [], $this->context);

        expect($directive->value)->toBeTrue()
            ->and($ifElseIf->value)->toBeTrue()
            ->and($rule->value)->toBeTrue();
    });

    it('validates string and module helper arguments', function () {
        expect(fn() => $this->module->call('get-mixin', [new NumberNode(1)], [], $this->context))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('get-function', [new StringNode('length')], ['module' => new NumberNode(1)], $this->context))
            ->toThrow(InvalidArgumentTypeException::class);
    });

    it('formats non-string deprecated meta arguments using their css value', function () {
        $warnings = [];
        $context = new BuiltinCallContext(
            $this->env,
            $this->registry,
            static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
            'variable-exists',
        );

        expect(fn() => $this->module->call('variable-exists', [new NumberNode(12, 'px')], [], $context))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('meta.variable-exists(12px)');
    });

    it('requires compiler context for scope-dependent meta functions', function () {
        expect(fn() => $this->module->call('content-exists', [], [], null))
            ->toThrow(LogicException::class);
    });
});
