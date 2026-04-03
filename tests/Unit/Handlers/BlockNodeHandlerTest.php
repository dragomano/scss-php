<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Style;
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
        $ctx,
    );

    $rule = $runtime->block()->handleRule(
        new RuleNode('.box', [new DeclarationNode('color', new StringNode('red'))]),
        RuntimeFactory::context(),
    );

    $expected = /** @lang text */ <<<'CSS'
    .box {
      color: red;
    }
    CSS;

    expect($apply)->toBe('width: 20px;')
        ->and($rule)->toEqualCss($expected);
});

it('handles nested property blocks after applying local and module declarations', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    $ctx->env->getCurrentScope()->addModule('theme', new Scope());

    $result = $runtime->block()->handleRule(new RuleNode('font:', [
        new VariableDeclarationNode('size', new NumberNode(12, 'px')),
        new ModuleVarDeclarationNode('theme', 'accent', new StringNode('blue')),
        new DeclarationNode('size', new VariableReferenceNode('size')),
    ]), $ctx);

    /** @var StringNode $moduleVariable */
    $moduleVariable = $ctx->env->getCurrentScope()->getModule('theme')?->getStringVariable('accent');

    expect($result)->toBe('font-size: 12px;')
        ->and($moduleVariable)->toBeInstanceOf(StringNode::class)
        ->and($moduleVariable->value)->toBe('blue');
});

it('starts a new rule block after standalone nested rule output without source mappings', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    $result = $runtime->block()->handleRule(new RuleNode('.parent', [
        new RuleNode('.child', [
            new DeclarationNode('color', new StringNode('red')),
        ]),
        new CommentNode('keep', true),
    ]), $ctx);

    $expected = /** @lang text */ <<<'CSS'
    .parent .child {
      color: red;
    }
    .parent {
      /*! keep */
    }
    CSS;

    expect($result)->toEqualCss($expected);
});

it('defers non-declaration children into rule output when source mappings are enabled', function () {
    $runtime = RuntimeFactory::createRuntime(options: new CompilerOptions(sourceMapFile: 'output.css.map'));
    $ctx     = RuntimeFactory::context();

    $runtimeContext = new ReflectionAccessor($runtime);
    $ctxObject      = $runtimeContext->getProperty('ctx');
    $ctxObject->sourceMapState->collectMappings = true;

    $result = $runtime->block()->handleRule(new RuleNode('.parent', [
        new RuleNode('.child', [
            new DeclarationNode('color', new StringNode('red')),
        ]),
        new CommentNode('keep', true, 2, 3),
    ]), $ctx);

    $expected = /** @lang text */ <<<'CSS'
    .parent .child {
      color: red;
    }
    .parent {
      /*! keep */
    }
    CSS;

    expect($result)->toEqualCss($expected)
        ->and($ctxObject->sourceMapState->mappings)->not->toBe([]);
});

it('handles variables and module assignments inside supports while collecting source mappings', function () {
    $runtime = RuntimeFactory::createRuntime(options: new CompilerOptions(sourceMapFile: 'output.css.map'));
    $ctx     = RuntimeFactory::context();

    $ctx->env->getCurrentScope()->addModule('theme', new Scope());

    $runtimeContext = new ReflectionAccessor($runtime);
    $ctxObject      = $runtimeContext->getProperty('ctx');
    $ctxObject->sourceMapState->collectMappings = true;

    $result = $runtime->block()->handleSupports(new SupportsNode('(display: grid)', [
        new VariableDeclarationNode('gap', new NumberNode(4, 'px')),
        new ModuleVarDeclarationNode('theme', 'accent', new StringNode('blue')),
        new RuleNode('.grid', [
            new DeclarationNode('gap', new VariableReferenceNode('gap')),
        ]),
    ]), $ctx);

    $expected = /** @lang text */ <<<'CSS'
    @supports (display: grid) {
      .grid {
        gap: 4px;
      }
    }
    CSS;

    /** @var StringNode $moduleVariable */
    $moduleVariable = $ctx->env->getCurrentScope()->getModule('theme')?->getStringVariable('accent');

    expect($result)->toEqualCss($expected)
        ->and($moduleVariable)->toBeInstanceOf(StringNode::class)
        ->and($moduleVariable->value)->toBe('blue');
});

it('returns escaped at-root chunks when supports body has no direct content', function () {
    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(style: Style::COMPRESSED),
    );
    $ctx     = RuntimeFactory::context();

    $result = $runtime->block()->handleSupports(new SupportsNode('(display: grid)', [
        new RuleNode('.inside', [
            new AtRootNode([
                new RuleNode('.outside', [
                    new DeclarationNode('color', new StringNode('red')),
                ]),
            ], 'without', ['all']),
        ]),
    ]), $ctx);

    $expected = /** @lang text */ <<<'CSS'
    .outside {
      color: #f00;
    }
    CSS;

    expect($result)->toEqualCss($expected);
});

it('returns escaped at-root chunks with a trailing newline for empty supports blocks in expanded style', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    $result = $runtime->block()->handleSupports(new SupportsNode('(display: grid)', [
        new RuleNode('.inside', [
            new AtRootNode([
                new RuleNode('.outside', [
                    new DeclarationNode('color', new StringNode('red')),
                ]),
            ], 'without', ['all']),
        ]),
    ]), $ctx);

    $expected = /** @lang text */ <<<'CSS'
    .outside {
      color: red;
    }

    CSS;

    expect($result)->toBe($expected);
});

it('appends escaped at-root chunks after supports output when content exists', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    $result = $runtime->block()->handleSupports(new SupportsNode('(display: grid)', [
        new RuleNode('.inside', [
            new DeclarationNode('display', new StringNode('grid')),
        ]),
        new RuleNode('.wrapper', [
            new AtRootNode([
                new RuleNode('.outside', [
                    new DeclarationNode('color', new StringNode('red')),
                ]),
            ], 'without', ['all']),
        ]),
    ]), $ctx);

    $expected = /** @lang text */ <<<'CSS'
    @supports (display: grid) {
      .inside {
        display: grid;
      }
    }
    .outside {
      color: red;
    }
    CSS;

    expect($result)->toEqualCss($expected);
});

it('returns compressed empty supports blocks without trailing newline', function () {
    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(style: Style::COMPRESSED),
    );
    $ctx = RuntimeFactory::context();

    $result = $runtime->block()->handleSupports(new SupportsNode('(display: grid)', []), $ctx);

    expect($result)->toBe('@supports (display: grid) {}');
});
