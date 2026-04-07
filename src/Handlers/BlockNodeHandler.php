<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Builtins\FunctionRegistry;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\Handlers\Block\MixinHandler;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DiagnosticNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\ReturnNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Module;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Style;

use function array_pop;
use function count;
use function implode;
use function str_contains;
use function trim;

final readonly class BlockNodeHandler
{
    private DeferredChunkManager $chunks;

    private MixinHandler $mixin;

    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Context $context,
        private Evaluator $evaluation,
        FunctionRegistry $registry,
        private Module $module,
        private Render $render,
        private Selector $selector,
    ) {
        $this->chunks = new DeferredChunkManager(
            $this->dispatcher,
            $this->context,
            $this->evaluation,
            $this->render,
            $this->selector,
        );

        $this->mixin = new MixinHandler(
            $this->dispatcher,
            $this->evaluation,
            $registry,
            $this->module,
            $this->render,
            $this->selector,
            $this->chunks,
        );
    }

    public function handleInclude(IncludeNode $node, TraversalContext $ctx): string
    {
        return $this->mixin->handleInclude($node, $ctx);
    }

    public function handleRule(RuleNode $node, TraversalContext $ctx): string
    {
        $output = '';
        $prefix = $this->render->indentPrefix($ctx->indent);

        $ctx->env->enterScope();

        $childCtx = new TraversalContext($ctx->env, $ctx->indent + 1);

        try {
            $scope       = $ctx->env->getCurrentScope();
            $outputState = $this->render->outputState();

            $outputState->deferredAtRootStack[]   = [];
            $outputState->deferredBubblingStack[] = [];

            $requiresRuleBlockOptimization      = false;
            $containsStandaloneNestedRuleChunks = false;

            /** @var array<int, string> $leadingRootChunks */
            $leadingRootChunks  = [];
            /** @var array<int, string> $trailingRootChunks */
            $trailingRootChunks = [];

            $selector = str_contains($node->selector, '#{')
                ? $this->evaluation->interpolateText($node->selector, $ctx->env)
                : $node->selector;

            $scopeParentSelector = $scope->getStringVariable('__parent_selector')?->value;
            $atRootVar           = $scope->getAstVariable('__at_root_context');
            $isAtRootContext     = $atRootVar instanceof BooleanNode && $atRootVar->value;

            if (
                $isAtRootContext
                && $scopeParentSelector !== null
                && str_contains($selector, '&')
                && ! str_contains($scopeParentSelector, '%')
            ) {
                $selector = $this->selector->resolveNestedSelector($selector, $scopeParentSelector);
            }

            $parentSelector = $selector;

            $selector          = $this->selector->applyExtendsToSelector($selector);
            $omitOwnRuleOutput = $this->selector->hasBogusTopLevelCombinatorSequence($selector);

            if ($selector === '') {
                array_pop($outputState->deferredAtRootStack);
                array_pop($outputState->deferredBubblingStack);

                return '';
            }

            $scope->setVariableLocal('__parent_selector', new StringNode($parentSelector));

            $compiledNestedPropertyBlock = $this->chunks->compileNestedPropertyBlock($node, $selector, $ctx, $ctx->indent);

            if ($compiledNestedPropertyBlock !== null) {
                array_pop($outputState->deferredAtRootStack);
                array_pop($outputState->deferredBubblingStack);

                return $compiledNestedPropertyBlock;
            }

            $hasRenderedChildren = false;

            foreach ($node->children as $child) {
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
                    $requiresRuleBlockOptimization = true;
                }

                if ($child instanceof AtRootNode) {
                    $this->chunks->collectRuleAtRootChunk($trailingRootChunks, $child, $ctx);

                    continue;
                }

                if ($this->evaluation->isBubblingAtRuleNode($child)) {
                    if ($hasRenderedChildren) {
                        $output = $this->render->trimTrailingNewlines($output);

                        $this->render->appendChunk($output, "\n" . $prefix . '}');

                        $hasRenderedChildren           = false;
                        $requiresRuleBlockOptimization = false;

                        $interleavedChunk = $this->chunks->compileInterleavedBubblingChunk(
                            $selector,
                            $scope,
                            $child,
                            $ctx,
                        );

                        if ($interleavedChunk !== null) {
                            if ($output !== '') {
                                $this->render->appendChunk($output, $this->render->outputSeparator());
                            }

                            $this->chunks->appendResolvedChunk($output, $interleavedChunk);
                        }
                    } else {
                        $this->chunks->collectRuleBubblingChunk(
                            $leadingRootChunks,
                            $trailingRootChunks,
                            $hasRenderedChildren,
                            $selector,
                            $scope,
                            $child,
                            $ctx,
                        );
                    }

                    continue;
                }

                if ($child instanceof RuleNode) {
                    $this->chunks->collectNestedRuleChunk(
                        $output,
                        $hasRenderedChildren,
                        $prefix,
                        $selector,
                        $node,
                        $child,
                        $containsStandaloneNestedRuleChunks,
                        $trailingRootChunks,
                        $ctx,
                    );

                    continue;
                }

                $deferredAtRootCount = null;

                if ($child instanceof IncludeNode) {
                    $atRootStackIndex    = count($outputState->deferredAtRootStack) - 1;
                    $deferredAtRootCount = count($outputState->deferredAtRootStack[$atRootStackIndex]);
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

                if ($compiled !== '' && ! $omitOwnRuleOutput) {
                    if ($child instanceof DeclarationNode) {
                        if (! $hasRenderedChildren) {
                            if ($output !== '') {
                                $this->render->appendChunk($output, "\n");
                            }

                            $this->render->appendChunk($output, $prefix . $selector . ' {', $node);

                            $hasRenderedChildren = true;
                        }

                        $this->render->appendChunk($output, "\n");
                        $this->render->appendChunk($output, $compiled, $child);
                    } else {
                        if ($savedPosition !== null) {
                            $deferredChunk = $this->render->createDeferredChunk($compiled, $savedPosition);

                            $this->render->restorePosition($savedPosition);

                            if (! $hasRenderedChildren) {
                                if ($output !== '') {
                                    $this->render->appendChunk($output, "\n");
                                }

                                $this->render->appendChunk($output, $prefix . $selector . ' {', $node);

                                $hasRenderedChildren = true;
                            }

                            $this->render->appendChunk($output, "\n");
                            $this->render->appendDeferredChunk($output, $deferredChunk);
                        } else {
                            if (! $hasRenderedChildren) {
                                if ($output !== '') {
                                    $this->render->appendChunk($output, "\n");
                                }

                                $this->render->appendChunk($output, $prefix . $selector . ' {', $node);

                                $hasRenderedChildren = true;
                            }

                            $this->render->appendChunk($output, "\n");

                            $output .= $compiled;
                        }
                    }
                }

                if ($child instanceof IncludeNode && $deferredAtRootCount !== null) {
                    $this->chunks->collectDeferredIncludeRootChunks($trailingRootChunks, $deferredAtRootCount);
                }
            }

            if ($hasRenderedChildren) {
                $output = $this->render->trimTrailingNewlines($output);

                $this->render->appendChunk($output, "\n" . $prefix . '}');
            }

            if (
                $requiresRuleBlockOptimization
                && ! $containsStandaloneNestedRuleChunks
                && $output !== ''
                && ! $this->render->collectSourceMappings()
            ) {
                $output = $this->selector->optimizeRuleBlock($output);
            }

            /** @var array<int, string> $localTrailingRootChunks */
            $localTrailingRootChunks = array_pop($outputState->deferredAtRootStack);

            /** @var array<int, string> $localLeadingRootChunks */
            $localLeadingRootChunks = array_pop($outputState->deferredBubblingStack);

            foreach ($localLeadingRootChunks as $chunk) {
                $leadingRootChunks[] = $chunk;
            }

            foreach ($localTrailingRootChunks as $chunk) {
                $trailingRootChunks[] = $chunk;
            }

            return $this->chunks->buildRuleResult($output, $leadingRootChunks, $trailingRootChunks);
        } finally {
            $ctx->env->exitScope();
        }
    }

    public function handleSupports(SupportsNode $node, TraversalContext $ctx): string
    {
        $output    = '';
        $prefix    = $this->render->indentPrefix($ctx->indent);
        $condition = $this->selector->resolveSupportsCondition($node->condition, $ctx->env);

        $this->render->appendChunk($output, $prefix . '@supports ' . $condition . ' {');

        $parentAtRuleStack = $this->selector->getCurrentAtRuleStack($ctx->env);

        $currentAtRuleStack   = $parentAtRuleStack;
        $currentAtRuleStack[] = [
            'type'      => 'supports',
            'condition' => trim($condition),
        ];

        $outputState = $this->render->outputState();
        $outputState->deferredAtRuleStack[] = [];

        $ctx->env->enterScope();
        $ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', $currentAtRuleStack);

        $childCtx = new TraversalContext($ctx->env, $ctx->indent + 1);

        $hasContent = false;
        $bodyChunks = [];

        $collectMappings = $this->render->collectSourceMappings();

        try {
            foreach ($node->body as $child) {
                if ($child instanceof VariableDeclarationNode) {
                    $ctx->env->getCurrentScope()->setVariable(
                        $child->name,
                        $this->evaluation->evaluateValue($child->value, $ctx->env),
                        $child->global,
                        $child->default,
                    );

                    continue;
                }

                if ($child instanceof ModuleVarDeclarationNode) {
                    $this->module->assignModuleVariable($child, $ctx->env);

                    continue;
                }

                if ($collectMappings) {
                    $this->render->appendChunk($output, "\n");
                }

                /** @var Visitable $child */
                $compiled = $this->render->trimAndAdjustState(
                    $this->dispatcher->compileWithContext($child, $childCtx),
                );

                if ($compiled === '') {
                    continue;
                }

                if ($collectMappings) {
                    $output .= $compiled;
                } else {
                    $bodyChunks[] = $compiled;
                }

                $hasContent = true;
            }
        } finally {
            $ctx->env->exitScope();
        }

        $outsideChunks = $this->selector->drainDeferredAtRuleEscapes();

        if ($hasContent) {
            if (! $collectMappings) {
                $output .= "\n" . implode("\n", $bodyChunks);
            }

            $output = $this->render->trimTrailingNewlines($output);

            $this->render->appendChunk($output, "\n" . $prefix . '}');
        } else {
            if ($outsideChunks !== []) {
                $separator = $this->render->outputSeparator();
                $outside   = implode($separator, $outsideChunks);

                if ($this->context->options()->style === Style::EXPANDED) {
                    return $outside . "\n";
                }

                return $outside;
            }

            $this->render->appendChunk($output, '}');
        }

        if ($outsideChunks !== []) {
            $separator = $this->render->outputSeparator();

            $output .= $separator . implode($separator, $outsideChunks);
        }

        if ($this->context->options()->style === Style::EXPANDED) {
            if ($this->render->collectSourceMappings()) {
                $dummy = '';

                $this->render->appendChunk($dummy, "\n");
            }

            return $output . "\n";
        }

        return $output;
    }

    private function assertCompilableRuleChild(AstNode $child): void
    {
        if ($child instanceof ReturnNode) {
            throw new SassErrorException('This at-rule is not allowed here.');
        }
    }
}
