<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Block;

use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StatementNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Runtime\DeferredAtRuleChunk;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Style;
use Bugo\SCSS\Utils\DeferredChunk;
use Bugo\SCSS\Utils\GroupStartChunk;
use Bugo\SCSS\Utils\OutputChunk;
use Bugo\SCSS\Utils\RawChunk;

use function array_splice;
use function array_values;
use function count;
use function max;
use function str_contains;
use function str_replace;
use function strtolower;
use function trim;

final readonly class DeferredChunkManager
{
    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Context $context,
        private Evaluator $evaluation,
        private Render $render,
        private Selector $selector,
    ) {}

    /**
     * @param list<OutputChunk> $leadingRootChunks
     * @param list<OutputChunk> $trailingRootChunks
     */
    public function buildRuleResult(
        string $output,
        array $leadingRootChunks,
        array $trailingRootChunks,
    ): string {
        $continuation = "\n" . Render::CONTINUATION_MARK;

        $result   = '';
        $previous = null;

        foreach ($leadingRootChunks as $chunk) {
            if ($result !== '') {
                $this->render->appendChunk($result, $this->separatorBetween($previous, $chunk));
            }

            $this->appendResolvedChunk($result, $chunk);

            $previous = $chunk;
        }

        if ($output !== '') {
            $ruleOutput = $this->render->trimTrailingNewlines($output);

            if ($ruleOutput !== '') {
                if ($result !== '') {
                    $result .= $this->separatorBetween($previous, new RawChunk(''));
                }

                $result .= $ruleOutput;

                $previous = null;
            }
        }

        foreach ($trailingRootChunks as $chunk) {
            if ($result !== '') {
                $this->render->appendChunk($result, $this->separatorBetween($previous, $chunk));
            }

            $this->appendResolvedChunk($result, $chunk);

            $previous = $chunk;
        }

        if ($this->context->options()->style === Style::EXPANDED) {
            if ($result === '') {
                return '';
            }

            if ($this->render->collectSourceMappings()) {
                $dummy = '';

                $this->render->appendChunk($dummy, "\n");
            }

            return $result . "\n";
        }

        return $result;
    }

    public function appendDeferredAtRuleChunk(int $levels, string $chunk): bool
    {
        $atRuleStackIndex = count($this->render->outputState()->deferral->atRuleStack) - 1;

        if ($atRuleStackIndex < 0) {
            return false;
        }

        $this->render->outputState()->deferral->atRuleStack[$atRuleStackIndex][] = new DeferredAtRuleChunk(
            $levels,
            $chunk,
        );

        return true;
    }

    /**
     * @param list<OutputChunk> $leadingRootChunks
     * @param list<OutputChunk> $trailingRootChunks
     */
    public function collectRuleBubblingChunk(
        array &$leadingRootChunks,
        array &$trailingRootChunks,
        bool $hasRenderedChildren,
        string $selector,
        Scope $scope,
        AstNode $child,
        TraversalContext $ctx,
        bool $hasStandaloneNestedRuleChunks = false,
    ): void {
        /** @var StatementNode $child */
        $bubblingNode = $this->evaluation->normalizeBubblingNodeForSelector($child, $selector);
        $saved        = $this->render->savePosition();

        if ($child instanceof DirectiveNode && strtolower($child->name) === 'media') {
            $atRuleStack        = $this->selector->getCurrentAtRuleStack($ctx->env);
            $parentMediaPrelude = $this->findLastMediaPrelude($atRuleStack);

            if ($parentMediaPrelude !== null && $bubblingNode instanceof DirectiveNode) {
                ['chunk' => $chunk, 'deferredChunk' => $deferredChunk] = $this->compileMergedMediaChunk(
                    $atRuleStack,
                    $parentMediaPrelude,
                    $bubblingNode,
                    $child,
                    $scope,
                    $ctx,
                    $saved,
                );

                if ($chunk !== '') {
                    $this->render->restorePosition($saved);

                    if ($this->appendDeferredAtRuleChunk(1, $chunk)) {
                        return;
                    }

                    if ($hasRenderedChildren || $hasStandaloneNestedRuleChunks) {
                        $trailingRootChunks[] = $deferredChunk;
                    } else {
                        $leadingRootChunks[] = $deferredChunk;
                    }
                }

                return;
            }
        }

        $chunk = $this->render->trimAndAdjustState(
            $this->dispatcher->compileWithContext($bubblingNode, $ctx),
        );

        if ($chunk !== '') {
            $deferredChunk = $this->render->createDeferredChunk($chunk, $saved);

            if ($hasRenderedChildren || $hasStandaloneNestedRuleChunks) {
                $this->render->restorePosition($saved);

                $trailingRootChunks[] = $deferredChunk;

                return;
            }

            $leadingRootChunks[] = $deferredChunk;
        }
    }

    /**
     * @param list<OutputChunk> $leadingRootChunks
     * @param list<OutputChunk> $trailingRootChunks
     */
    public function collectRuleAtRootChunk(
        array &$leadingRootChunks,
        array &$trailingRootChunks,
        AtRootNode $child,
        TraversalContext $ctx,
    ): void {
        $preparedChunk = $this->prepareAtRootChunk($child, $ctx);

        if ($preparedChunk === null) {
            return;
        }

        $this->render->restorePosition($preparedChunk['saved']);

        if ($preparedChunk['escapeLevels'] > 0) {
            if (! $this->appendDeferredAtRuleChunk($preparedChunk['escapeLevels'], $preparedChunk['chunk'])) {
                $trailingRootChunks[] = $preparedChunk['deferredChunk'];
            }

            return;
        }

        if ($preparedChunk['deferredChunk']->isEarly) {
            $leadingRootChunks[] = $preparedChunk['deferredChunk'];

            return;
        }

        $trailingRootChunks[] = $preparedChunk['deferredChunk'];
    }

    /**
     * @param list<OutputChunk> $leadingRootChunks
     */
    public function interleaveDeferredRootChunks(
        string &$output,
        bool &$hasRenderedChildren,
        string $prefix,
        bool &$containsStandaloneNestedRuleChunks,
        int $deferredAtRootCount,
        array &$leadingRootChunks,
    ): void {
        $outputState      = $this->render->outputState();
        $atRootStackIndex = count($outputState->deferral->atRootStack) - 1;

        if ($atRootStackIndex < 0) {
            return;
        }

        /** @var list<OutputChunk> $stack */
        $stack = $outputState->deferral->atRootStack[$atRootStackIndex];

        /** @var list<OutputChunk> $newChunks */
        $newChunks = array_splice($stack, $deferredAtRootCount);

        $outputState->deferral->atRootStack[$atRootStackIndex] = $stack;

        $interleaved = [];

        foreach ($newChunks as $chunk) {
            if ($chunk instanceof GroupStartChunk && $chunk->isEarly) {
                $leadingRootChunks[] = $chunk;

                continue;
            }

            $interleaved[] = $chunk;
        }

        if ($interleaved === []) {
            return;
        }

        if ($hasRenderedChildren) {
            $output = $this->render->trimTrailingNewlines($output);

            $this->render->appendChunk($output, "\n" . $prefix . '}');

            $hasRenderedChildren = false;
        }

        foreach ($interleaved as $chunk) {
            if ($output !== '') {
                $this->render->appendChunk($output, "\n" . Render::CONTINUATION_MARK);
            }

            $this->appendResolvedChunk($output, $chunk);
        }

        $containsStandaloneNestedRuleChunks = true;

        $this->render->outputState()->deferral->currentRuleHasOutput = true;
    }

    public function appendIncludeAtRootChunk(
        string &$output,
        bool &$first,
        AtRootNode $child,
        TraversalContext $ctx,
    ): void {
        $preparedChunk = $this->prepareAtRootChunk($child, $ctx);

        if ($preparedChunk === null) {
            return;
        }

        if ($preparedChunk['escapeLevels'] > 0) {
            $this->render->restorePosition($preparedChunk['saved']);

            if (! $this->appendDeferredAtRuleChunk($preparedChunk['escapeLevels'], $preparedChunk['chunk'])
                && ! $ctx->env->getCurrentScope()->hasVariable('__parent_selector')
            ) {
                $this->appendOutputChunk($output, $first, $preparedChunk['deferredChunk']);
            }

            return;
        }

        if ($this->appendDeferredRootChunk($preparedChunk['deferredChunk'])) {
            $this->render->restorePosition($preparedChunk['saved']);

            return;
        }

        if (! $ctx->env->getCurrentScope()->hasVariable('__parent_selector')) {
            $this->appendOutputChunk($output, $first, $preparedChunk['deferredChunk']);
        }
    }

    public function appendIncludeBubblingChunk(
        string &$output,
        bool &$first,
        AstNode $child,
        TraversalContext $ctx,
        bool $hasRenderedChildren = false,
        bool $flushToOutput = false,
    ): void {
        /** @var StatementNode $child */
        $parentSelector = $this->selector->getCurrentParentSelector($ctx->env);
        $bubblingNode   = $parentSelector === null || $parentSelector === ''
            ? $child
            : $this->evaluation->normalizeBubblingNodeForSelector($child, $parentSelector);

        $outerCtx      = new TraversalContext($ctx->env, max(0, $ctx->indent - 1));
        $preparedChunk = $this->prepareCompiledChunk($bubblingNode, $outerCtx);

        if ($preparedChunk === null) {
            return;
        }

        $stackIndex = count($this->render->outputState()->deferral->bubblingStack) - 1;

        if ($stackIndex >= 0 && ! $flushToOutput) {
            if ($this->shouldDeferBubblingChunkToTrailingRoot($child, $hasRenderedChildren)) {
                $this->render->restorePosition($preparedChunk['saved']);

                $atRootStackIndex = count($this->render->outputState()->deferral->atRootStack) - 1;

                if ($atRootStackIndex >= 0) {
                    $this->render->outputState()->deferral->atRootStack[$atRootStackIndex][] = $preparedChunk['deferredChunk'];
                } else {
                    $this->appendDeferredBubblingChunk($preparedChunk['deferredChunk']);
                }
            } else {
                $this->appendDeferredBubblingChunk($preparedChunk['deferredChunk']);
            }

            return;
        }

        $this->appendOutputChunk($output, $first, $preparedChunk['deferredChunk']);
    }

    public function appendIncludedRuleChunk(
        string &$output,
        bool &$first,
        RuleNode $child,
        TraversalContext $ctx,
        bool $flushToOutput = false,
    ): void {
        $parentSelector = $this->selector->getCurrentParentSelector($ctx->env);
        $childSelector  = $this->resolveRuleSelector($child, $ctx);

        $compiled = $this->compileNestedPropertyBlock($child, $childSelector, $ctx, $ctx->indent);

        if ($compiled !== null && $compiled !== '') {
            $this->appendOutputChunk($output, $first, new RawChunk($compiled));

            return;
        }

        $ruleNode = $child;

        if ($parentSelector !== null && $parentSelector !== '') {
            $resolvedSelector = str_contains($childSelector, '&')
                ? $this->selector->resolveNestedSelector($childSelector, $parentSelector)
                : $this->selector->combineNestedSelectorWithParent($childSelector, $parentSelector);

            $ruleNode = new RuleNode(
                $resolvedSelector,
                $child->children,
                $child->line,
                $child->column,
            );
        }

        $outerCtx = new TraversalContext($ctx->env, max(0, $ctx->indent - 1));
        $preparedChunk = $this->prepareCompiledChunk($ruleNode, $outerCtx);

        if ($preparedChunk === null) {
            return;
        }

        if (! $flushToOutput && $this->appendDeferredRootChunk($preparedChunk['deferredChunk'])) {
            $this->render->restorePosition($preparedChunk['saved']);

            return;
        }

        $this->appendOutputChunk($output, $first, $preparedChunk['deferredChunk']);
    }

    public function appendOutputChunk(string &$output, bool &$first, OutputChunk $chunk): void
    {
        if (! $first) {
            $this->render->appendChunk($output, "\n" . Render::CONTINUATION_MARK);
        }

        $this->appendResolvedChunk($output, $chunk);

        $first = false;
    }

    public function appendDeferredRootChunk(OutputChunk $chunk): bool
    {
        $stackIndex = count($this->render->outputState()->deferral->atRootStack) - 1;

        if ($stackIndex < 0) {
            return false;
        }

        $this->render->outputState()->deferral->atRootStack[$stackIndex][] = $chunk;

        return true;
    }

    public function appendDeferredBubblingChunk(OutputChunk $chunk): bool
    {
        $stackIndex = count($this->render->outputState()->deferral->bubblingStack) - 1;

        if ($stackIndex < 0) {
            return false;
        }

        $this->render->outputState()->deferral->bubblingStack[$stackIndex][] = $chunk;

        return true;
    }

    public function resolveRuleSelector(RuleNode $node, TraversalContext $ctx): string
    {
        return str_contains($node->selector, '#{')
            ? $this->evaluation->interpolateText($node->selector, $ctx->env)
            : $node->selector;
    }

    public function compileNestedPropertyBlock(
        RuleNode $node,
        string $selector,
        TraversalContext $ctx,
        int $indent,
    ): ?string {
        $nestedProperty = $this->selector->parseNestedPropertyBlockSelector($selector);

        if ($nestedProperty === null) {
            return null;
        }

        return $this->selector->compileNestedPropertyBlockChildren(
            $node->children,
            $ctx->env,
            $indent,
            $nestedProperty['property'],
            $nestedProperty['value'],
        );
    }

    /**
     * @param list<OutputChunk> $trailingRootChunks
     */
    public function collectNestedRuleChunk(
        string &$output,
        bool &$hasRenderedChildren,
        string $prefix,
        string $selector,
        RuleNode $node,
        RuleNode $child,
        bool &$containsStandaloneNestedRuleChunks,
        array &$trailingRootChunks,
        TraversalContext $ctx,
    ): void {
        $childSelector = $this->resolveRuleSelector($child, $ctx);
        $compiled      = $this->compileNestedPropertyBlock($child, $childSelector, $ctx, $ctx->indent + 1);

        if ($compiled !== null && $compiled !== '') {
            if (! $hasRenderedChildren) {
                $formattedSelector = str_replace("\n", "\n" . $prefix, $selector);

                $this->render->appendChunk($output, $prefix . $formattedSelector . ' {', $node);

                $hasRenderedChildren = true;
                $this->render->outputState()->deferral->currentRuleHasOutput = true;
            }

            $this->render->appendChunk($output, "\n");
            $this->render->appendChunk($output, $compiled, $child);

            return;
        }

        $resolvedNestedSelector = str_contains($childSelector, '&')
            ? $this->selector->resolveNestedSelector($childSelector, $selector)
            : $this->selector->combineNestedSelectorWithParent($childSelector, $selector);

        $nestedRuleNode = new RuleNode(
            $resolvedNestedSelector,
            $child->children,
            $child->line,
            $child->column,
        );

        $preparedChunk = $this->prepareCompiledChunk($nestedRuleNode, $ctx);

        if ($preparedChunk !== null) {
            $this->render->restorePosition($preparedChunk['saved']);

            if ($hasRenderedChildren) {
                $output = $this->render->trimTrailingNewlines($output);

                $this->render->appendChunk($output, "\n" . $prefix . '}');

                $hasRenderedChildren = false;
            }

            if ($trailingRootChunks !== []) {
                foreach ($trailingRootChunks as $rootChunk) {
                    if ($output !== '') {
                        $this->render->appendChunk($output, "\n" . Render::CONTINUATION_MARK);
                    }

                    $this->appendResolvedChunk($output, $rootChunk);
                }

                $trailingRootChunks = [];
            }

            if ($output !== '') {
                $this->render->appendChunk($output, "\n" . Render::CONTINUATION_MARK);
            }

            $this->appendResolvedChunk($output, $preparedChunk['deferredChunk']);

            $this->render->outputState()->deferral->currentRuleHasOutput = true;

            $containsStandaloneNestedRuleChunks = true;
        }
    }

    /**
     * @param string $selector
     * @param Scope $scope
     * @param AstNode $child
     * @param TraversalContext $ctx
     * @return DeferredChunk|null
     */
    public function compileInterleavedBubblingChunk(
        string $selector,
        Scope $scope,
        AstNode $child,
        TraversalContext $ctx,
    ): ?DeferredChunk {
        /** @var StatementNode $child */
        $bubblingNode = $this->evaluation->normalizeBubblingNodeForSelector($child, $selector);
        $saved        = $this->render->savePosition();

        if ($child instanceof DirectiveNode && strtolower($child->name) === 'media') {
            $atRuleStack        = $this->selector->getCurrentAtRuleStack($ctx->env);
            $parentMediaPrelude = $this->findLastMediaPrelude($atRuleStack);

            if ($parentMediaPrelude !== null && $bubblingNode instanceof DirectiveNode) {
                ['chunk' => $chunk, 'deferredChunk' => $deferredChunk] = $this->compileMergedMediaChunk(
                    $atRuleStack,
                    $parentMediaPrelude,
                    $bubblingNode,
                    $child,
                    $scope,
                    $ctx,
                    $saved,
                );

                $this->render->restorePosition($saved);

                if ($chunk !== '') {

                    if ($this->appendDeferredAtRuleChunk(1, $chunk)) {
                        return null;
                    }

                    return $deferredChunk;
                }

                return null;
            }
        }

        $chunk = $this->render->trimAndAdjustState(
            $this->dispatcher->compileWithContext($bubblingNode, $ctx),
        );

        if ($chunk === '') {
            $this->render->restorePosition($saved);

            return null;
        }

        $deferredChunk = $this->render->createDeferredChunk($chunk, $saved);

        $this->render->restorePosition($saved);

        return $deferredChunk;
    }

    public function appendResolvedChunk(string &$output, OutputChunk $chunk): void
    {
        $this->render->appendOutputChunk($output, $chunk);
    }

    /**
     * @param array<int, AstNode> $body
     */
    public function compileBodyChunks(array $body, TraversalContext $ctx, Scope $callScope): string
    {
        $output       = '';
        $first        = true;
        $parentOpened = $callScope->hasVariable('__parent_rule_has_rendered_children')
            && $callScope->getVariable('__parent_rule_has_rendered_children') === true;
        $parentReopen = false;
        $blockSplit   = false;
        $parentPrefix = $this->render->indentPrefix(max(0, $ctx->indent - 1));

        foreach ($body as $child) {
            if ($this->evaluation->applyVariableDeclaration($child, $ctx->env)) {
                continue;
            }

            if ($child instanceof AtRootNode) {
                $this->appendIncludeAtRootChunk($output, $first, $child, $ctx);

                continue;
            }

            if ($this->evaluation->isBubblingAtRuleNode($child)) {
                $parentHasRendered = $callScope->hasVariable('__parent_rule_has_rendered_children')
                    && $callScope->getVariable('__parent_rule_has_rendered_children') === true;

                if ($parentOpened && $output !== '') {
                    $this->closeParentRuleBlock($output, $parentPrefix);

                    $parentOpened = false;
                    $parentReopen = true;
                    $blockSplit   = true;
                }

                $this->appendIncludeBubblingChunk($output, $first, $child, $ctx, $parentHasRendered, $blockSplit);

                continue;
            }

            if ($child instanceof RuleNode) {
                if ($parentOpened && $output !== '') {
                    $this->closeParentRuleBlock($output, $parentPrefix);

                    $parentOpened = false;
                    $parentReopen = true;
                    $blockSplit   = true;
                }

                $this->appendIncludedRuleChunk($output, $first, $child, $ctx, $blockSplit);

                continue;
            }

            if ($parentReopen && $output !== '') {
                $parentSelector = $this->selector->getCurrentParentSelector($ctx->env);

                if ($parentSelector !== null && $parentSelector !== '') {
                    $formattedSelector = str_replace("\n", "\n" . $parentPrefix, $parentSelector);

                    $this->render->appendChunk($output, "\n" . $parentPrefix . $formattedSelector . ' {');

                    $parentReopen = false;
                    $parentOpened = true;
                }
            }

            $savedPosition = null;

            if ($this->render->collectSourceMappings() && ! $child instanceof DeclarationNode) {
                $savedPosition = $this->render->savePosition();
            }

            /** @var Visitable $child */
            $compiled = $this->dispatcher->compileWithContext($child, $ctx);

            if ($compiled === '') {
                continue;
            }

            if ($child instanceof DeclarationNode) {
                if (! $first) {
                    $this->render->appendChunk($output, "\n");
                }

                $this->render->appendChunk($output, $compiled, $child);
            } else {
                $compiled = $this->render->trimAndAdjustState($compiled);

                if ($savedPosition !== null) {
                    $deferredChunk = $this->render->createDeferredChunk($compiled, $savedPosition);

                    $this->render->restorePosition($savedPosition);

                    if (! $first) {
                        $this->render->appendChunk($output, "\n");
                    }

                    $this->render->appendDeferredChunk($output, $deferredChunk);
                } else {
                    if (! $first) {
                        $this->render->appendChunk($output, "\n");
                    }

                    $output .= $compiled;
                }
            }

            $first = false;
        }

        return $output;
    }

    private function closeParentRuleBlock(string &$output, string $parentPrefix): void
    {
        $output = $this->render->trimTrailingNewlines($output);

        $this->render->appendChunk($output, "\n" . $parentPrefix . '}');
    }

    /**
     * @param list<AtRuleContextEntry> $stack
     */
    private function findLastMediaPrelude(array $stack): ?string
    {
        for ($index = count($stack) - 1; $index >= 0; $index--) {
            $entry = $stack[$index];

            if ($entry->type !== 'directive' || $entry->name !== 'media') {
                continue;
            }

            return trim($entry->prelude ?? '');
        }

        return null;
    }

    /**
     * @param list<AtRuleContextEntry> $stack
     * @return list<AtRuleContextEntry>
     */
    private function removeLastMediaEntryFromAtRuleStack(array $stack): array
    {
        for ($index = count($stack) - 1; $index >= 0; $index--) {
            $entry = $stack[$index];

            if ($entry->type === 'directive' && $entry->name === 'media') {
                unset($stack[$index]);

                break;
            }
        }

        return array_values($stack);
    }

    private function shouldDeferBubblingChunkToTrailingRoot(AstNode $node, bool $hasRenderedChildren = false): bool
    {
        if ($node instanceof SupportsNode) {
            return true;
        }

        if ($node instanceof RuleNode && $node->selector === '@font-face') {
            return $hasRenderedChildren;
        }

        if (! $node instanceof DirectiveNode) {
            return false;
        }

        $name = strtolower($node->name);

        if ($name === 'media') {
            return true;
        }

        if (str_contains($name, 'keyframes')) {
            return $hasRenderedChildren;
        }

        return false;
    }

    /**
     * @return array{
     *     chunk:string,
     *     deferredChunk:GroupStartChunk,
     *     escapeLevels:int,
     *     saved:array{0: int, 1: int, 2: int}
     * }|null
     */
    private function prepareAtRootChunk(AtRootNode $child, TraversalContext $ctx): ?array
    {
        $saved        = $this->render->savePosition();
        $ruleWasEmpty = ! $this->render->outputState()->deferral->currentRuleHasOutput;
        $atRootResult = $this->selector->compileAtRootBody($child, $ctx->env);
        $chunk        = $atRootResult['chunk'];

        if ($chunk === '') {
            $this->render->restorePosition($saved);

            return null;
        }

        return [
            'chunk'         => $chunk,
            'deferredChunk' => new GroupStartChunk(
                $this->render->createDeferredChunk($chunk, $saved),
                fromInclude: true,
                isEarly: $ruleWasEmpty,
            ),
            'escapeLevels'  => $atRootResult['escapeLevels'],
            'saved'         => $saved,
        ];
    }

    /**
     * @return array{
     *     chunk:string,
     *     deferredChunk:DeferredChunk,
     *     saved:array{0: int, 1: int, 2: int}
     * }|null
     */
    private function prepareCompiledChunk(Visitable $node, TraversalContext $ctx): ?array
    {
        $saved = $this->render->savePosition();
        $chunk = $this->render->trimAndAdjustState(
            $this->dispatcher->compileWithContext($node, $ctx),
        );

        if ($chunk === '') {
            $this->render->restorePosition($saved);

            return null;
        }

        return [
            'chunk'         => $chunk,
            'deferredChunk' => $this->render->createDeferredChunk($chunk, $saved),
            'saved'         => $saved,
        ];
    }

    /**
     * @param list<AtRuleContextEntry> $atRuleStack
     * @param array{0: int, 1: int, 2: int} $saved
     * @return array{
     *     chunk:string,
     *     deferredChunk:DeferredChunk
     * }
     */
    private function compileMergedMediaChunk(
        array $atRuleStack,
        string $parentMediaPrelude,
        DirectiveNode $bubblingNode,
        DirectiveNode $child,
        Scope $scope,
        TraversalContext $ctx,
        array $saved,
    ): array {
        $mergedPrelude = $this->selector->combineMediaQueryPreludes(
            $parentMediaPrelude,
            $this->selector->resolveDirectivePrelude($child->prelude, $ctx->env),
        );

        $mergedNode = new DirectiveNode('media', $mergedPrelude, $bubblingNode->body, true);
        $scope->setVariableLocal('__at_rule_stack', $this->removeLastMediaEntryFromAtRuleStack($atRuleStack));

        $outerCtx = new TraversalContext($ctx->env, max(0, $ctx->indent - 1));
        $chunk    = $this->render->trimAndAdjustState(
            $this->dispatcher->compileWithContext($mergedNode, $outerCtx),
        );

        $scope->setVariableLocal('__at_rule_stack', $atRuleStack);

        return [
            'chunk'         => $chunk,
            'deferredChunk' => $this->render->createDeferredChunk($chunk, $saved),
        ];
    }

    private function separatorBetween(?OutputChunk $previous, OutputChunk $chunk): string
    {
        if ($previous instanceof GroupStartChunk && (
            $chunk instanceof GroupStartChunk || $previous->fromInclude
        )) {
            return "\n";
        }

        return "\n" . Render::CONTINUATION_MARK;
    }
}
