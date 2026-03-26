<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\SelectorHelper;
use Bugo\SCSS\Utils\SelectorTokenizer;
use Closure;

use function array_flip;
use function array_keys;
use function array_reverse;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function ctype_alnum;
use function implode;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

final readonly class ExtendsResolver
{
    /**
     * @param Closure(string): array<int, string> $splitTopLevelSelectorList
     * @param Closure(string, string): string $resolveNestedSelector
     */
    public function __construct(
        private CompilerContext $ctx,
        private Text $text,
        private SelectorTokenizer $tokenizer,
        private Closure $splitTopLevelSelectorList,
        private Closure $resolveNestedSelector
    ) {}

    public function collectExtends(AstNode $node, Environment $env): void
    {
        if ($node instanceof RootNode) {
            foreach ($node->children as $child) {
                $this->collectExtends($child, $env);
            }

            return;
        }

        if ($node instanceof RuleNode) {
            $selector = $this->text->interpolateText($node->selector, $env);

            $parentSelectorNode = $env->getCurrentScope()->getStringVariable('__parent_selector');
            $parentSelector     = $parentSelectorNode?->value;

            if (
                $parentSelector !== null
                && str_contains($selector, '&')
                && ! str_contains($parentSelector, '%')
            ) {
                $selector = ($this->resolveNestedSelector)($selector, $parentSelector);
            }

            $currentContext = $this->getCurrentExtendDirectiveContext($env);
            $outputState    = $this->ctx->outputState;

            foreach (($this->splitTopLevelSelectorList)($selector) as $selectorPart) {
                if ($selectorPart === '') {
                    continue;
                }

                $outputState->selectorContexts[$selectorPart] ??= [];
                $outputState->selectorContexts[$selectorPart][$currentContext] = true;
            }

            $env->enterScope();
            $env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode($selector));

            foreach ($node->children as $child) {
                if ($child instanceof ExtendNode) {
                    foreach ($this->extractSimpleExtendTargetSelectors($child->selector) as $extendTarget) {
                        $outputState->pendingExtends[] = [
                            'target'  => $extendTarget,
                            'source'  => $selector,
                            'context' => $currentContext,
                        ];
                    }
                }

                $this->collectExtends($child, $env);
            }

            $env->exitScope();

            return;
        }

        if ($node instanceof SupportsNode) {
            $condition      = trim($node->condition);
            $contextSegment = '@supports' . ($condition !== '' ? ' ' . $condition : '');

            $this->collectExtendsInDirectiveContext($node->body, $contextSegment, $env);

            return;
        }

        if ($node instanceof DirectiveNode) {
            if (! $node->hasBlock) {
                return;
            }

            $name           = strtolower(trim($node->name));
            $prelude        = trim($node->prelude);
            $contextSegment = '@' . $name . ($prelude !== '' ? ' ' . $prelude : '');

            $this->collectExtendsInDirectiveContext($node->body, $contextSegment, $env);

            return;
        }

        if (! $node instanceof AtRootNode) {
            return;
        }

        foreach ($node->body as $child) {
            $this->collectExtends($child, $env);
        }
    }

    public function finalizeCollectedExtends(): void
    {
        $outputState = $this->ctx->outputState;

        foreach ($outputState->pendingExtends as [
            'target'  => $target,
            'source'  => $source,
            'context' => $sourceContext,
        ]) {
            $this->assertExtendContextIsCompatible($target, $sourceContext);
            $this->registerExtend($target, $source);
        }
    }

    public function registerExtend(string $target, string $source): void
    {
        $target = trim($target);
        $source = trim($source);

        if ($target === '' || $source === '') {
            return;
        }

        $state = $this->ctx->outputState;

        $state->extendMap[$target] ??= [];
        $state->extendMap[$target][] = $source;
    }

    /**
     * @return array<int, string>
     */
    public function extractSimpleExtendTargetSelectors(string $target): array
    {
        $target = trim($target);

        if ($target === '') {
            return [];
        }

        $targets = ($this->splitTopLevelSelectorList)($target);

        foreach ($targets as $item) {
            $this->assertSimpleExtendTargetSelector($item);
        }

        return $targets;
    }

    public function applyExtendsToSelector(string $selector): string
    {
        $parts  = SelectorHelper::splitList($selector, false);
        $result = [];
        $exact  = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (! str_starts_with($part, '%')) {
                $result[] = $part;
                $exact[]  = $part;
            }

            $extenders = $this->collectTransitiveExactExtenders($part);

            array_push($result, ...$extenders);
            array_push($exact, ...$extenders);
            array_push($result, ...$this->collectReplacementVariants($part));
        }

        $unique      = array_values(array_unique($result));
        $uniqueExact = array_values(array_unique($exact));

        return implode(', ', $this->trimRedundantSelectors($unique, $uniqueExact));
    }

    /**
     * @return array<int, string>
     */
    private function collectTransitiveExactExtenders(string $part): array
    {
        $result  = [];
        $pending = array_reverse($this->ctx->outputState->extendMap[$part] ?? []);
        $seen    = [];
        $index   = 0;

        while ($index < count($pending)) {
            $extender = $pending[$index++];

            if (isset($seen[$extender])) {
                continue;
            }

            $seen[$extender] = true;

            $result[] = $extender;

            foreach (array_reverse($this->ctx->outputState->extendMap[$extender] ?? []) as $nestedExtender) {
                if ($nestedExtender === '' || isset($seen[$nestedExtender])) {
                    continue;
                }

                $pending[] = $nestedExtender;
            }
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function collectReplacementVariants(string $part): array
    {
        $result  = [];
        $pending = [$part];
        $seen    = [$part => true];
        $index   = 0;

        while ($index < count($pending)) {
            $currentPart = $pending[$index++];

            foreach ($this->getOrderedReplacementTargets($currentPart) as $target) {
                foreach ($this->generateExtendedVariants($currentPart, $target) as $extendedPart) {
                    if ($extendedPart === '' || isset($seen[$extendedPart])) {
                        continue;
                    }

                    $seen[$extendedPart] = true;
                    $result[]  = $extendedPart;
                    $pending[] = $extendedPart;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function generateExtendedVariants(string $part, string $target): array
    {
        $variants = [];

        foreach (array_reverse($this->ctx->outputState->extendMap[$target] ?? []) as $extender) {
            array_push($variants, ...$this->replaceExtendTargetInSelectorPart($part, $target, $extender));
        }

        return $variants;
    }

    /**
     * @return array<int, string>
     */
    private function getOrderedReplacementTargets(string $part): array
    {
        $targets = [];
        $seen    = [];
        $parts   = $this->splitSelectorCompoundsByDescendant($part);

        foreach (array_reverse($parts) as $compound) {
            foreach (array_reverse($this->tokenizeSelectorCompound($compound)) as $target) {
                if (
                    $target === ''
                    || $target === $part
                    || isset($seen[$target])
                    || ! isset($this->ctx->outputState->extendMap[$target])
                    || $this->ctx->outputState->extendMap[$target] === []
                ) {
                    continue;
                }

                $seen[$target] = true;
                $targets[]     = $target;
            }
        }

        return $targets;
    }

    /**
     * @param array<int, string> $selectors
     * @param array<int, string> $protectedSelectors
     * @return array<int, string>
     */
    private function trimRedundantSelectors(array $selectors, array $protectedSelectors = []): array
    {
        $protectedLookup = array_flip($protectedSelectors);

        $trimmed = [];
        foreach ($selectors as $index => $selector) {
            if (isset($protectedLookup[$selector])) {
                $trimmed[] = $selector;

                continue;
            }

            $isRedundant = false;

            foreach ($selectors as $otherIndex => $otherSelector) {
                if ($index === $otherIndex || $selector === $otherSelector) {
                    continue;
                }

                if ($this->selectorsAreEquivalent($otherSelector, $selector)) {
                    if ($otherIndex > $index) {
                        $isRedundant = true;

                        break;
                    }

                    continue;
                }

                if ($this->isSuperselectorOf($otherSelector, $selector)) {
                    $isRedundant = true;

                    break;
                }
            }

            if (! $isRedundant) {
                $trimmed[] = $selector;
            }
        }

        return $trimmed;
    }

    private function selectorsAreEquivalent(string $left, string $right): bool
    {
        if (
            $left === ''
            || $right === ''
            || $this->tokenizer->hasUnsupportedTopLevelCombinator($left)
            || $this->tokenizer->hasUnsupportedTopLevelCombinator($right)
        ) {
            return $left === $right;
        }

        $leftCompounds  = $this->splitSelectorCompoundsByDescendant($left);
        $rightCompounds = $this->splitSelectorCompoundsByDescendant($right);

        if (count($leftCompounds) !== count($rightCompounds)) {
            return false;
        }

        foreach ($leftCompounds as $i => $leftCompound) {
            if (
                ! $this->tokenizer->doesCompoundSatisfy($leftCompound, $rightCompounds[$i])
                || ! $this->tokenizer->doesCompoundSatisfy($rightCompounds[$i], $leftCompound)
            ) {
                return false;
            }
        }

        return true;
    }

    private function isSuperselectorOf(string $superselector, string $selector): bool
    {
        if (
            $superselector === ''
            || $selector === ''
            || $superselector === $selector
            || $this->tokenizer->hasUnsupportedTopLevelCombinator($superselector)
            || $this->tokenizer->hasUnsupportedTopLevelCombinator($selector)
        ) {
            return false;
        }

        $superselectorCompounds = $this->splitSelectorCompoundsByDescendant($superselector);
        $selectorCompounds      = $this->splitSelectorCompoundsByDescendant($selector);

        if (count($superselectorCompounds) !== count($selectorCompounds)) {
            return false;
        }

        $isStrict = false;

        foreach ($selectorCompounds as $i => $selectorCompound) {
            if (! $this->tokenizer->doesCompoundSatisfy($selectorCompound, $superselectorCompounds[$i])) {
                return false;
            }

            if (! $this->tokenizer->doesCompoundSatisfy($superselectorCompounds[$i], $selectorCompound)) {
                $isStrict = true;
            }
        }

        return $isStrict;
    }

    /**
     * @param array<AstNode> $children
     */
    private function collectExtendsInDirectiveContext(array $children, string $contextSegment, Environment $env): void
    {
        $parentContext = $this->getCurrentExtendDirectiveContext($env);

        $context = $parentContext === '' ? $contextSegment : $parentContext . '|' . $contextSegment;

        $env->enterScope();
        $env->getCurrentScope()->setVariableLocal('__extend_directive_context', new StringNode($context));

        foreach ($children as $child) {
            $this->collectExtends($child, $env);
        }

        $env->exitScope();
    }

    private function getCurrentExtendDirectiveContext(Environment $env): string
    {
        $node = $env->getCurrentScope()->getStringVariable('__extend_directive_context');

        return $node !== null ? $node->value : '';
    }

    private function assertSimpleExtendTargetSelector(string $target): void
    {
        $target = trim($target);

        if ($target === '') {
            return;
        }

        if ($this->tokenizer->hasUnsupportedTopLevelCombinator($target)) {
            throw new SassErrorException(
                'Complex selectors may not be extended. Use a simple selector target in @extend.'
            );
        }

        $compounds = $this->splitSelectorCompoundsByDescendant($target);

        if (count($compounds) !== 1) {
            throw new SassErrorException(
                'Complex selectors may not be extended. Use a simple selector target in @extend.'
            );
        }

        $tokens = $this->tokenizeSelectorCompound($compounds[0]);

        if (count($tokens) !== 1) {
            throw new SassErrorException(
                'Compound selectors may not be extended. Use separate @extend directives for each simple selector.'
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function splitSelectorCompoundsByDescendant(string $selector): array
    {
        return $this->tokenizer->splitAtTopLevel($selector, [' '], handleQuotes: true);
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeSelectorCompound(string $compound): array
    {
        return $this->tokenizer->tokenizeCompound($compound);
    }

    /**
     * @return array<int, string>
     */
    private function replaceExtendTargetInSelectorPart(string $part, string $target, string $extender): array
    {
        $structured = $this->replaceExtendTargetInStructuredSelectorPart($part, $target, $extender);

        if ($structured !== null) {
            return $structured;
        }

        $fallback = $this->replaceExtendTargetInSelectorPartFallback($part, $target, $extender);

        return $fallback === null ? [] : [$fallback];
    }

    /**
     * @return array<int, string>|null
     */
    private function replaceExtendTargetInStructuredSelectorPart(string $part, string $target, string $extender): ?array
    {
        if (
            $this->tokenizer->hasUnsupportedTopLevelCombinator($part)
            || $this->tokenizer->hasUnsupportedTopLevelCombinator($target)
            || $this->tokenizer->hasUnsupportedTopLevelCombinator($extender)
        ) {
            return null;
        }

        $targetTokens = $this->tokenizeSelectorCompound($target);

        if ($targetTokens === []) {
            return null;
        }

        $partCompounds     = $this->splitSelectorCompoundsByDescendant($part);
        $extenderCompounds = $this->splitSelectorCompoundsByDescendant($extender);

        return $this->tokenizer->replaceExtendTargetInStructuredSelector(
            $partCompounds,
            $targetTokens,
            $extenderCompounds
        );
    }

    private function replaceExtendTargetInSelectorPartFallback(string $part, string $target, string $extender): ?string
    {
        $partLength   = strlen($part);
        $targetLength = strlen($target);

        if ($targetLength === 0 || $partLength < $targetLength) {
            return null;
        }

        $position = strpos($part, $target);

        while ($position !== false) {
            $start      = $position;
            $end        = $start + $targetLength;
            $beforeChar = $start > 0 ? $part[$start - 1] : '';
            $afterChar  = $end < $partLength ? $part[$end] : '';

            if ($this->isValidSelectorTokenBoundary($target, $beforeChar, $afterChar)) {
                $woven = $this->weaveFallbackExtendedSelector(
                    trim(substr($part, 0, $start)),
                    trim(substr($part, $end)),
                    $extender
                );

                if ($woven !== null) {
                    return $woven;
                }

                return substr($part, 0, $start) . $extender . substr($part, $end);
            }

            $position = strpos($part, $target, $start + 1);
        }

        return null;
    }

    private function weaveFallbackExtendedSelector(string $before, string $after, string $extender): ?string
    {
        if ($before === '' || $this->tokenizer->hasUnsupportedTopLevelCombinator($extender)) {
            return null;
        }

        if (! str_contains($before, '>') && ! str_contains($before, '+') && ! str_contains($before, '~')) {
            return null;
        }

        $extenderCompounds = $this->splitSelectorCompoundsByDescendant($extender);

        if (count($extenderCompounds) < 2) {
            return null;
        }

        $last     = $extenderCompounds[count($extenderCompounds) - 1];
        $segments = [...array_slice($extenderCompounds, 0, -1), $before, $last];

        if ($after !== '') {
            $segments[] = $after;
        }

        return implode(' ', $segments);
    }

    private function isValidSelectorTokenBoundary(string $target, string $beforeChar, string $afterChar): bool
    {
        $firstChar         = $target[0];
        $startsWithSpecial = $firstChar === '.' || $firstChar === '#' || $firstChar === '%';

        $leftBoundary = $beforeChar === ''
            || $startsWithSpecial
            || ! ctype_alnum($beforeChar) && $beforeChar !== '-' && $beforeChar !== '_';

        $rightBoundary = $afterChar === '' || ! ctype_alnum($afterChar) && $afterChar !== '-' && $afterChar !== '_';

        return $leftBoundary && $rightBoundary;
    }

    private function assertExtendContextIsCompatible(string $target, string $sourceContext): void
    {
        foreach (array_keys($this->ctx->outputState->selectorContexts[$target] ?? []) as $targetContext) {
            if ($targetContext !== $sourceContext) {
                throw new SassErrorException('You may not @extend selectors across media queries.');
            }
        }
    }
}
