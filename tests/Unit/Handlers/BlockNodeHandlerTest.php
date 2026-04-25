<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
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
use Tests\RuntimeFactory;

it('handles local mixin includes', function () {
    $runtime = RuntimeFactory::createRuntime();
    $context = RuntimeFactory::context();
    $context->env->getCurrentScope()->defineMixin('box', [], [
        new DeclarationNode('color', new StringNode('red')),
    ]);

    expect($runtime->block()->handleInclude(new IncludeNode(null, 'box'), $context))
        ->toBe('color: red;');
});

it('handles nested property blocks after applying local and module declarations', function () {
    $runtime = RuntimeFactory::createRuntime();
    $context = RuntimeFactory::context();
    $context->env->getCurrentScope()->addModule('theme', new Scope());

    $result = $runtime->block()->handleRule(new RuleNode('font:', [
        new VariableDeclarationNode('size', new NumberNode(12, 'px')),
        new ModuleVarDeclarationNode('theme', 'accent', new StringNode('blue')),
        new DeclarationNode('size', new VariableReferenceNode('size')),
    ]), $context);

    /** @var StringNode $moduleVariable */
    $moduleVariable = $context->env->getCurrentScope()->getModule('theme')?->getStringVariable('accent');

    expect($result)->toBe('font-size: 12px;')
        ->and($moduleVariable)->toBeInstanceOf(StringNode::class)
        ->and($moduleVariable->value)->toBe('blue');
});

it('starts a new rule block after standalone nested rule output without source mappings', function () {
    $runtime = RuntimeFactory::createRuntime();
    $context = RuntimeFactory::context();

    $result = $runtime->block()->handleRule(new RuleNode('.parent', [
        new RuleNode('.child', [
            new DeclarationNode('color', new StringNode('red')),
        ]),
        new CommentNode('keep', true),
    ]), $context);

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

it('returns escaped at-root chunks when supports body has no direct content', function () {
    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(style: Style::COMPRESSED),
    );
    $context = RuntimeFactory::context();

    $result = $runtime->block()->handleSupports(new SupportsNode('(display: grid)', [
        new RuleNode('.inside', [
            new AtRootNode([
                new RuleNode('.outside', [
                    new DeclarationNode('color', new StringNode('red')),
                ]),
            ], 'without', ['all']),
        ]),
    ]), $context);

    $expected = /** @lang text */ <<<'CSS'
    .outside {
      color: #f00;
    }
    CSS;

    expect($result)->toEqualCss($expected);
});

it('returns escaped at-root chunks with a trailing newline for empty supports blocks in expanded style', function () {
    $runtime = RuntimeFactory::createRuntime();
    $context = RuntimeFactory::context();

    $result = $runtime->block()->handleSupports(new SupportsNode('(display: grid)', [
        new RuleNode('.inside', [
            new AtRootNode([
                new RuleNode('.outside', [
                    new DeclarationNode('color', new StringNode('red')),
                ]),
            ], 'without', ['all']),
        ]),
    ]), $context);

    $expected = /** @lang text */ <<<'CSS'
    .outside {
      color: red;
    }

    CSS;

    expect($result)->toBe($expected);
});

it('appends escaped at-root chunks after supports output when content exists', function () {
    $runtime = RuntimeFactory::createRuntime();
    $context = RuntimeFactory::context();

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
    ]), $context);

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
    $context = RuntimeFactory::context();

    $result = $runtime->block()->handleSupports(new SupportsNode('(display: grid)', []), $context);

    expect($result)->toBe('@supports (display: grid) {}');
});

it('handles supports bodies with local and module declarations while collecting source mappings', function () {
    $compilerContext = new CompilerContext();
    $compilerContext->sourceMapState->startCollection();

    $runtime = RuntimeFactory::createRuntime(
        context: $compilerContext,
    );
    $context = RuntimeFactory::context();
    $moduleScope = new Scope();

    $context->env->getCurrentScope()->addModule('theme', $moduleScope);

    $result = $runtime->block()->handleSupports(new SupportsNode('(display: grid)', [
        new VariableDeclarationNode('tone', new StringNode('red')),
        new ModuleVarDeclarationNode('theme', 'accent', new StringNode('blue')),
        new RuleNode('.box', [
            new DeclarationNode('color', new VariableReferenceNode('tone')),
        ]),
    ]), $context);

    /** @var StringNode $moduleVariable */
    $moduleVariable = $moduleScope->getStringVariable('accent');

    $expected = /** @lang text */ <<<'CSS'
    @supports (display: grid) {
      .box {
        color: red;
      }
    }

    CSS;

    expect($result)->toBe($expected)
        ->and($moduleVariable)->toBeInstanceOf(StringNode::class)
        ->and($moduleVariable->value)->toBe('blue');
});

it('renders non-declaration rule children through deferred chunks while collecting source mappings', function () {
    $compilerContext = new CompilerContext();
    $compilerContext->sourceMapState->startCollection();

    $runtime = RuntimeFactory::createRuntime(
        context: $compilerContext,
    );
    $context = RuntimeFactory::context();

    $result = $runtime->block()->handleRule(new RuleNode('.parent', [
        new CommentNode('keep', true, 2, 3),
    ]), $context);

    $expected = /** @lang text */ <<<'CSS'
    .parent {
      /*! keep */
    }

    CSS;

    expect($result)->toBe($expected)
        ->and($compilerContext->sourceMapState->mappings)->not->toBe([]);
});
