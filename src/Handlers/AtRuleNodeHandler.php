<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Runtime\DeferredAtRuleChunk;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Utils\GroupStartChunk;
use Bugo\SCSS\Utils\OutputChunk;
use Bugo\SCSS\Utils\RawChunk;

use function count;
use function in_array;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function trim;

final readonly class AtRuleNodeHandler
{
    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Evaluator $evaluation,
        private Render $render,
        private Selector $selector,
        private DeferredChunkManager $chunks,
    ) {}

    public function handleAtRoot(AtRootNode $node, TraversalContext $ctx): string
    {
        $saved         = $this->render->savePosition();
        $ruleWasEmpty  = ! $this->render->outputState()->deferral->currentRuleHasOutput;
        $atRootResult  = $this->selector->compileAtRootBody($node, $ctx->env);
        $chunk         = $atRootResult['chunk'];
        $deferredChunk = new GroupStartChunk(
            $this->render->createDeferredChunk($chunk, $saved),
            isEarly: $ruleWasEmpty,
        );

        if ($chunk === '') {
            $this->render->restorePosition($saved);

            return '';
        }

        if ($atRootResult['escapeLevels'] > 0) {
            $outputState      = $this->render->outputState();
            $atRuleStackIndex = count($outputState->deferral->atRuleStack) - 1;

            if ($atRuleStackIndex >= 0) {
                $this->render->restorePosition($saved);

                $outputState->deferral->atRuleStack[$atRuleStackIndex][] = new DeferredAtRuleChunk(
                    $atRootResult['escapeLevels'],
                    $chunk,
                );

                return '';
            }
        }

        if (! $ctx->env->getCurrentScope()->hasVariable('__parent_selector')) {
            return $chunk;
        }

        $outputState = $this->render->outputState();
        $stackIndex  = count($outputState->deferral->atRootStack) - 1;

        if ($stackIndex >= 0) {
            $this->render->restorePosition($saved);

            $outputState->deferral->atRootStack[$stackIndex][] = $deferredChunk;

            return '';
        }

        return $chunk;
    }

    public function handleDirective(DirectiveNode $node, TraversalContext $ctx): string
    {
        $prefix = $this->render->indentPrefix($ctx->indent);

        $directiveName = str_contains($node->name, '#{')
            ? $this->evaluation->interpolateText($node->name, $ctx->env)
            : $node->name;

        if ($node->name === 'content') {
            return $this->compileContentDirective($node, $ctx);
        }

        if ($node->name === 'charset') {
            return '';
        }

        $output          = '';
        $prelude         = '';
        $resolvedPrelude = '';

        if ($node->prelude !== '') {
            // For @keyframes, only interpolate #{} but don't resolve $var references
            if ($this->isKeyframesDirective($node)) {
                $resolvedPrelude = str_contains($node->prelude, '#{')
                    ? $this->evaluation->interpolateText($node->prelude, $ctx->env)
                    : $node->prelude;
            } else {
                $resolvedPrelude = $this->selector->resolveDirectivePrelude($node->prelude, $ctx->env);

                $lowerName = strtolower($node->name);

                if ($lowerName === 'media') {
                    $resolvedPrelude = $this->selector->normalizeMediaQueryPrelude($resolvedPrelude);
                } elseif ($lowerName === '-moz-document') {
                    $resolvedPrelude = $this->selector->stripAllComments($resolvedPrelude);
                }
            }

            $prelude = $resolvedPrelude === '' ? '' : ' ' . $resolvedPrelude;
        }

        if (! $node->hasBlock) {
            $this->render->appendChunk($output, $prefix . '@' . $directiveName . $prelude . ';', $node);

            return $output;
        }

        $outputState = $this->render->outputState();
        $outputState->deferral->atRuleStack[] = [];

        /** @var list<array{chunk: OutputChunk, isMerged: bool}> $orderedChunks */
        $orderedChunks      = [];
        $parentSegmentSaved = $this->render->savePosition();
        $hasParentContent   = false;

        $parentAtRuleStack = $this->selector->getCurrentAtRuleStack($ctx->env);

        $currentAtRuleStack   = $parentAtRuleStack;
        $currentAtRuleStack[] = AtRuleContextEntry::directive(
            strtolower($node->name),
            trim($resolvedPrelude),
        );

        $ctx->env->enterScope();
        $ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', $currentAtRuleStack);

        $bodyCtx   = new TraversalContext($ctx->env, $ctx->indent + 1);
        $mergedCtx = $ctx;

        try {
            /**
             * @var array<int, AstNode> $body
             */
            $body = $node->body;

            // For keyframes with comment-only body, output compact format { /**/ }
            if ($this->isKeyframesDirective($node) && $this->isCommentOnlyBody($body)) {
                $this->render->appendChunk(
                    $output,
                    $prefix . '@' . $directiveName . $prelude . ' { /**/ }',
                    $node,
                );

                $orderedChunks[] = [
                    'chunk'    => $this->render->createDeferredChunk($output, $parentSegmentSaved),
                    'isMerged' => false,
                ];

                $this->render->restorePosition($parentSegmentSaved);

                $ctx->env->exitScope();

                $outsideChunks = $this->selector->drainDeferredAtRuleEscapes();

                $result    = '';
                $separator = "\n" . Render::CONTINUATION_MARK;

                foreach ($orderedChunks as $index => $entry) {
                    if ($index > 0) {
                        $this->render->appendChunk($result, $separator);
                    }

                    $this->appendResolvedChunk($result, $entry['chunk']);
                }

                foreach ($outsideChunks as $chunk) {
                    $this->render->appendChunk($result, $separator);
                    $this->appendResolvedChunk($result, new RawChunk($chunk));
                }

                return $result;
            }

            foreach ($body as $child) {
                if ($this->evaluation->applyVariableDeclaration($child, $ctx->env)) {
                    continue;
                }

                if (
                    strtolower($node->name) === 'media'
                    && $child instanceof DirectiveNode
                    && strtolower($child->name) === 'media'
                    && ! str_starts_with(trim($resolvedPrelude), 'not ')
                ) {
                    if ($hasParentContent) {
                        $this->render->appendChunk($output, "\n" . $prefix . '}');

                        $orderedChunks[] = [
                            'chunk'    => $this->render->createDeferredChunk($output, $parentSegmentSaved),
                            'isMerged' => false,
                        ];

                        $this->render->restorePosition($parentSegmentSaved);

                        $output             = '';
                        $hasParentContent   = false;
                        $parentSegmentSaved = $this->render->savePosition();
                    }

                    $childPrelude  = $this->selector->resolveDirectivePrelude($child->prelude, $ctx->env);
                    $parentPrelude = trim($resolvedPrelude);
                    $mergedPrelude = $this->selector->combineMediaQueryPreludes($parentPrelude, $childPrelude);
                    $mergedNode    = new DirectiveNode('media', $mergedPrelude, $child->body, true);
                    $saved         = $this->render->savePosition();

                    $ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', $parentAtRuleStack);

                    $mergedChunk = $this->render->trimTrailingNewlines(
                        $this->dispatcher->compileWithContext($mergedNode, $mergedCtx),
                    );

                    $deferredMergedChunk = $this->render->createDeferredChunk($mergedChunk, $saved);

                    $ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', $currentAtRuleStack);

                    $this->render->restorePosition($saved);

                    if ($mergedChunk !== '') {
                        $orderedChunks[] = [
                            'chunk'    => $deferredMergedChunk,
                            'isMerged' => true,
                        ];
                    }

                    continue;
                }

                if (! $hasParentContent) {
                    $parentSegmentSaved = $this->render->savePosition();

                    $this->render->appendChunk($output, $prefix . '@' . $directiveName . $prelude . ' {', $node);
                }

                $collectMappings     = $this->render->collectSourceMappings();
                $preCompileSaved     = null;
                $lengthBeforeNewline = strlen($output);

                if ($collectMappings) {
                    $preCompileSaved = $this->render->savePosition();

                    $this->render->appendChunk($output, "\n");
                }

                /** @var Visitable $child */
                $compiled = $this->render->trimAndAdjustState(
                    $this->dispatcher->compileWithContext($child, $bodyCtx),
                );

                if ($compiled === '') {
                    if (! $hasParentContent) {
                        $this->render->restorePosition($parentSegmentSaved);

                        $output = '';
                    } elseif ($preCompileSaved !== null) {
                        $this->render->restorePosition($preCompileSaved);

                        $output = substr($output, 0, $lengthBeforeNewline);
                    }

                    continue;
                }

                if (! $collectMappings) {
                    $this->render->appendChunk($output, "\n");
                }

                $output .= $compiled;

                $hasParentContent = true;
            }
        } finally {
            $ctx->env->exitScope();
        }

        $outsideChunks = $this->selector->drainDeferredAtRuleEscapes();

        if ($hasParentContent) {
            if (! $this->render->collectSourceMappings()) {
                $output = $this->selector->optimizeAdjacentSiblingRuleBlocks($output);
            }

            $this->render->appendChunk($output, "\n" . $prefix . '}');

            $orderedChunks[] = [
                'chunk'    => $this->render->createDeferredChunk($output, $parentSegmentSaved),
                'isMerged' => false,
            ];

            $this->render->restorePosition($parentSegmentSaved);
        } elseif (! in_array(strtolower($node->name), ['media', 'supports'], true)) {
            $emptyOutput = $prefix . '@' . $directiveName . $prelude . ' {}';

            $orderedChunks[] = [
                'chunk'    => $this->render->createDeferredChunk($emptyOutput, $parentSegmentSaved),
                'isMerged' => false,
            ];
        }

        if ($orderedChunks === []) {
            if ($outsideChunks === []) {
                return '';
            }

            $separator = "\n" . Render::CONTINUATION_MARK;
            $result    = '';

            foreach ($outsideChunks as $index => $chunk) {
                if ($index > 0) {
                    $this->render->appendChunk($result, $separator);
                }

                $this->appendResolvedChunk($result, new RawChunk($chunk));
            }

            return $result;
        }

        $result    = '';
        $separator = "\n" . Render::CONTINUATION_MARK;

        foreach ($orderedChunks as $index => $entry) {
            if ($index > 0) {
                $previous = $orderedChunks[$index - 1];

                $this->render->appendChunk(
                    $result,
                    $previous['isMerged'] && ! $entry['isMerged'] ? "\n" : $separator,
                );
            }

            $this->appendResolvedChunk($result, $entry['chunk']);
        }

        foreach ($outsideChunks as $chunk) {
            $this->render->appendChunk($result, $separator);
            $this->appendResolvedChunk($result, new RawChunk($chunk));
        }

        return $result;
    }

    private function appendResolvedChunk(string &$output, OutputChunk $chunk): void
    {
        $this->render->appendOutputChunk($output, $chunk);
    }

    private function isKeyframesDirective(DirectiveNode $node): bool
    {
        $name = strtolower($node->name);

        return $name === 'keyframes'
            || str_ends_with($name, '-keyframes');
    }

    /**
     * @param array<int, AstNode> $body
     */
    private function isCommentOnlyBody(array $body): bool
    {
        if ($body === []) {
            return true;
        }

        foreach ($body as $child) {
            if (! $child instanceof CommentNode) {
                return false;
            }
        }

        return true;
    }

    private function compileContentDirective(DirectiveNode $node, TraversalContext $ctx): string
    {
        if (! $ctx->env->getCurrentScope()->hasVariable('__meta_content_block')) {
            return '';
        }

        $contentBlock = $this->evaluation->extractAstNodes(
            $ctx->env->getCurrentScope()->getVariable('__meta_content_block'),
        );

        if ($contentBlock === []) {
            return '';
        }

        $contentArguments = $ctx->env->getCurrentScope()->hasVariable('__meta_content_arguments')
            ? $this->evaluation->extractArgumentNodes(
                $ctx->env->getCurrentScope()->getVariable('__meta_content_arguments'),
            )
            : [];

        $contentScope         = $ctx->env->getCurrentScope()->getScopeVariable('__meta_content_scope');
        $mixinParentSelector  = $ctx->env->getCurrentScope()->getStringVariable('__parent_selector');
        $moduleGlobalTarget   = $ctx->env->getCurrentScope()->getScopeVariable('__module_global_target');
        $contentCallArguments = $this->evaluation->parseContentCallArguments($node->prelude);
        $atRuleStack          = $this->selector->getCurrentAtRuleStack($ctx->env);

        [$resolvedPositional, $resolvedNamed] = $this->evaluation->resolveCallArguments(
            $contentCallArguments,
            $ctx->env,
        );

        $contentScope ??= $ctx->env->getCurrentScope();

        $ctx->env->enterScope($contentScope);

        $childScope = $ctx->env->getCurrentScope();

        if ($mixinParentSelector instanceof StringNode) {
            $childScope->setVariableLocal('__parent_selector', $mixinParentSelector);
        }

        if ($moduleGlobalTarget instanceof Scope) {
            $childScope->setVariableLocal('__module_global_target', $moduleGlobalTarget);
        }

        if ($atRuleStack !== []) {
            $childScope->setVariableLocal('__at_rule_stack', $atRuleStack);
        }

        if ($contentArguments !== []) {
            $this->evaluation->bindParametersToCurrentScope(
                $contentArguments,
                $resolvedPositional,
                $resolvedNamed,
                $childScope,
                $ctx->env,
            );
        }

        $contentVarsBefore = $contentScope->getVariables();

        $output     = '';
        $first      = true;
        $contentCtx = $ctx;

        try {
            if ($mixinParentSelector instanceof StringNode && $this->shouldWrapContentInParentRule($atRuleStack)) {
                $wrappedContent = new RuleNode($mixinParentSelector->value, $contentBlock);
                $compiled       = $this->dispatcher->compileWithContext($wrappedContent, $contentCtx);

                if ($compiled !== '') {
                    $this->render->appendChunk($output, $compiled, $wrappedContent);
                }

                return $output;
            }

            if ($atRuleStack === []) {
                $output = $this->chunks->compileBodyChunks($contentBlock, $contentCtx, $contentScope);
            } else {
                /**
                 * @var iterable<AstNode> $contentBlock
                 */
                foreach ($contentBlock as $child) {
                    /** @var Visitable $child */
                    $compiled = $this->render->trimAndAdjustState(
                        $this->dispatcher->compileWithContext($child, $contentCtx),
                    );

                    if ($compiled === '') {
                        continue;
                    }

                    if (! $first) {
                        $this->render->appendChunk($output, "\n");
                    }

                    $this->render->appendChunk($output, $compiled, $child);

                    $first = false;
                }
            }
        } finally {
            $childSnapshotAfter = $childScope->getVariables();
            $ctx->env->exitScope();
        }

        $executionScope = $ctx->env->getCurrentScope();

        /** @var mixed $value */
        foreach ($childSnapshotAfter as $name => $value) {
            if (isset($contentVarsBefore[$name]) && $contentVarsBefore[$name] !== $value) {
                if ($name !== '' && $name[0] === '-') {
                    continue;
                }

                $contentScope->setVariableLocal($name, $value);
                $executionScope->setVariableLocal($name, $value);
            }
        }

        return $output;
    }

    /**
     * @param list<AtRuleContextEntry> $atRuleStack
     */
    private function shouldWrapContentInParentRule(array $atRuleStack): bool
    {
        foreach ($atRuleStack as $entry) {
            if ($entry->type === 'supports') {
                return true;
            }

            if ($entry->type !== 'directive') {
                continue;
            }

            if ($entry->name === 'media') {
                return true;
            }
        }

        return false;
    }
}
