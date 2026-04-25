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
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Utils\DeferredChunk;
use Bugo\SCSS\Utils\RawChunk;
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
        $result = $this->manager->buildRuleResult('', [new RawChunk('first'), new RawChunk('second')], []);

        expect($result)->toBe("first\nsecond\n");
    });

    it('collects merged media chunks into leading root chunks when children were not rendered yet', function () {
        $scope = $this->ctx->env->getCurrentScope();
        $scope->setVariableLocal('__at_rule_stack', [
            AtRuleContextEntry::directive('media', 'screen'),
        ]);

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
            ->and($leading[0]->content())->toContain('@media screen and print')
            ->and($trailing)->toBe([])
            ->and($scope->getVariable('__at_rule_stack'))->toEqual([
                AtRuleContextEntry::directive('media', 'screen'),
            ]);
    });

    it('defers merged media chunks into the at-rule stack when available', function () {
        $scope = $this->ctx->env->getCurrentScope();
        $scope->setVariableLocal('__at_rule_stack', [
            AtRuleContextEntry::directive('media', 'screen'),
        ]);

        $this->runtime->render()->outputState()->deferral->atRuleStack[] = [];

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
            ->and($this->runtime->render()->outputState()->deferral->atRuleStack[0])->toHaveCount(1)
            ->and($this->runtime->render()->outputState()->deferral->atRuleStack[0][0]->levels)->toBe(1)
            ->and($this->runtime->render()->outputState()->deferral->atRuleStack[0][0]->chunk)
            ->toContain('@media screen and print');
    });

    it('collects merged media chunks into trailing root chunks after children were rendered', function () {
        $scope = $this->ctx->env->getCurrentScope();
        $scope->setVariableLocal('__at_rule_stack', [
            AtRuleContextEntry::directive('media', 'screen'),
        ]);

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
            ->and($trailing[0]->content())->toContain('@media screen and print');
    });

    it('collects non-media bubbling chunks into trailing root chunks after children were rendered', function () {
        $scope    = $this->ctx->env->getCurrentScope();
        $leading  = [];
        $trailing = [];
        $child    = new SupportsNode('(display: grid)', [
            new RuleNode('.item', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ]);

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
            ->and($trailing[0]->content())->toContain('@supports (display: grid)')
            ->and($trailing[0]->content())->toContain('.host .item');
    });

    it('ignores empty @at-root chunks when collecting rule output', function () {
        $trailing = [];

        $this->manager->collectRuleAtRootChunk($trailing, new AtRootNode(), $this->ctx);

        expect($trailing)->toBe([]);
    });

    it('appends escaped @at-root chunks to trailing root chunks when deferral is unavailable', function () {
        $this->ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', [
            AtRuleContextEntry::directive('media', 'screen'),
        ]);

        $trailing = [];
        $child    = new AtRootNode([
            new RuleNode('.outside', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ]);

        $this->manager->collectRuleAtRootChunk($trailing, $child, $this->ctx);

        expect($trailing)->toHaveCount(1)
            ->and($trailing[0]->content())->toContain('.outside')
            ->and($trailing[0]->content())->toContain('color: red');
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
            ->and($trailing[0]->content())->toContain('.outside')
            ->and($trailing[0]->content())->toContain('color: red');
    });

    it('returns immediately when there are no deferred include root chunks to collect', function () {
        $existing = new RawChunk('existing');
        $trailing = [$existing];

        $this->manager->collectDeferredIncludeRootChunks($trailing, 0);

        expect($trailing)->toBe([$existing]);
    });

    it('moves deferred include root chunks into trailing root chunks when new chunks exist', function () {
        $deferredChunk = new DeferredChunk('.outside { color: red; }', 1, 0, []);
        $outputState   = $this->runtime->render()->outputState();
        $keep          = new RawChunk('keep');

        $outputState->deferral->atRootStack[] = [$keep, $deferredChunk];

        $trailing = [];

        $this->manager->collectDeferredIncludeRootChunks($trailing, 1);

        expect($trailing)->toBe([$deferredChunk])
            ->and($outputState->deferral->atRootStack[0])->toBe([$keep]);
    });

    it('returns immediately for empty included @at-root chunks', function () {
        $output = '';
        $state  = new class {
            public bool $first = true;
        };

        $this->manager->appendIncludeAtRootChunk($output, $state->first, new AtRootNode(), $this->ctx);
        $this->manager->appendOutputChunk($output, $state->first, new RawChunk('.after {}'));

        expect($output)->toBe('.after {}')
            ->and($state->first)->toBeFalse();
    });

    it('appends escaped included @at-root chunks directly to output without a parent selector', function () {
        $this->ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', [
            AtRuleContextEntry::directive('media', 'screen'),
        ]);

        $output = '';
        $state  = new class {
            public bool $first = true;
        };
        $child  = new AtRootNode([
            new RuleNode('.outside', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ]);

        $this->manager->appendIncludeAtRootChunk($output, $state->first, $child, $this->ctx);

        expect($output)->toContain('.outside')
            ->and($output)->toContain('color: red')
            ->and($state->first)->toBeFalse();
    });

    it('returns immediately for empty included bubbling chunks without a parent selector', function () {
        $output = '';
        $state  = new class {
            public bool $first = true;
        };
        $child  = new DirectiveNode('foo', 'bar', [], true);

        $this->manager->appendIncludeBubblingChunk($output, $state->first, $child, $this->ctx);

        expect($output)->toBe('')
            ->and($state->first)->toBeTrue();
    });

    it('defers supports include bubbling chunks when only the bubbling stack is available', function () {
        $outputState = $this->runtime->render()->outputState();
        $outputState->deferral->bubblingStack[] = [];

        $output = '';
        $state  = new class {
            public bool $first = true;
        };
        $child  = new SupportsNode('(display: grid)', [
            new RuleNode('.item', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ]);

        $this->manager->appendIncludeBubblingChunk($output, $state->first, $child, $this->ctx);

        expect($output)->toBe('')
            ->and($state->first)->toBeTrue()
            ->and($outputState->deferral->bubblingStack[0])->toHaveCount(1)
            ->and($outputState->deferral->bubblingStack[0][0]->content())->toContain('@supports (display: grid)');
    });

    it('appends bubbling include chunks directly to output when no deferral stack is available', function () {
        $output = '';
        $state  = new class {
            public bool $first = true;
        };
        $child  = new SupportsNode('(display: grid)', [
            new RuleNode('.item', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ]);

        $this->manager->appendIncludeBubblingChunk($output, $state->first, $child, $this->ctx);

        expect($output)->toContain('@supports (display: grid)')
            ->and($output)->toContain('color: red')
            ->and($state->first)->toBeFalse();
    });

    it('appends nested property include chunks directly to output', function () {
        $output = '';
        $state  = new class {
            public bool $first = true;
        };
        $child  = new RuleNode('font:', [
            new DeclarationNode('size', new NumberNode(12, 'px')),
        ]);

        $this->manager->appendIncludedRuleChunk($output, $state->first, $child, $this->ctx);

        expect($output)->toBe('font-size: 12px;')
            ->and($state->first)->toBeFalse();
    });

    it('returns immediately for included rules that compile to an empty chunk', function () {
        $output = '';
        $state  = new class {
            public bool $first = true;
        };
        $child  = new RuleNode('.empty', []);

        $this->manager->appendIncludedRuleChunk($output, $state->first, $child, $this->ctx);

        expect($output)->toBe('')
            ->and($state->first)->toBeTrue();
    });

    it('adds a newline before appending subsequent output chunks', function () {
        $output = '';
        $state  = new class {
            public bool $first = true;
        };

        $this->manager->appendOutputChunk($output, $state->first, new RawChunk('first'));
        $this->manager->appendOutputChunk($output, $state->first, new RawChunk('second'));

        expect($output)->toBe("first\nsecond")
            ->and($state->first)->toBeFalse();
    });

    it('returns false when there is no deferred bubbling stack', function () {
        expect($this->manager->appendDeferredBubblingChunk(new RawChunk('chunk')))->toBeFalse();
    });

    it('returns an interleaved merged media chunk when deferral is unavailable', function () {
        $scope = $this->ctx->env->getCurrentScope();
        $scope->setVariableLocal('__at_rule_stack', [
            AtRuleContextEntry::directive('media', 'screen'),
        ]);

        $child = new DirectiveNode('media', 'print', [
            new RuleNode('.item', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ], true);

        $chunk = $this->manager->compileInterleavedBubblingChunk(
            '.host',
            $scope,
            $child,
            $this->ctx,
        );

        expect($chunk)->not->toBeNull()
            ->and($chunk->content())->toContain('@media screen and print')
            ->and($chunk->content())->toContain('.host .item')
            ->and($scope->getVariable('__at_rule_stack'))->toEqual([
                AtRuleContextEntry::directive('media', 'screen'),
            ]);
    });

    it('defers interleaved merged media chunks into the at-rule stack when available', function () {
        $scope = $this->ctx->env->getCurrentScope();
        $scope->setVariableLocal('__at_rule_stack', [
            AtRuleContextEntry::directive('media', 'screen'),
        ]);

        $this->runtime->render()->outputState()->deferral->atRuleStack[] = [];

        $child = new DirectiveNode('media', 'print', [
            new RuleNode('.item', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ], true);

        $chunk = $this->manager->compileInterleavedBubblingChunk(
            '.host',
            $scope,
            $child,
            $this->ctx,
        );

        expect($chunk)->toBeNull()
            ->and($this->runtime->render()->outputState()->deferral->atRuleStack[0])->toHaveCount(1)
            ->and($this->runtime->render()->outputState()->deferral->atRuleStack[0][0]->levels)->toBe(1)
            ->and($this->runtime->render()->outputState()->deferral->atRuleStack[0][0]->chunk)
            ->toContain('@media screen and print');
    });

    it('returns null for empty interleaved bubbling chunks', function () {
        $chunk = $this->manager->compileInterleavedBubblingChunk(
            '.host',
            $this->ctx->env->getCurrentScope(),
            new DirectiveNode('foo', 'bar', [], true),
            $this->ctx,
        );

        expect($chunk)->toBeNull();
    });

    it('returns null for empty interleaved merged media chunks', function () {
        $scope = $this->ctx->env->getCurrentScope();
        $scope->setVariableLocal('__at_rule_stack', [
            AtRuleContextEntry::directive('media', 'screen'),
        ]);

        $chunk = $this->manager->compileInterleavedBubblingChunk(
            '.host',
            $scope,
            new DirectiveNode('media', 'print', [], true),
            $this->ctx,
        );

        expect($chunk)->toBeNull()
            ->and($scope->getVariable('__at_rule_stack'))->toEqual([
                AtRuleContextEntry::directive('media', 'screen'),
            ]);
    });

    it('finds the last media prelude even when newer non-media entries are above it on the stack', function () {
        $scope = $this->ctx->env->getCurrentScope();
        $scope->setVariableLocal('__at_rule_stack', [
            AtRuleContextEntry::directive('media', 'screen'),
            AtRuleContextEntry::supports('(display: grid)'),
        ]);

        $chunk = $this->manager->compileInterleavedBubblingChunk(
            '.host',
            $scope,
            new DirectiveNode('media', 'print', [
                new RuleNode('.item', [
                    new DeclarationNode('color', new StringNode('red')),
                ]),
            ], true),
            $this->ctx,
        );

        expect($chunk)->not->toBeNull()
            ->and($chunk->content())->toContain('@media screen and print')
            ->and($scope->getVariable('__at_rule_stack'))->toEqual([
                AtRuleContextEntry::directive('media', 'screen'),
                AtRuleContextEntry::supports('(display: grid)'),
            ]);
    });

    it('flushes trailing root chunks before appending standalone nested rule chunks', function () {
        $output             = ".host {\n  color: red;\n}";
        $trailingRootChunks = [new RawChunk(".outside {\n  color: blue;\n}")];
        $parent             = new RuleNode('.host', []);
        $child              = new RuleNode('.child', [
            new DeclarationNode('margin', new StringNode('0')),
        ]);

        $state = new class {
            public bool $hasRenderedChildren = true;

            public bool $containsStandaloneNestedRuleChunks = false;
        };

        $this->manager->collectNestedRuleChunk(
            $output,
            $state->hasRenderedChildren,
            '',
            '.host',
            $parent,
            $child,
            $state->containsStandaloneNestedRuleChunks,
            $trailingRootChunks,
            $this->ctx,
        );

        expect($output)->toContain(".host {\n  color: red;\n}\n}\n.outside")
            ->and($output)->toContain(".outside {\n  color: blue;\n}\n.host .child")
            ->and($trailingRootChunks)->toBe([])
            ->and($state->containsStandaloneNestedRuleChunks)->toBeTrue();
    });

    it('appends included @at-root chunks directly to output when root deferral is unavailable', function () {
        $output = '';
        $state  = new class {
            public bool $first = true;
        };
        $child  = new AtRootNode([
            new RuleNode('.outside', [
                new DeclarationNode('color', new StringNode('red')),
            ]),
        ]);

        $this->manager->appendIncludeAtRootChunk($output, $state->first, $child, $this->ctx);

        expect($output)->toContain('.outside')
            ->and($output)->toContain('color: red')
            ->and($state->first)->toBeFalse();
    });
});
