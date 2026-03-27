<?php

declare(strict_types=1);

use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Tests\RuntimeFactory;

describe('DeferredChunkManager', function () {
    beforeEach(function () {
        $this->runtime = RuntimeFactory::createRuntime();
        $this->ctx     = RuntimeFactory::context();
        $this->manager = new DeferredChunkManager(
            $this->runtime->dispatcher(),
            $this->runtime->context(),
            $this->runtime->evaluation(),
            $this->runtime->render(),
            $this->runtime->selector()
        );
    });

    it('returns false when there is no deferred at-rule stack', function () {
        expect($this->manager->appendDeferredAtRuleChunk(1, '@media print {}'))->toBeFalse();
    });

    it('collects merged media chunks into leading root chunks when children were not rendered yet', function () {
        $scope = $this->ctx->env->getCurrentScope();
        $scope->setVariableLocal('__at_rule_stack', [[
            'type'    => 'directive',
            'name'    => 'media',
            'prelude' => 'screen',
        ]]);

        $leading  = [];
        $trailing = [];
        $child    = new DirectiveNode('media', 'print', [
            new RuleNode('.item', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ], true);

        $this->manager->collectRuleBubblingChunk(
            $leading,
            $trailing,
            false,
            '.host',
            $scope,
            $child,
            $this->ctx
        );

        expect($leading)->toHaveCount(1)
            ->and($leading[0])->toContain('@media screen and print')
            ->and($trailing)->toBe([])
            ->and($scope->getVariable('__at_rule_stack'))->toBe([[
                'type'    => 'directive',
                'name'    => 'media',
                'prelude' => 'screen',
            ]]);
    });

    it('collects merged media chunks into trailing root chunks after children were rendered', function () {
        $scope = $this->ctx->env->getCurrentScope();
        $scope->setVariableLocal('__at_rule_stack', [[
            'type'    => 'directive',
            'name'    => 'media',
            'prelude' => 'screen',
        ]]);

        $leading  = [];
        $trailing = [];
        $child    = new DirectiveNode('media', 'print', [
            new RuleNode('.item', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ], true);

        $this->manager->collectRuleBubblingChunk(
            $leading,
            $trailing,
            true,
            '.host',
            $scope,
            $child,
            $this->ctx
        );

        expect($leading)->toBe([])
            ->and($trailing)->toHaveCount(1)
            ->and($trailing[0])->toContain('@media screen and print');
    });

    it('ignores empty @at-root chunks when collecting rule output', function () {
        $trailing = [];

        $this->manager->collectRuleAtRootChunk($trailing, new AtRootNode(), $this->ctx);

        expect($trailing)->toBe([]);
    });

    it('appends escaped @at-root chunks to trailing root chunks when deferral is unavailable', function () {
        $this->ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', [[
            'type'    => 'directive',
            'name'    => 'media',
            'prelude' => 'screen',
        ]]);

        $trailing = [];
        $child    = new AtRootNode([
            new RuleNode('.outside', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ]);

        $this->manager->collectRuleAtRootChunk($trailing, $child, $this->ctx);

        expect($trailing)->toHaveCount(1)
            ->and($trailing[0])->toContain('.outside')
            ->and($trailing[0])->toContain('color: red');
    });

    it('returns immediately when there are no deferred include root chunks to collect', function () {
        $trailing = ['existing'];

        $this->manager->collectDeferredIncludeRootChunks($trailing, 0);

        expect($trailing)->toBe(['existing']);
    });

    it('returns immediately for empty included @at-root chunks', function () {
        $output = '';
        $first  = true;

        $this->manager->appendIncludeAtRootChunk($output, $first, new AtRootNode(), $this->ctx);

        expect($output)->toBe('')
            ->and($first)->toBeTrue();
    });

    it('appends escaped included @at-root chunks directly to output without a parent selector', function () {
        $this->ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', [[
            'type'    => 'directive',
            'name'    => 'media',
            'prelude' => 'screen',
        ]]);

        $output = '';
        $first  = true;
        $child  = new AtRootNode([
            new RuleNode('.outside', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ]);

        $this->manager->appendIncludeAtRootChunk($output, $first, $child, $this->ctx);

        expect($output)->toContain('.outside')
            ->and($output)->toContain('color: red')
            ->and($first)->toBeFalse();
    });

    it('appends included @at-root chunks directly to output when root deferral is unavailable', function () {
        $output = '';
        $first  = true;
        $child  = new AtRootNode([
            new RuleNode('.outside', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ]);

        $this->manager->appendIncludeAtRootChunk($output, $first, $child, $this->ctx);

        expect($output)->toContain('.outside')
            ->and($output)->toContain('color: red')
            ->and($first)->toBeFalse();
    });
});
