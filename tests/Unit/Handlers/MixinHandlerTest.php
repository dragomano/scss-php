<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Tests\ReflectionAccessor;
use Tests\RuntimeFactory;

beforeEach(function () {
    $this->runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);
    $this->ctx     = RuntimeFactory::context();

    $runtimeContext = new ReflectionAccessor($this->runtime);
    $ctxObject      = $runtimeContext->getProperty('ctx');
    $ctxObject->functionRegistry->registerUse('sass:meta', 'meta');

    $blockAccessor   = new ReflectionAccessor($this->runtime->block());
    $this->mixin     = $blockAccessor->getProperty('mixin');
    $this->mixinTest = new ReflectionAccessor($this->mixin);
});

it('throws for unresolved local mixin includes', function () {
    expect(fn() => $this->runtime->block()->handleInclude(new IncludeNode(null, 'missing'), $this->ctx))
        ->toThrow(UndefinedSymbolException::class, 'Undefined mixin: missing');
});

it('returns an empty string for meta.apply when the first argument is not a mixin reference', function () {
    $result = $this->runtime->block()->handleInclude(
        new IncludeNode('meta', 'apply', [new NumberNode(10, 'px')]),
        $this->ctx,
    );

    expect($result)->toBe('');
});

it('returns an empty string for meta.apply when the referenced mixin cannot be resolved', function () {
    $result = $this->runtime->block()->handleInclude(
        new IncludeNode('meta', 'apply', [new StringNode('theme.missing')]),
        $this->ctx,
    );

    expect($result)->toBe('');
});

it('returns an empty string for meta.load-css when the first argument is not a string', function () {
    $result = $this->runtime->block()->handleInclude(
        new IncludeNode('meta', 'load-css', [new NumberNode(10, 'px')]),
        $this->ctx,
    );

    expect($result)->toBe('');
});

it('returns an empty string for meta.load-css when the loaded module has no css', function () {
    $result = $this->runtime->block()->handleInclude(
        new IncludeNode('meta', 'load-css', [new StringNode('functions')]),
        $this->ctx,
    );

    expect($result)->toBe('');
});

it('returns loaded css for meta.load-css without a parent selector', function () {
    $result = $this->runtime->block()->handleInclude(
        new IncludeNode('meta', 'load-css', [new StringNode('meta-load-css')]),
        $this->ctx,
    );

    $expected = /** @lang text */ <<<'CSS'
    .loaded-css {
      color: red;
    }
    CSS;

    expect($result)->toEqualCss($expected);
});

it('qualifies loaded css with the parent selector for meta.load-css', function () {
    $this->ctx->env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode('.host'));

    $result = $this->runtime->block()->handleInclude(
        new IncludeNode('meta', 'load-css', [new StringNode('meta-load-css')]),
        $this->ctx,
    );

    $expected = /** @lang text */ <<<'CSS'
    .host .loaded-css {
      color: red;
    }
    CSS;

    expect($result)->toEqualCss($expected);
});

it('propagates at-root context into mixin execution for nested selector rules', function () {
    $this->ctx->env->getCurrentScope()->defineMixin('compact', [], [
        new RuleNode('&.compact', [
            new DeclarationNode('color', new StringNode('red')),
        ]),
    ]);
    $this->ctx->env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode('.host'));
    $this->ctx->env->getCurrentScope()->setVariableLocal('__at_root_context', new BooleanNode(true));

    $result = $this->runtime->block()->handleInclude(new IncludeNode(null, 'compact'), $this->ctx);

    $expected = /** @lang text */ <<<'CSS'
    .host.compact {
      color: red;
    }
    CSS;

    expect($result)->toEqualCss($expected)
        ->and($this->runtime->module()->state()->callDepth)->toBe(0);
});

it('adds a newline between multiple declaration outputs in mixins', function () {
    $this->ctx->env->getCurrentScope()->defineMixin('decls', [], [
        new DeclarationNode('color', new StringNode('red')),
        new DeclarationNode('background', new StringNode('blue')),
    ]);

    $result = $this->runtime->block()->handleInclude(new IncludeNode(null, 'decls'), $this->ctx);

    $expected = /** @lang text */ <<<'CSS'
    color: red;
    background: blue;
    CSS;

    expect($result)->toEqualCss($expected);
});

it('appends non-declaration mixin output directly when source mappings are disabled', function () {
    $this->ctx->env->getCurrentScope()->defineMixin('comments', [], [
        new CommentNode('note', true),
        new CommentNode('again', true),
    ]);

    $result = $this->runtime->block()->handleInclude(new IncludeNode(null, 'comments'), $this->ctx);

    expect($result)->toBe("/*! note */\n/*! again */");
});

it('defers non-declaration mixin output through deferred chunks when source mappings are enabled', function () {
    $runtime = RuntimeFactory::createRuntime(
        [__DIR__ . '/../../fixtures'],
        new CompilerOptions(sourceMapFile: 'output.css.map'),
    );
    $ctx = RuntimeFactory::context();

    $runtimeContext = new ReflectionAccessor($runtime);
    $ctxObject      = $runtimeContext->getProperty('ctx');
    $ctxObject->functionRegistry->registerUse('sass:meta', 'meta');
    $ctxObject->sourceMapState->collectMappings = true;

    $ctx->env->getCurrentScope()->defineMixin('mixed', [], [
        new DeclarationNode('color', new StringNode('red')),
        new CommentNode('note', true, 2, 3),
    ]);

    $result = $runtime->block()->handleInclude(new IncludeNode(null, 'mixed'), $ctx);

    expect($result)->toBe("color: red;\n/*! note */")
        ->and($ctxObject->sourceMapState->mappings)->toHaveCount(2);
});

it('returns null namespace for unqualified mixin references', function () {
    expect($this->mixinTest->callMethod('parseMixinReference', ['plain']))->toBe([null, 'plain']);
});

it('splits qualified mixin references into namespace and member', function () {
    expect($this->mixinTest->callMethod('parseMixinReference', ['theme.box']))->toBe(['theme', 'box']);
});

it('returns unresolved results for local and namespaced missing mixins', function () {
    $scope = $this->ctx->env->getCurrentScope();

    expect($this->mixinTest->callMethod('resolveMixin', [null, 'missing', $scope]))->toBe([null, null])
        ->and($this->mixinTest->callMethod('resolveMixin', ['theme', 'missing', $scope]))->toBe([null, null]);
});

it('returns an empty configuration for meta.load-css when the with argument is not a map', function () {
    expect($this->mixinTest->callMethod('metaLoadCssConfiguration', [new StringNode('bad')]))->toBe([]);
});

it('extracts string keyed configuration entries for meta.load-css maps', function () {
    $configuration = $this->mixinTest->callMethod('metaLoadCssConfiguration', [
        new MapNode([
            ['key' => new StringNode('primary'), 'value' => new StringNode('red')],
            ['key' => new NumberNode(1), 'value' => new StringNode('ignored')],
        ]),
    ]);

    expect($configuration)->toHaveKey('primary')
        ->and($configuration['primary'])->toBeInstanceOf(StringNode::class);

    /** @var StringNode $primary */
    $primary = $configuration['primary'];

    expect($primary->value)->toBe('red');
});
