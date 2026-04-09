<?php

declare(strict_types=1);

use Bugo\SCSS\Handlers\AtRuleNodeHandler;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\States\OutputState;
use Bugo\SCSS\Utils\DeferredChunk;
use Bugo\SCSS\Utils\OutputChunk;
use Tests\RuntimeFactory;

it('handles @at-root blocks', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $node    = new AtRootNode([
        new RuleNode('.box', [new DeclarationNode('color', new StringNode('red'))]),
    ]);

    expect($runtime->atRule()->handleAtRoot($node, $ctx))->toContain('.box');
});

it('returns an empty string for empty @at-root blocks', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    expect($runtime->atRule()->handleAtRoot(new AtRootNode(), $ctx))->toBe('');
});

it('defers escaped @at-root chunks into the current at-rule stack', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    $ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', [
        AtRuleContextEntry::directive('media', 'screen'),
    ]);
    $outputState = $runtime->render()->outputState();
    $outputState->deferral->atRuleStack[] = [];

    $node = new AtRootNode([
        new RuleNode('.outside', [new DeclarationNode('color', new StringNode('red'))]),
    ]);

    expect($runtime->atRule()->handleAtRoot($node, $ctx))->toBe('')
        ->and($outputState->deferral->atRuleStack[0])->toHaveCount(1)
        ->and($outputState->deferral->atRuleStack[0][0]->levels)->toBe(1)
        ->and($outputState->deferral->atRuleStack[0][0]->chunk)->toContain('@media screen');
});

it('returns non-escaped @at-root chunks when a parent selector and root stack are present', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    $ctx->env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode('.host'));
    $outputState = $runtime->render()->outputState();
    $outputState->deferral->atRuleStack[] = [];

    $node = new AtRootNode([
        new RuleNode('.outside', [new DeclarationNode('color', new StringNode('red'))]),
    ]);

    $result = $runtime->atRule()->handleAtRoot($node, $ctx);

    expect($result)->toContain('.outside')
        ->toContain('color: red')
        ->and($outputState->deferral->atRuleStack[0])->toBe([]);
});

it('returns non-escaped @at-root chunks when a parent selector exists but root deferral is unavailable', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    $ctx->env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode('.host'));

    $node = new AtRootNode([
        new RuleNode('.outside', [new DeclarationNode('color', new StringNode('red'))]),
    ]);

    expect($runtime->atRule()->handleAtRoot($node, $ctx))->toContain('.outside')
        ->toContain('color: red');
});

it('defers non-escaped @at-root chunks into the deferred root stack when a parent selector is present', function () {
    $ctx = RuntimeFactory::context();
    $ctx->env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode('.host'));

    $savedPosition = [1, 0, 0];
    $deferredChunk = new DeferredChunk('.outside { color: red; }', 1, 0, []);
    $outputState = new OutputState();
    $outputState->deferral->atRootStack[] = [];

    $dispatcher = mock(NodeDispatcherInterface::class);
    $evaluation = mock(Evaluator::class);
    $render     = mock(Render::class);
    $selector   = mock(Selector::class);

    $render->shouldReceive('savePosition')->once()->andReturn($savedPosition);
    $selector->shouldReceive('compileAtRootBody')->once()->andReturn([
        'chunk'        => '.outside { color: red; }',
        'escapeLevels' => 0,
    ]);
    $render->shouldReceive('createDeferredChunk')->once()->with('.outside { color: red; }', $savedPosition)
        ->andReturn($deferredChunk);
    $render->shouldReceive('outputState')->once()->andReturn($outputState);
    $render->shouldReceive('restorePosition')->once()->with($savedPosition);
    $render->shouldReceive('appendOutputChunk')->zeroOrMoreTimes()->withArgs(
        /**
         * @param string $output
         * @param OutputChunk $chunk
         * @return bool
         */
        static function (string $output, OutputChunk $chunk): bool {
            return $chunk->content() === '.outside { color: red; }';
        },
    );

    $handler = new AtRuleNodeHandler($dispatcher, $evaluation, $render, $selector);

    expect($handler->handleAtRoot(new AtRootNode(), $ctx))->toBe('')
        ->and($outputState->deferral->atRootStack[0])->toBe([$deferredChunk]);

    Mockery::close();
});

it('handles block directives', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $node    = new DirectiveNode('media', 'screen', [
        new RuleNode('.box', [new DeclarationNode('color', new StringNode('red'))]),
    ], true);

    $expected = /** @lang text */ <<<'CSS'
    @media screen {
      .box {
        color: red;
      }
    }
    CSS;

    expect($runtime->atRule()->handleDirective($node, $ctx))->toEqualCss($expected);
});

it('ignores empty merged nested media chunks and keeps other directive content', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $node    = new DirectiveNode('media', 'screen', [
        new DirectiveNode('media', 'print', [], true),
        new RuleNode('.box', [new DeclarationNode('color', new StringNode('red'))]),
    ], true);

    $expected = /** @lang text */ <<<'CSS'
    @media screen {
      .box {
        color: red;
      }
    }
    CSS;

    expect($runtime->atRule()->handleDirective($node, $ctx))->toEqualCss($expected);
});

it('returns an empty string for block directives with no rendered content or escaped chunks', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    expect($runtime->atRule()->handleDirective(new DirectiveNode('media', 'screen', [], true), $ctx))->toBe('');
});

it('joins multiple outside chunks when a directive body renders only escaped content', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $node    = new DirectiveNode('media', 'screen', [
        new RuleNode('.a', [
            new AtRootNode([
                new RuleNode('.x', [new DeclarationNode('color', new StringNode('red'))]),
            ], 'without', ['all']),
        ]),
        new RuleNode('.b', [
            new AtRootNode([
                new RuleNode('.y', [new DeclarationNode('color', new StringNode('blue'))]),
            ], 'without', ['all']),
        ]),
    ], true);

    $expected = /** @lang text */ <<<'CSS'
    .x {
      color: red;
    }
    .y {
      color: blue;
    }
    CSS;

    expect($runtime->atRule()->handleDirective($node, $ctx))->toEqualCss($expected);
});

it('returns an empty string for @content when there is no content block', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    expect($runtime->atRule()->handleDirective(new DirectiveNode('content', '', [], false), $ctx))->toBe('');
});

it('returns an empty string for @content when the extracted content block is empty', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    $ctx->env->getCurrentScope()->setVariableLocal('__meta_content_block', []);

    expect($runtime->atRule()->handleDirective(new DirectiveNode('content', '', [], false), $ctx))->toBe('');
});

it('renders @content from the current scope when no content scope or arguments are provided', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $scope   = $ctx->env->getCurrentScope();

    $scope->setVariableLocal('tone', new StringNode('red'));
    $scope->setVariableLocal('__meta_content_block', [
        new DeclarationNode('color', new VariableReferenceNode('tone')),
        new DeclarationNode('background', new StringNode('blue')),
    ]);

    $expected = /** @lang text */ <<<'CSS'
    color: red;
    background: blue;
    CSS;

    expect($runtime->atRule()->handleDirective(new DirectiveNode('content', '', [], false), $ctx))
        ->toEqualCss($expected);
});

it('propagates module global target into @content execution scope', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $scope   = $ctx->env->getCurrentScope();

    $moduleTarget = new Scope();
    $scope->setVariableLocal('__module_global_target', $moduleTarget);
    $scope->setVariableLocal('__meta_content_block', [
        new DeclarationNode('color', new StringNode('red')),
    ]);

    $runtime->atRule()->handleDirective(new DirectiveNode('content', '', [], false), $ctx);

    expect($scope->getScopeVariable('__module_global_target'))->toBe($moduleTarget);
});

it('wraps @content in the parent rule when the current at-rule stack requires it', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $scope   = $ctx->env->getCurrentScope();

    $scope->setVariableLocal('__meta_content_block', [
        new DeclarationNode('color', new StringNode('red')),
    ]);
    $scope->setVariableLocal('__parent_selector', new StringNode('.host'));
    $scope->setVariableLocal('__at_rule_stack', [
        AtRuleContextEntry::supports('(display: grid)'),
    ]);

    $expected = /** @lang text */ <<<'CSS'
    .host {
      color: red;
    }
    CSS;

    expect($runtime->atRule()->handleDirective(new DirectiveNode('content', '', [], false), $ctx))
        ->toEqualCss($expected);
});
