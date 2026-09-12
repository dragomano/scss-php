<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Rule;

use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DiagnosticNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\ReturnNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;

use function count;
use function is_array;
use function str_replace;

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
        $selector    = $ruleCtx->parentSelector !== '' ? $ruleCtx->parentSelector : $ruleCtx->selector;
        $scope       = $ruleCtx->outerCtx->env->getCurrentScope();
        $outputState = $this->render->outputState();

        $lastRenderedLine = $ruleCtx->node->openBraceLine ?: $ruleCtx->node->line;

        foreach ($ruleCtx->node->children as $child) {
            $inlinesBody = $child instanceof IncludeNode || $child instanceof ImportNode;

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

            if ($inlinesBody) {
                $ruleCtx->requiresRuleBlockOptimization = true;
            }

            if ($child instanceof AtRootNode) {
                $this->chunks->collectRuleAtRootChunk(
                    $ruleCtx->leadingRootChunks,
                    $ruleCtx->trailingRootChunks,
                    $child,
                    $ruleCtx->outerCtx,
                );

                continue;
            }

            if ($this->evaluation->isBubblingAtRuleNode($child) && ! $this->isInsideKeyframes($scope)) {
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
                            $this->render->appendChunk($ruleCtx->output, "\n" . Render::CONTINUATION_MARK);
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
                        $ruleCtx->containsStandaloneNestedRuleChunks || $ruleCtx->output !== '',
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
            $atRootStackIndex    = count($outputState->deferral->atRootStack) - 1;

            if ($inlinesBody) {
                $scope->setVariableLocal('__parent_rule_has_rendered_children', $ruleCtx->hasRenderedChildren);
            }

            if ($atRootStackIndex >= 0) {
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
                if ($child instanceof CommentNode && $child->line === $lastRenderedLine) {
                    $this->renderRuleOpeningIfNeeded($ruleCtx);

                    if ($savedPosition !== null) {
                        $this->render->restorePosition($savedPosition);
                    }

                    $this->render->appendChunk($ruleCtx->output, ' ' . ltrim($compiled), $child);

                    $lastRenderedLine = $child->line;
                } elseif ($child instanceof DeclarationNode) {
                    $this->renderRuleOpeningIfNeeded($ruleCtx);

                    $this->render->appendChunk($ruleCtx->output, "\n");
                    $this->render->appendChunk($ruleCtx->output, $compiled, $child);

                    $lastRenderedLine = $child->line;
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

            if ($deferredAtRootCount !== null) {
                $this->chunks->interleaveDeferredRootChunks(
                    $ruleCtx->output,
                    $ruleCtx->hasRenderedChildren,
                    $ruleCtx->prefix,
                    $ruleCtx->containsStandaloneNestedRuleChunks,
                    $deferredAtRootCount,
                    $ruleCtx->leadingRootChunks,
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
            $separator = $ruleCtx->containsStandaloneNestedRuleChunks
                ? "\n" . Render::CONTINUATION_MARK
                : "\n";

            $this->render->appendChunk($ruleCtx->output, $separator);
        }

        $formattedSelector = str_replace("\n", "\n" . $ruleCtx->prefix, $ruleCtx->selector);

        $this->render->appendChunk(
            $ruleCtx->output,
            $ruleCtx->prefix . $formattedSelector . ' {',
            $ruleCtx->node,
        );

        $ruleCtx->hasRenderedChildren = true;

        $this->render->outputState()->deferral->currentRuleHasOutput = true;
    }

    private function isInsideKeyframes(Scope $scope): bool
    {
        if (! $scope->hasVariable('__at_rule_stack')) {
            return false;
        }

        $atRuleStack = $scope->getVariable('__at_rule_stack');

        if (! is_array($atRuleStack)) {
            return false;
        }

        /** @var list<AtRuleContextEntry|array<string, mixed>> $atRuleStack */
        foreach ($atRuleStack as $entry) {
            if ($entry instanceof AtRuleContextEntry && $entry->name !== null && str_ends_with($entry->name, 'keyframes')) {
                return true;
            }
        }

        return false;
    }
}
