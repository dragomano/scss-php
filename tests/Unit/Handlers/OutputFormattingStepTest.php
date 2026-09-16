<?php

declare(strict_types=1);

use Bugo\SCSS\Handlers\Rule\OutputFormattingStep;
use Bugo\SCSS\Handlers\Rule\RuleCompilationContext;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Utils\GroupStartChunk;
use Bugo\SCSS\Utils\RawChunk;
use Tests\Support\RuntimeFactory;

describe('OutputFormattingStep', function () {
    beforeEach(function () {
        $this->runtime = RuntimeFactory::createRuntime();
        $this->ctx     = RuntimeFactory::context();
        $this->step    = new OutputFormattingStep(
            $this->runtime->render(),
            $this->runtime->selector(),
            $this->runtime->deferredChunks(),
        );
    });

    it('hoists early non-nested group starts from the popped level into leading chunks', function () {
        $outputState = $this->runtime->render()->outputState();
        $earlyChunk  = new GroupStartChunk(new RawChunk('.early {}'), isEarly: true);

        $outputState->deferral->atRootStack   = [[], [$earlyChunk]];
        $outputState->deferral->bubblingStack = [[], []];

        $ruleCtx  = new RuleCompilationContext(new RuleNode('.a', []), $this->ctx, '', $this->ctx);
        $expected = /** @lang text */ <<<'CSS'
        .early {}
        CSS;

        expect($this->step->execute($ruleCtx))->toBe($expected . "\n")
            ->and($ruleCtx->leadingRootChunks)->toEqual([$earlyChunk])
            ->and($ruleCtx->trailingRootChunks)->toBe([]);
    });

    it('keeps nested group starts and plain chunks in trailing root chunks while hoisting', function () {
        $outputState = $this->runtime->render()->outputState();
        $nestedChunk = new GroupStartChunk(new RawChunk('.nested {}'), isNested: true);
        $plainChunk  = new RawChunk('.plain {}');

        $outputState->deferral->atRootStack   = [[], [$nestedChunk, $plainChunk]];
        $outputState->deferral->bubblingStack = [[], []];

        $ruleCtx  = new RuleCompilationContext(new RuleNode('.a', []), $this->ctx, '', $this->ctx);
        $expected = /** @lang text */ <<<'CSS'
        .nested {}
        CSS;

        expect($this->step->execute($ruleCtx))
            ->toBe($expected . "\n" . Render::CONTINUATION_MARK . ".plain {}\n")
            ->and($ruleCtx->leadingRootChunks)->toBe([])
            ->and($ruleCtx->trailingRootChunks)->toEqual([$nestedChunk, $plainChunk]);
    });
});
