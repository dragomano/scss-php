<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Rule;

use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DiagnosticNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\ReturnNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;

use function count;

final readonly class ChildrenCompilationStep implements CompilationStepInterface
{
    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Evaluator $evaluation,
        private Render $render,
        private DeferredChunkManager $chunks,
    ) {}

    public function execute(RuleCompilationContext $ruleCtx): ?string
    {
        $childCtx    = $ruleCtx->childCtx;
        $selector    = $ruleCtx->selector;
        $scope       = $ruleCtx->outerCtx->env->getCurrentScope();
        $outputState = $this->render->outputState();

        foreach ($ruleCtx->node->children as $child) {
            if (
                $child instanceof VariableDeclarationNode
                || $child instanceof ModuleVarDeclarationNode
                || $child instanceof DiagnosticNode
            ) {
                $this->dispatcher->compileWithContext($child, $childCtx);

                continue;
            }

            if ($child instanceof ExtendNode) {
                continue;
            }

            if ($child instanceof IncludeNode) {
                $ruleCtx->requiresRuleBlockOptimization = true;
            }

            if ($child instanceof AtRootNode) {
                $this->chunks->collectRuleAtRootChunk(
                    $ruleCtx->trailingRootChunks,
                    $child,
                    $ruleCtx->outerCtx,
                );

                continue;
            }

            if ($this->evaluation->isBubblingAtRuleNode($child)) {
                if ($ruleCtx->hasRenderedChildren) {
                    $ruleCtx->output = $this->render->trimTrailingNewlines($ruleCtx->output);

                    $this->render->appendChunk($ruleCtx->output, "\n" . $ruleCtx->prefix . '}');

                    $ruleCtx->hasRenderedChildren           = false;
                    $ruleCtx->requiresRuleBlockOptimization = false;

                    $interleavedChunk = $this->chunks->compileInterleavedBubblingChunk(
                        $selector,
                        $scope,
                        $child,
                        $ruleCtx->outerCtx,
                    );

                    if ($interleavedChunk !== null) {
                        if ($ruleCtx->output !== '') {
                            $this->render->appendChunk($ruleCtx->output, $this->render->outputSeparator());
                        }

                        $this->chunks->appendResolvedChunk($ruleCtx->output, $interleavedChunk);
                    }
                } else {
                    $this->chunks->collectRuleBubblingChunk(
                        $ruleCtx->leadingRootChunks,
                        $ruleCtx->trailingRootChunks,
                        $ruleCtx->hasRenderedChildren,
                        $selector,
                        $scope,
                        $child,
                        $ruleCtx->outerCtx,
                    );
                }

                continue;
            }

            if ($child instanceof RuleNode) {
                $this->chunks->collectNestedRuleChunk(
                    $ruleCtx->output,
                    $ruleCtx->hasRenderedChildren,
                    $ruleCtx->prefix,
                    $selector,
                    $ruleCtx->node,
                    $child,
                    $ruleCtx->containsStandaloneNestedRuleChunks,
                    $ruleCtx->trailingRootChunks,
                    $ruleCtx->outerCtx,
                );

                continue;
            }

            $deferredAtRootCount = null;

            if ($child instanceof IncludeNode) {
                $atRootStackIndex    = count($outputState->deferral->atRootStack) - 1;
                $deferredAtRootCount = count($outputState->deferral->atRootStack[$atRootStackIndex]);
            }

            $this->assertCompilableRuleChild($child);

            $savedPosition = null;

            if ($this->render->collectSourceMappings() && ! $child instanceof DeclarationNode) {
                $savedPosition = $this->render->savePosition();
            }

            /** @var Visitable $child */
            $compiled = $this->render->trimAndAdjustState(
                $this->dispatcher->compileWithContext($child, $childCtx),
            );

            if ($compiled !== '' && ! $ruleCtx->omitOwnRuleOutput) {
                if ($child instanceof DeclarationNode) {
                    $this->renderRuleOpeningIfNeeded($ruleCtx);

                    $this->render->appendChunk($ruleCtx->output, "\n");
                    $this->render->appendChunk($ruleCtx->output, $compiled, $child);
                } else {
                    if ($savedPosition !== null) {
                        $deferredChunk = $this->render->createDeferredChunk($compiled, $savedPosition);

                        $this->render->restorePosition($savedPosition);

                        $this->renderRuleOpeningIfNeeded($ruleCtx);

                        $this->render->appendChunk($ruleCtx->output, "\n");
                        $this->render->appendDeferredChunk($ruleCtx->output, $deferredChunk);
                    } else {
                        $this->renderRuleOpeningIfNeeded($ruleCtx);

                        $this->render->appendChunk($ruleCtx->output, "\n");

                        $ruleCtx->output .= $compiled;
                    }
                }
            }

            if ($child instanceof IncludeNode && $deferredAtRootCount !== null) {
                $this->chunks->collectDeferredIncludeRootChunks(
                    $ruleCtx->trailingRootChunks,
                    $deferredAtRootCount,
                );
            }
        }

        return null;
    }

    private function assertCompilableRuleChild(AstNode $child): void
    {
        if ($child instanceof ReturnNode) {
            throw new SassErrorException('This at-rule is not allowed here.');
        }
    }

    private function renderRuleOpeningIfNeeded(RuleCompilationContext $ruleCtx): void
    {
        if ($ruleCtx->hasRenderedChildren) {
            return;
        }

        if ($ruleCtx->output !== '') {
            $this->render->appendChunk($ruleCtx->output, "\n");
        }

        $this->render->appendChunk(
            $ruleCtx->output,
            $ruleCtx->prefix . $ruleCtx->selector . ' {',
            $ruleCtx->node,
        );

        $ruleCtx->hasRenderedChildren = true;
    }
}
