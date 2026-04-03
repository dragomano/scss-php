<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Utils\SourceMapMapping;

use function count;
use function strtolower;
use function trim;

final readonly class AtRuleNodeHandler
{
    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Evaluator $evaluation,
        private Render $render,
        private Selector $selector,
    ) {}

    public function handleAtRoot(AtRootNode $node, TraversalContext $ctx): string
    {
        $saved         = $this->render->savePosition();
        $atRootResult  = $this->selector->compileAtRootBody($node, $ctx->env);
        $chunk         = $atRootResult['chunk'];
        $deferredChunk = $this->render->createDeferredChunk($chunk, $saved);

        if ($chunk === '') {
            $this->render->restorePosition($saved);

            return '';
        }

        if ($atRootResult['escapeLevels'] > 0) {
            $outputState      = $this->render->outputState();
            $atRuleStackIndex = count($outputState->deferredAtRuleStack) - 1;

            if ($atRuleStackIndex >= 0) {
                $this->render->restorePosition($saved);

                $outputState->deferredAtRuleStack[$atRuleStackIndex][] = [
                    'levels' => $atRootResult['escapeLevels'],
                    'chunk'  => $chunk,
                ];

                return '';
            }
        }

        if (! $ctx->env->getCurrentScope()->hasVariable('__parent_selector')) {
            return $chunk;
        }

        $outputState = $this->render->outputState();
        $stackIndex  = count($outputState->deferredAtRootStack) - 1;

        if ($stackIndex >= 0) {
            $this->render->restorePosition($saved);

            $outputState->deferredAtRootStack[$stackIndex][] = $deferredChunk;

            return '';
        }

        return $chunk;
    }

    public function handleDirective(DirectiveNode $node, TraversalContext $ctx): string
    {
        $prefix = $this->render->indentPrefix($ctx->indent);

        if ($node->name === 'content') {
            return $this->compileContentDirective($node, $ctx);
        }

        $output          = '';
        $prelude         = '';
        $resolvedPrelude = '';

        if ($node->prelude !== '') {
            $resolvedPrelude = $this->selector->resolveDirectivePrelude($node->prelude, $ctx->env);

            $prelude = ' ' . $resolvedPrelude;
        }

        if (! $node->hasBlock) {
            $this->render->appendChunk($output, $prefix . '@' . $node->name . $prelude . ';', $node);

            return $output;
        }

        $this->render->appendChunk($output, $prefix . '@' . $node->name . $prelude . ' {', $node);

        $first = true;

        $outputState = $this->render->outputState();
        $outputState->deferredAtRuleStack[] = [];

        $deferredMergedMediaChunks = [];

        $parentAtRuleStack = $this->selector->getCurrentAtRuleStack($ctx->env);

        $currentAtRuleStack   = $parentAtRuleStack;
        $currentAtRuleStack[] = [
            'type'    => 'directive',
            'name'    => strtolower($node->name),
            'prelude' => trim($resolvedPrelude),
        ];

        $ctx->env->enterScope();
        $ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', $currentAtRuleStack);

        $bodyCtx   = new TraversalContext($ctx->env, $ctx->indent + 1);
        $mergedCtx = $ctx;

        try {
            /**
             * @var array<int, AstNode> $body
             */
            $body = $node->body;

            foreach ($body as $child) {
                if ($this->evaluation->applyVariableDeclaration($child, $ctx->env)) {
                    continue;
                }

                if (
                    strtolower($node->name) === 'media'
                    && $child instanceof DirectiveNode
                    && strtolower($child->name) === 'media'
                ) {
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
                        $deferredMergedMediaChunks[] = $deferredMergedChunk;
                    }

                    continue;
                }

                $this->render->appendChunk($output, "\n");

                /** @var Visitable $child */
                $compiled = $this->render->trimAndAdjustState(
                    $this->dispatcher->compileWithContext($child, $bodyCtx),
                );

                if ($compiled === '') {
                    continue;
                }

                $output .= $compiled;

                $first = false;
            }
        } finally {
            $ctx->env->exitScope();
        }

        $outsideChunks = $this->selector->drainDeferredAtRuleEscapes();

        if ($first) {
            if ($outsideChunks === []) {
                return '';
            }

            $separator = $this->render->outputSeparator();
            $result    = '';

            foreach ($outsideChunks as $index => $chunk) {
                if ($index > 0) {
                    $this->render->appendChunk($result, $separator);
                }

                $this->appendResolvedChunk($result, $chunk);
            }

            return $result;
        }

        if (! $this->render->collectSourceMappings()) {
            $output = $this->selector->optimizeAdjacentSiblingRuleBlocks($output);
        }

        $this->render->appendChunk($output, "\n" . $prefix . '}');

        if ($outsideChunks !== []) {
            $separator = $this->render->outputSeparator();

            foreach ($outsideChunks as $chunk) {
                $this->render->appendChunk($output, $separator);
                $this->appendResolvedChunk($output, $chunk);
            }
        }

        if ($deferredMergedMediaChunks !== []) {
            $separator = $this->render->outputSeparator();

            foreach ($deferredMergedMediaChunks as $chunk) {
                $this->render->appendChunk($output, $separator);
                $this->appendResolvedChunk($output, $chunk);
            }
        }

        return $output;
    }

    /**
     * @param array{
     *     chunk:string,
     *     baseLine:int,
     *     baseColumn:int,
     *     mappings:array<int, SourceMapMapping>
     * }|string $chunk
     */
    private function appendResolvedChunk(string &$output, array|string $chunk): void
    {
        if (is_string($chunk)) {
            $this->render->appendChunk($output, $chunk);

            return;
        }

        $this->render->appendDeferredChunk($output, $chunk);
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

        if ($contentScope === null) {
            $contentScope = $ctx->env->getCurrentScope();
        }

        $ctx->env->enterScope($contentScope);

        if ($mixinParentSelector instanceof StringNode) {
            $ctx->env->getCurrentScope()->setVariableLocal('__parent_selector', $mixinParentSelector);
        }

        if ($moduleGlobalTarget instanceof Scope) {
            $ctx->env->getCurrentScope()->setVariableLocal('__module_global_target', $moduleGlobalTarget);
        }

        if ($atRuleStack !== []) {
            $ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', $atRuleStack);
        }

        if ($contentArguments !== []) {
            $this->evaluation->bindParametersToCurrentScope(
                $contentArguments,
                $resolvedPositional,
                $resolvedNamed,
                $ctx->env->getCurrentScope(),
            );
        }

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

            /**
             * @var iterable<AstNode> $contentBlock
             */
            foreach ($contentBlock as $child) {
                /** @var Visitable $child */
                $compiled = $this->dispatcher->compileWithContext($child, $contentCtx);

                if ($compiled === '') {
                    continue;
                }

                if (! $first) {
                    $this->render->appendChunk($output, "\n");
                }

                $this->render->appendChunk($output, $compiled, $child);

                $first = false;
            }
        } finally {
            $ctx->env->exitScope();
        }

        return $output;
    }

    /**
     * @param array<int, array{type: string, name?: string, prelude?: string, condition?: string}> $atRuleStack
     */
    private function shouldWrapContentInParentRule(array $atRuleStack): bool
    {
        foreach ($atRuleStack as $entry) {
            if ($entry['type'] === 'supports') {
                return true;
            }

            if ($entry['type'] !== 'directive') {
                continue;
            }

            if (($entry['name'] ?? null) === 'media') {
                return true;
            }
        }

        return false;
    }
}
