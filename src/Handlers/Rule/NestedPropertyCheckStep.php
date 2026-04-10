<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Rule;

use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\Services\Render;

use function array_pop;

final readonly class NestedPropertyCheckStep implements CompilationStepInterface
{
    public function __construct(private DeferredChunkManager $chunks, private Render $render) {}

    public function execute(RuleCompilationContext $ruleCtx): ?string
    {
        $compiled = $this->chunks->compileNestedPropertyBlock(
            $ruleCtx->node,
            $ruleCtx->selector,
            $ruleCtx->outerCtx,
            $ruleCtx->outerCtx->indent,
        );

        if ($compiled !== null) {
            $outputState = $this->render->outputState();

            array_pop($outputState->deferral->atRootStack);
            array_pop($outputState->deferral->bubblingStack);

            return $compiled;
        }

        return null;
    }
}
