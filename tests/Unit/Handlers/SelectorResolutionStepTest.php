<?php

declare(strict_types=1);

use Bugo\SCSS\Handlers\Rule\RuleCompilationContext;
use Bugo\SCSS\Handlers\Rule\SelectorResolutionStep;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Tests\Support\RuntimeFactory;

describe('SelectorResolutionStep', function () {
    it('treats a non-array at-rule stack as not being inside keyframes', function () {
        $runtime = RuntimeFactory::createRuntime();
        $ctx     = RuntimeFactory::context();

        $ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', 'oops');

        $step = new SelectorResolutionStep(
            $runtime->evaluation(),
            $runtime->selector(),
            $runtime->render(),
            $runtime->context(),
        );

        $ruleCtx = new RuleCompilationContext(
            new RuleNode('.a', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
            $ctx,
            '',
            $ctx,
        );

        expect($step->execute($ruleCtx))->toBeNull()
            ->and($ruleCtx->selector)->toBe('.a')
            ->and($ruleCtx->parentSelector)->toBe('.a')
            ->and($ruleCtx->omitOwnRuleOutput)->toBeFalse();
    });
});
