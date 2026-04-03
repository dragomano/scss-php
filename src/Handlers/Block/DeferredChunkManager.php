<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Block;

use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StatementNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Style;
use Bugo\SCSS\Utils\SourceMapMapping;

use function array_splice;
use function array_values;
use function count;
use function max;
use function str_contains;
use function strtolower;
use function trim;

/**
 * @phpstan-type AtRuleStackEntry array{
 *     type: string,
 *     name?: string,
 *     prelude?: string,
 *     condition?: string
 * }
 */
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
     * @param array<int, array{
     *     chunk:string,
     *     baseLine:int,
     *     baseColumn:int,
     *     mappings:array<int, SourceMapMapping>
     * }|string> $leadingRootChunks
     * @param array<int, array{
     *     chunk:string,
     *     baseLine:int,
     *     baseColumn:int,
     *     mappings:array<int, SourceMapMapping>
     * }|string> $trailingRootChunks
     */
    public function buildRuleResult(
        string $output,
        array $leadingRootChunks,
        array $trailingRootChunks,
    ): string {
        $segmentSeparator = $this->render->outputSeparator();

        $result = '';

        foreach ($leadingRootChunks as $index => $chunk) {
            if ($index > 0) {
                $this->render->appendChunk($result, "\n");
            }

            $this->appendResolvedChunk($result, $chunk);
        }

        if ($output !== '') {
            $ruleOutput = $this->render->trimTrailingNewlines($output);

            if ($ruleOutput !== '') {
                if ($result !== '') {
                    $result .= $segmentSeparator;
                }

                $result .= $ruleOutput;
            }
        }

        foreach ($trailingRootChunks as $chunk) {
            if ($result !== '') {
                $this->render->appendChunk($result, $segmentSeparator);
            }

            $this->appendResolvedChunk($result, $chunk);
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
        $atRuleStackIndex = count($this->render->outputState()->deferredAtRuleStack) - 1;

        if ($atRuleStackIndex < 0) {
            return false;
        }

        $this->render->outputState()->deferredAtRuleStack[$atRuleStackIndex][] = [
            'levels' => $levels,
            'chunk'  => $chunk,
        ];

        return true;
    }

    /**
     * @param array<int, array{
     *     chunk:string,
     *     baseLine:int,
     *     baseColumn:int,
     *     mappings:array<int, SourceMapMapping>
     * }|string> $leadingRootChunks
     * @param array<int, array{
     *     chunk:string,
     *     baseLine:int,
     *     baseColumn:int,
     *     mappings:array<int, SourceMapMapping>
     * }|string> $trailingRootChunks
     */
    public function collectRuleBubblingChunk(
        array &$leadingRootChunks,
        array &$trailingRootChunks,
        bool $hasRenderedChildren,
        string $selector,
        Scope $scope,
        AstNode $child,
        TraversalContext $ctx,
    ): void {
        /** @var StatementNode $child */
        $bubblingNode = $this->evaluation->normalizeBubblingNodeForSelector($child, $selector);
        $saved        = $this->render->savePosition();

        if ($child instanceof DirectiveNode && strtolower($child->name) === 'media') {
            $atRuleStack        = $this->selector->getCurrentAtRuleStack($ctx->env);
            $parentMediaPrelude = $this->findLastMediaPrelude($atRuleStack);

            if ($parentMediaPrelude !== null && $bubblingNode instanceof DirectiveNode) {
                $mergedPrelude = $this->selector->combineMediaQueryPreludes(
                    $parentMediaPrelude,
                    $this->selector->resolveDirectivePrelude($child->prelude, $ctx->env),
                );

                $bubblingNode = new DirectiveNode('media', $mergedPrelude, $bubblingNode->body, true);

                $stackWithoutLastMedia = $this->removeLastMediaEntryFromAtRuleStack($atRuleStack);

                $scope->setVariableLocal('__at_rule_stack', $stackWithoutLastMedia);

                $outerCtx = new TraversalContext($ctx->env, max(0, $ctx->indent - 1));

                $chunk = $this->render->trimAndAdjustState(
                    $this->dispatcher->compileWithContext($bubblingNode, $outerCtx),
                );

                $deferredChunk = $this->render->createDeferredChunk($chunk, $saved);

                $scope->setVariableLocal('__at_rule_stack', $atRuleStack);

                if ($chunk !== '') {
                    $this->render->restorePosition($saved);

                    if ($this->appendDeferredAtRuleChunk(1, $chunk)) {
                        return;
                    }

                    if ($hasRenderedChildren) {
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

            if ($hasRenderedChildren) {
                $this->render->restorePosition($saved);

                $trailingRootChunks[] = $deferredChunk;

                return;
            }

            $leadingRootChunks[] = $deferredChunk;
        }
    }

    /**
     * @param array<int, array{
     *     chunk:string,
     *     baseLine:int,
     *     baseColumn:int,
     *     mappings:array<int, SourceMapMapping>
     * }|string> $trailingRootChunks
     */
    public function collectRuleAtRootChunk(array &$trailingRootChunks, AtRootNode $child, TraversalContext $ctx): void
    {
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

        $trailingRootChunks[] = $preparedChunk['deferredChunk'];
    }

    /**
     * @param array<int, array{
     *     chunk:string,
     *     baseLine:int,
     *     baseColumn:int,
     *     mappings:array<int, SourceMapMapping>
     * }|string> $trailingRootChunks
     */
    public function collectDeferredIncludeRootChunks(array &$trailingRootChunks, int $deferredAtRootCount): void
    {
        $outputState      = $this->render->outputState();
        $atRootStackIndex = count($outputState->deferredAtRootStack) - 1;

        if ($atRootStackIndex < 0) {
            return;
        }

        $deferred      = $outputState->deferredAtRootStack[$atRootStackIndex];
        $deferredCount = count($deferred);

        if ($deferredCount <= $deferredAtRootCount) {
            return;
        }

        /** @var array<int, array{
         *     chunk:string,
         *     baseLine:int,
         *     baseColumn:int,
         *     mappings:array<int, SourceMapMapping>
         * }|string> $newChunks */
        $newChunks = array_splice($deferred, $deferredAtRootCount);

        $outputState->deferredAtRootStack[$atRootStackIndex] = $deferred;

        foreach ($newChunks as $chunk) {
            $trailingRootChunks[] = $chunk;
        }
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

        $stackIndex = count($this->render->outputState()->deferredBubblingStack) - 1;

        if ($stackIndex >= 0) {
            if ($this->shouldDeferBubblingChunkToTrailingRoot($child)) {
                $this->render->restorePosition($preparedChunk['saved']);

                $atRootStackIndex = count($this->render->outputState()->deferredAtRootStack) - 1;

                if ($atRootStackIndex >= 0) {
                    $this->render->outputState()->deferredAtRootStack[$atRootStackIndex][] = $preparedChunk['deferredChunk'];
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
    ): void {
        $parentSelector = $this->selector->getCurrentParentSelector($ctx->env);
        $childSelector  = $this->resolveRuleSelector($child, $ctx);

        $compiled = $this->compileNestedPropertyBlock($child, $childSelector, $ctx, $ctx->indent);

        if ($compiled !== null && $compiled !== '') {
            $this->appendOutputChunk($output, $first, $compiled);

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

        if ($this->appendDeferredRootChunk($preparedChunk['deferredChunk'])) {
            $this->render->restorePosition($preparedChunk['saved']);

            return;
        }

        $this->appendOutputChunk($output, $first, $preparedChunk['deferredChunk']);
    }

    /**
     * @param array{chunk:string, baseLine:int, baseColumn:int, mappings:array<int, SourceMapMapping>}|string $chunk
     */
    public function appendOutputChunk(string &$output, bool &$first, array|string $chunk): void
    {
        if (! $first) {
            $this->render->appendChunk($output, "\n");
        }

        $this->appendResolvedChunk($output, $chunk);

        $first = false;
    }

    /**
     * @param array{chunk:string, baseLine:int, baseColumn:int, mappings:array<int, SourceMapMapping>}|string $chunk
     */
    public function appendDeferredRootChunk(array|string $chunk): bool
    {
        $stackIndex = count($this->render->outputState()->deferredAtRootStack) - 1;

        if ($stackIndex < 0) {
            return false;
        }

        $this->render->outputState()->deferredAtRootStack[$stackIndex][] = $chunk;

        return true;
    }

    /**
     * @param array{chunk:string, baseLine:int, baseColumn:int, mappings:array<int, SourceMapMapping>}|string $chunk
     */
    public function appendDeferredBubblingChunk(array|string $chunk): bool
    {
        $stackIndex = count($this->render->outputState()->deferredBubblingStack) - 1;

        if ($stackIndex < 0) {
            return false;
        }

        $this->render->outputState()->deferredBubblingStack[$stackIndex][] = $chunk;

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
     * @param array<int, array{
     *     chunk:string,
     *     baseLine:int,
     *     baseColumn:int,
     *     mappings:array<int, SourceMapMapping>
     * }|string> $trailingRootChunks
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
                $this->render->appendChunk($output, $prefix . $selector . ' {', $node);
                $hasRenderedChildren = true;
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
                if ($output !== '') {
                    $this->render->appendChunk($output, "\n");
                }

                foreach ($trailingRootChunks as $index => $rootChunk) {
                    if ($index > 0) {
                        $this->render->appendChunk($output, "\n");
                    }

                    $this->appendResolvedChunk($output, $rootChunk);
                }

                $trailingRootChunks = [];
            }

            if ($output !== '') {
                $this->render->appendChunk($output, "\n");
            }

            $this->appendResolvedChunk($output, $preparedChunk['deferredChunk']);

            $containsStandaloneNestedRuleChunks = true;
        }
    }

    /**
     * @param array<int, AtRuleStackEntry> $stack
     */
    private function findLastMediaPrelude(array $stack): ?string
    {
        for ($index = count($stack) - 1; $index >= 0; $index--) {
            $entry = $stack[$index];

            if ($entry['type'] !== 'directive' || ($entry['name'] ?? '') !== 'media') {
                continue;
            }

            return trim($entry['prelude'] ?? '');
        }

        return null;
    }

    /**
     * @param array<int, AtRuleStackEntry> $stack
     * @return array<int, AtRuleStackEntry>
     */
    private function removeLastMediaEntryFromAtRuleStack(array $stack): array
    {
        for ($index = count($stack) - 1; $index >= 0; $index--) {
            $entry = $stack[$index];

            if ($entry['type'] === 'directive' && isset($entry['name']) && $entry['name'] === 'media') {
                unset($stack[$index]);

                return array_values($stack);
            }
        }

        return $stack;
    }

    private function shouldDeferBubblingChunkToTrailingRoot(AstNode $node): bool
    {
        if ($node instanceof SupportsNode) {
            return true;
        }

        return $node instanceof DirectiveNode && strtolower($node->name) === 'media';
    }

    /**
     * @return array{
     *     chunk:string,
     *     deferredChunk:array{chunk:string, baseLine:int, baseColumn:int, mappings:array<int, SourceMapMapping>},
     *     escapeLevels:int,
     *     saved:array{0: int, 1: int, 2: int}
     * }|null
     */
    private function prepareAtRootChunk(AtRootNode $child, TraversalContext $ctx): ?array
    {
        $saved        = $this->render->savePosition();
        $atRootResult = $this->selector->compileAtRootBody($child, $ctx->env);
        $chunk        = $atRootResult['chunk'];

        if ($chunk === '') {
            $this->render->restorePosition($saved);

            return null;
        }

        return [
            'chunk'         => $chunk,
            'deferredChunk' => $this->render->createDeferredChunk($chunk, $saved),
            'escapeLevels'  => $atRootResult['escapeLevels'],
            'saved'         => $saved,
        ];
    }

    /**
     * @return array{
     *     chunk:string,
     *     deferredChunk:array{chunk:string, baseLine:int, baseColumn:int, mappings:array<int, SourceMapMapping>},
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
     * @param array{chunk:string, baseLine:int, baseColumn:int, mappings:array<int, SourceMapMapping>}|string $chunk
     */
    private function appendResolvedChunk(string &$output, array|string $chunk): void
    {
        if (is_string($chunk)) {
            $this->render->appendChunk($output, $chunk);

            return;
        }

        $this->render->appendDeferredChunk($output, $chunk);
    }
}
