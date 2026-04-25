<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Scope;
use Tests\RuntimeFactory;

beforeEach(function () {
    $this->compilerContext = new CompilerContext();
    $this->compilerContext->functionRegistry->registerUse('sass:meta', 'meta');

    $this->runtime = RuntimeFactory::createRuntime(
        [__DIR__ . '/../../fixtures'],
        context: $this->compilerContext,
    );

    $this->ctx = RuntimeFactory::context();
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

it('returns an empty string for meta.apply when an unqualified referenced mixin cannot be resolved', function () {
    $result = $this->runtime->block()->handleInclude(
        new IncludeNode('meta', 'apply', [new StringNode('missing')]),
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
    $compilerContext = new CompilerContext();
    $compilerContext->functionRegistry->registerUse('sass:meta', 'meta');
    $compilerContext->sourceMapState->collectMappings = true;

    $runtime = RuntimeFactory::createRuntime(
        [__DIR__ . '/../../fixtures'],
        new CompilerOptions(sourceMapFile: 'output.css.map'),
        $compilerContext,
    );

    $ctx = RuntimeFactory::context();

    $ctx->env->getCurrentScope()->defineMixin('mixed', [], [
        new DeclarationNode('color', new StringNode('red')),
        new CommentNode('note', true, 2, 3),
    ]);

    $result = $runtime->block()->handleInclude(new IncludeNode(null, 'mixed'), $ctx);

    expect($result)->toBe("color: red;\n/*! note */")
        ->and($compilerContext->sourceMapState->mappings)->toHaveCount(2);
});

it('resolves unqualified meta.apply mixin references from the current scope', function () {
    $this->ctx->env->getCurrentScope()->defineMixin('plain', [], [
        new DeclarationNode('color', new StringNode('red')),
    ]);

    $result = $this->runtime->block()->handleInclude(
        new IncludeNode('meta', 'apply', [new StringNode('plain')]),
        $this->ctx,
    );

    expect($result)->toBe('color: red;');
});

it('resolves qualified meta.apply mixin references from module scopes', function () {
    $moduleScope = new Scope();
    $moduleScope->defineMixin('box', [], [
        new DeclarationNode('border', new StringNode('1px solid red')),
    ]);

    $this->ctx->env->getCurrentScope()->addModule('theme', $moduleScope);

    $result = $this->runtime->block()->handleInclude(
        new IncludeNode('meta', 'apply', [new StringNode('theme.box')]),
        $this->ctx,
    );

    expect($result)->toBe('border: 1px solid red;');
});

it('ignores non-map with arguments for meta.load-css configuration', function () {
    $result = $this->runtime->block()->handleInclude(
        new IncludeNode('meta', 'load-css', [
            new StringNode('configurable_with_css'),
            new NamedArgumentNode('with', new StringNode('bad')),
        ]),
        $this->ctx,
    );

    $expected = /** @lang text */ <<<'CSS'
    .configurable-sample {
      color: red;
      margin: 8px;
    }
    CSS;

    expect($result)->toEqualCss($expected);
});

it('uses only string keyed with entries for meta.load-css configuration maps', function () {
    $result = $this->runtime->block()->handleInclude(
        new IncludeNode('meta', 'load-css', [
            new StringNode('configurable_with_css'),
            new NamedArgumentNode('with', new MapNode([
                new MapPair(new StringNode('primary'), new StringNode('blue')),
                new MapPair(new NumberNode(1), new StringNode('ignored')),
            ])),
        ]),
        $this->ctx,
    );

    $expected = /** @lang text */ <<<'CSS'
    .configurable-sample {
      color: blue;
      margin: 8px;
    }
    CSS;

    expect($result)->toEqualCss($expected);
});
