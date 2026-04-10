<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Rule;

use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Utils\OutputChunk;

use function array_pop;

final readonly class OutputFormattingStep implements CompilationStepInterface
{
    public function __construct(
        private Render $render,
        private Selector $selector,
        private DeferredChunkManager $chunks,
    ) {}

    public function execute(RuleCompilationContext $ruleCtx): string
    {
        if ($ruleCtx->hasRenderedChildren) {
            $ruleCtx->output = $this->render->trimTrailingNewlines($ruleCtx->output);

            $this->render->appendChunk($ruleCtx->output, "\n" . $ruleCtx->prefix . '}');
        }

        if (
            $ruleCtx->requiresRuleBlockOptimization
            && ! $ruleCtx->containsStandaloneNestedRuleChunks
            && $ruleCtx->output !== ''
            && ! $this->render->collectSourceMappings()
        ) {
            $ruleCtx->output = $this->selector->optimizeRuleBlock($ruleCtx->output);
        }

        $outputState = $this->render->outputState();

        /** @var list<OutputChunk> $localTrailingRootChunks */
        $localTrailingRootChunks = array_pop($outputState->deferral->atRootStack);

        /** @var list<OutputChunk> $localLeadingRootChunks */
        $localLeadingRootChunks = array_pop($outputState->deferral->bubblingStack);

        foreach ($localLeadingRootChunks as $chunk) {
            $ruleCtx->leadingRootChunks[] = $chunk;
        }

        foreach ($localTrailingRootChunks as $chunk) {
            $ruleCtx->trailingRootChunks[] = $chunk;
        }

        return $this->chunks->buildRuleResult(
            $ruleCtx->output,
            $ruleCtx->leadingRootChunks,
            $ruleCtx->trailingRootChunks,
        );
    }
}
