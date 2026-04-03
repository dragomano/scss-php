<?php

declare(strict_types=1);

use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Tests\ReflectionAccessor;
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
            $this->runtime->selector(),
        );
    });

    it('returns false when there is no deferred at-rule stack', function () {
        expect($this->manager->appendDeferredAtRuleChunk(1, '@media print {}'))->toBeFalse();
    });

    it('adds a newline between multiple leading root chunks when building the rule result', function () {
        $result = $this->manager->buildRuleResult('', ['first', 'second'], []);

        expect($result)->toBe("first\nsecond\n");
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
            $this->ctx,
        );

        expect($leading)->toHaveCount(1)
            ->and($leading[0]['chunk'])->toContain('@media screen and print')
            ->and($trailing)->toBe([])
            ->and($scope->getVariable('__at_rule_stack'))->toBe([[
                'type'    => 'directive',
                'name'    => 'media',
                'prelude' => 'screen',
            ]]);
    });

    it('defers merged media chunks into the at-rule stack when available', function () {
        $scope = $this->ctx->env->getCurrentScope();
        $scope->setVariableLocal('__at_rule_stack', [[
            'type'    => 'directive',
            'name'    => 'media',
            'prelude' => 'screen',
        ]]);

        $this->runtime->render()->outputState()->deferredAtRuleStack[] = [];

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
            $this->ctx,
        );

        expect($leading)->toBe([])
            ->and($trailing)->toBe([])
            ->and($this->runtime->render()->outputState()->deferredAtRuleStack[0])->toHaveCount(1)
            ->and($this->runtime->render()->outputState()->deferredAtRuleStack[0][0]['levels'])->toBe(1)
            ->and($this->runtime->render()->outputState()->deferredAtRuleStack[0][0]['chunk'])
            ->toContain('@media screen and print');
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
            $this->ctx,
        );

        expect($leading)->toBe([])
            ->and($trailing)->toHaveCount(1)
            ->and($trailing[0]['chunk'])->toContain('@media screen and print');
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
            ->and($trailing[0]['chunk'])->toContain('.outside')
            ->and($trailing[0]['chunk'])->toContain('color: red');
    });

    it('collects non-escaped @at-root chunks into trailing root chunks', function () {
        $trailing = [];
        $child    = new AtRootNode([
            new RuleNode('.outside', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ], 'without', ['all']);

        $this->manager->collectRuleAtRootChunk($trailing, $child, $this->ctx);

        expect($trailing)->toHaveCount(1)
            ->and($trailing[0]['chunk'])->toContain('.outside')
            ->and($trailing[0]['chunk'])->toContain('color: red');
    });

    it('returns immediately when there are no deferred include root chunks to collect', function () {
        $trailing = ['existing'];

        $this->manager->collectDeferredIncludeRootChunks($trailing, 0);

        expect($trailing)->toBe(['existing']);
    });

    it('moves deferred include root chunks into trailing root chunks when new chunks exist', function () {
        $deferredChunk = [
            'chunk'      => '.outside { color: red; }',
            'baseLine'   => 1,
            'baseColumn' => 0,
            'mappings'   => [],
        ];
        $outputState = $this->runtime->render()->outputState();
        $outputState->deferredAtRootStack[] = ['keep', $deferredChunk];
        $trailing = [];

        $this->manager->collectDeferredIncludeRootChunks($trailing, 1);

        expect($trailing)->toBe([$deferredChunk])
            ->and($outputState->deferredAtRootStack[0])->toBe(['keep']);
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

    it('returns immediately for empty included bubbling chunks without a parent selector', function () {
        $output = '';
        $first  = true;
        $child  = new DirectiveNode('foo', 'bar', [], true);

        $this->manager->appendIncludeBubblingChunk($output, $first, $child, $this->ctx);

        expect($output)->toBe('')
            ->and($first)->toBeTrue();
    });

    it('defers supports include bubbling chunks when only the bubbling stack is available', function () {
        $outputState = $this->runtime->render()->outputState();
        $outputState->deferredBubblingStack[] = [];

        $output = '';
        $first  = true;
        $child  = new SupportsNode('(display: grid)', [
            new RuleNode('.item', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ]);

        $this->manager->appendIncludeBubblingChunk($output, $first, $child, $this->ctx);

        expect($output)->toBe('')
            ->and($first)->toBeTrue()
            ->and($outputState->deferredBubblingStack[0])->toHaveCount(1)
            ->and($outputState->deferredBubblingStack[0][0]['chunk'])->toContain('@supports (display: grid)');
    });

    it('appends bubbling include chunks directly to output when no deferral stack is available', function () {
        $output = '';
        $first  = true;
        $child  = new SupportsNode('(display: grid)', [
            new RuleNode('.item', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ]);

        $this->manager->appendIncludeBubblingChunk($output, $first, $child, $this->ctx);

        expect($output)->toContain('@supports (display: grid)')
            ->and($output)->toContain('color: red')
            ->and($first)->toBeFalse();
    });

    it('appends nested property include chunks directly to output', function () {
        $output = '';
        $first  = true;
        $child  = new RuleNode('font:', [
            new DeclarationNode('size', new NumberNode(12, 'px')),
        ]);

        $this->manager->appendIncludedRuleChunk($output, $first, $child, $this->ctx);

        expect($output)->toBe('font-size: 12px;')
            ->and($first)->toBeFalse();
    });

    it('returns immediately for included rules that compile to an empty chunk', function () {
        $output = '';
        $first  = true;
        $child  = new RuleNode('.empty', []);

        $this->manager->appendIncludedRuleChunk($output, $first, $child, $this->ctx);

        expect($output)->toBe('')
            ->and($first)->toBeTrue();
    });

    it('adds a newline before appending subsequent output chunks', function () {
        $output = '';
        $first  = true;
        $origin = new RuleNode('.x', []);

        $this->manager->appendOutputChunk($output, $first, 'first', $origin);
        $this->manager->appendOutputChunk($output, $first, 'second', $origin);

        expect($output)->toBe("first\nsecond")
            ->and($first)->toBeFalse();
    });

    it('returns false when there is no deferred bubbling stack', function () {
        expect($this->manager->appendDeferredBubblingChunk('chunk'))->toBeFalse();
    });

    it('flushes trailing root chunks before appending standalone nested rule chunks', function () {
        $output             = ".host {\n  color: red;\n}";
        $trailingRootChunks = [".outside {\n  color: blue;\n}"];
        $parent             = new RuleNode('.host', []);
        $child              = new RuleNode('.child', [
            new DeclarationNode('margin', new StringNode('0')),
        ]);

        $hasRenderedChildren                = true;
        $containsStandaloneNestedRuleChunks = false;

        $this->manager->collectNestedRuleChunk(
            $output,
            $hasRenderedChildren,
            '',
            '.host',
            $parent,
            $child,
            $containsStandaloneNestedRuleChunks,
            $trailingRootChunks,
            $this->ctx,
        );

        expect($output)->toContain(".host {\n  color: red;\n}\n}\n.outside")
            ->and($output)->toContain(".outside {\n  color: blue;\n}\n.host .child")
            ->and($trailingRootChunks)->toBe([])
            ->and($containsStandaloneNestedRuleChunks)->toBeTrue();
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

    it('returns the original at-rule stack when there is no media entry to remove', function () {
        $accessor = new ReflectionAccessor($this->manager);
        $stack    = [[
            'type'      => 'supports',
            'condition' => '(display: grid)',
        ]];

        expect($accessor->callMethod('removeLastMediaEntryFromAtRuleStack', [$stack]))->toBe($stack);
    });

    it('marks supports nodes for trailing-root bubbling deferral', function () {
        $accessor = new ReflectionAccessor($this->manager);

        expect($accessor->callMethod(
            'shouldDeferBubblingChunkToTrailingRoot',
            [new SupportsNode('(display: grid)')],
        ))->toBeTrue();
    });
});
