<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Rule;

use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\States\OutputState;
use Bugo\SCSS\Utils\GroupStartChunk;
use Bugo\SCSS\Utils\OutputChunk;

use function array_pop;
use function array_values;
use function count;
use function str_ends_with;

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

            if ($this->isSingleInlineCommentBody($ruleCtx)) {
                $this->render->appendChunk($ruleCtx->output, ' }');
            } else {
                $this->render->appendChunk($ruleCtx->output, "\n" . $ruleCtx->prefix . '}');
            }
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

        $depth = count($outputState->deferral->atRootStack);

        /** @var list<OutputChunk> $localTrailingRootChunks */
        $localTrailingRootChunks = array_pop($outputState->deferral->atRootStack);

        /** @var list<OutputChunk> $localLeadingRootChunks */
        $localLeadingRootChunks = array_pop($outputState->deferral->bubblingStack);

        foreach ($localLeadingRootChunks as $chunk) {
            $ruleCtx->leadingRootChunks[] = $chunk;
        }

        foreach ($localTrailingRootChunks as $chunk) {
            if ($chunk instanceof GroupStartChunk) {
                if (! $chunk->isNested && $chunk->isEarly) {
                    $ruleCtx->leadingRootChunks[] = $chunk;

                    continue;
                }
            }

            $ruleCtx->trailingRootChunks[] = $chunk;
        }

        if ($depth > 1) {
            $ruleCtx->trailingRootChunks = $this->hoistNestedGroupStarts(
                $ruleCtx->trailingRootChunks,
                $outputState,
            );
        }

        return $this->chunks->buildRuleResult(
            $ruleCtx->output,
            $ruleCtx->leadingRootChunks,
            $ruleCtx->trailingRootChunks,
        );
    }

    private function isSingleInlineCommentBody(RuleCompilationContext $ruleCtx): bool
    {
        if (! str_ends_with($ruleCtx->output, '*/')) {
            return false;
        }

        $children = $ruleCtx->node->children;

        if (count($children) !== 1) {
            return false;
        }

        $child = array_pop($children);

        if (! $child instanceof CommentNode) {
            return false;
        }

        return $child->line === ($ruleCtx->node->openBraceLine ?: $ruleCtx->node->line);
    }

    /**
     * @param list<OutputChunk> $chunks
     * @return list<OutputChunk>
     */
    private function hoistNestedGroupStarts(array $chunks, OutputState $outputState): array
    {
        foreach ($chunks as $index => $chunk) {
            if (! $chunk instanceof GroupStartChunk || $chunk->isNested) {
                continue;
            }

            unset($chunks[$index]);

            $parentIndex = count($outputState->deferral->atRootStack) - 1;

            $outputState->deferral->atRootStack[$parentIndex][] = new GroupStartChunk($chunk->inner(), true);
        }

        return array_values($chunks);
    }
}
