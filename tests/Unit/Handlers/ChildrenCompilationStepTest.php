<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\Handlers\Rule\ChildrenCompilationStep;
use Bugo\SCSS\Handlers\Rule\RuleCompilationContext;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\TraversalContext;
use Tests\Support\RuntimeFactory;

describe('ChildrenCompilationStep', function () {
    it('restores the saved position for a preserved comment on the rule opening line', function () {
        $compilerContext = new CompilerContext();
        $compilerContext->sourceMapState->startCollection();

        $runtime = RuntimeFactory::createRuntime(
            context: $compilerContext,
        );
        $context = RuntimeFactory::context();

        $step = new ChildrenCompilationStep(
            $runtime->dispatcher(),
            $runtime->evaluation(),
            $runtime->render(),
            $runtime->deferredChunks(),
        );

        $ruleCtx = new RuleCompilationContext(
            node: new RuleNode('.parent', [
                new CommentNode('keep', true, 1),
            ]),
            outerCtx: $context,
            prefix: '',
            childCtx: new TraversalContext($context->env, 1),
        );
        $ruleCtx->selector = '.parent';

        expect($step->execute($ruleCtx))->toBeNull()
            ->and($ruleCtx->output)->toBe('.parent { /*!keep*/')
            ->and($ruleCtx->hasRenderedChildren)->toBeTrue();
    });

    it('treats a non-array at-rule stack as not being inside keyframes', function () {
        $runtime = RuntimeFactory::createRuntime();
        $context = RuntimeFactory::context();
        $context->env->getCurrentScope()->setVariableLocal('__at_rule_stack', 'oops');

        $step = new ChildrenCompilationStep(
            $runtime->dispatcher(),
            $runtime->evaluation(),
            $runtime->render(),
            $runtime->deferredChunks(),
        );

        $media = new DirectiveNode('media', '(min-width: 1)', [
            new RuleNode('.inner', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ], true);

        $ruleCtx = new RuleCompilationContext(
            node: new RuleNode('.host', [$media]),
            outerCtx: $context,
            prefix: '',
            childCtx: new TraversalContext($context->env, 1),
        );
        $ruleCtx->selector       = '.host';
        $ruleCtx->parentSelector = '.host';

        $expected = /** @lang text */ <<<'CSS'
        @media (min-width: 1) {
          .host .inner {
            color: red;
          }
        }
        CSS;

        expect($step->execute($ruleCtx))->toBeNull()
            ->and($ruleCtx->output)->toBe('')
            ->and($ruleCtx->leadingRootChunks)->toHaveCount(1)
            ->and($ruleCtx->leadingRootChunks[0]->content())->toBe($expected)
            ->and($ruleCtx->trailingRootChunks)->toBe([]);
    });
});
