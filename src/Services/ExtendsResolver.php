<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\Exceptions\InvalidLoopBoundaryException;
use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\EachNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\NameNormalizer;
use Bugo\SCSS\Utils\SelectorHelper;
use Bugo\SCSS\Utils\SelectorTokenizer;

use function array_column;
use function array_flip;
use function array_keys;
use function array_push;
use function array_reverse;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function ctype_alnum;
use function implode;
use function is_numeric;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function usort;

final readonly class ExtendsResolver
{
    private const MAX_EXTEND_VARIANTS = 256;

    private const MAX_EXTEND_VARIANT_COMPOUNDS = 16;

    public function __construct(
        private CompilerContext $ctx,
        private Text $text,
        private SelectorTokenizer $tokenizer,
        private AstValueEvaluatorInterface $valueEvaluator,
        private FunctionConditionEvaluatorInterface $conditionEvaluator,
        private VariableDeclarationApplierInterface $variableDeclarationApplier,
        private EachLoopBinderInterface $eachLoopBinder,
        private AstValueFormatterInterface $valueFormatter,
    ) {}

    public function collectExtends(AstNode $node, Environment $env): void
    {
        if ($node instanceof RootNode) {
            $this->collectRootExtends($node, $env);

            return;
        }

        if ($node instanceof RuleNode) {
            $this->collectRuleExtends($node, $env);

            return;
        }

        if ($node instanceof SupportsNode) {
            $this->collectSupportsExtends($node, $env);

            return;
        }

        if ($node instanceof DirectiveNode) {
            $this->collectDirectiveExtends($node, $env);

            return;
        }

        if ($node instanceof IfNode) {
            $this->collectIfExtends($node, $env);

            return;
        }

        if ($node instanceof EachNode) {
            $this->collectEachExtends($node, $env);

            return;
        }

        if ($node instanceof ForNode) {
            $this->collectForExtends($node, $env);

            return;
        }

        if ($node instanceof WhileNode) {
            $this->collectWhileExtends($node, $env);

            return;
        }

        if (! $node instanceof AtRootNode) {
            return;
        }

        $this->collectChildren($node->body, $env);
    }

    public function finalizeCollectedExtends(): void
    {
        $state = $this->ctx->outputState->extends;

        if ($state->events === []) {
            foreach ($state->pendingExtends as [
                'target'   => $target,
                'source'   => $source,
                'context'  => $sourceContext,
                'optional' => $optional,
                'priority' => $priority,
            ]) {
                if (! $this->assertExtendTargetExists($target, $optional)) {
                    continue;
                }

                $this->assertExtendContextIsCompatible($target, $sourceContext);
                $this->registerExtend($target, $source, $priority);
            }

            return;
        }

        $this->playExtendEvents();
    }

    private function playExtendEvents(): void
    {
        $state = $this->ctx->outputState->extends;

        /** @var array<string, array<int, array{source: string, priority: int}>> $extensionsByTarget */
        $extensionsByTarget = [];

        /** @var array<string, array<int, true>> $selectorsBySimple */
        $selectorsBySimple = [];

        /** @var array<string, true> $originals */
        $originals = [];

        /** @var array<string, int> $sourceSpecificity */
        $sourceSpecificity = [];

        $boxes = [];

        foreach ($state->events as $event) {
            if ($event['type'] === 'rule') {
                $resolvedParts = $event['resolvedParts'];
                $boxId         = $event['boxId'];

                foreach ($resolvedParts as $part) {
                    $originals[$part] = true;
                }

                if ($extensionsByTarget === []) {
                    $selectors = $resolvedParts;
                } else {
                    $selectors = $this->extendList($resolvedParts, $extensionsByTarget);
                }

                $boxes[$boxId] = [
                    'rawParts'  => $event['rawParts'],
                    'selectors' => $this->trimBoxSelectors($selectors, $originals, $sourceSpecificity),
                    'originals' => $resolvedParts,
                    'context'   => $event['context'],
                ];

                foreach ($boxes[$boxId]['selectors'] as $complex) {
                    foreach ($this->simpleSelectorsOf($complex) as $simple) {
                        $selectorsBySimple[$simple][$boxId] = true;
                    }
                }

                continue;
            }

            $target     = $event['target'];
            $sourceBox  = $boxes[$event['boxId']]['selectors'] ?? [];
            $newSources = [];

            if (! $this->assertExtendTargetExists($target, $event['optional'])) {
                continue;
            }

            $this->assertExtendContextIsCompatible($target, $event['context']);

            foreach ($sourceBox as $source) {
                if ($source === $target) {
                    continue;
                }

                $exists = false;

                foreach ($extensionsByTarget[$target] ?? [] as $extension) {
                    if ($extension['source'] === $source) {
                        $exists = true;

                        break;
                    }
                }

                if ($exists) {
                    continue;
                }

                $extensionsByTarget[$target][] = [
                    'source'   => $source,
                    'priority' => $event['priority'],
                ];

                foreach ($this->simpleSelectorsOf($source) as $simple) {
                    $sourceSpecificity[$simple] ??= $this->selectorSpecificity($source);
                }

                $newSources[] = [
                    'source'   => $source,
                    'priority' => $event['priority'],
                ];
            }

            if ($newSources === []) {
                continue;
            }

            $snapshot = $extensionsByTarget;

            foreach ($snapshot as $oldTarget => $existingExtensions) {
                foreach ($existingExtensions as $existing) {
                    if (! $this->complexContainsSimple($existing['source'], $target)) {
                        continue;
                    }

                    $extendedExtenders = $this->extendList([$existing['source']], [$target => $newSources]);

                    foreach (array_slice($extendedExtenders, 1) as $extended) {
                        if (
                            $extended === $existing['source']
                            || $this->hasExtensionSource($extensionsByTarget[$oldTarget] ?? [], $extended)
                        ) {
                            continue;
                        }

                        $transitive = [
                            'source'   => $extended,
                            'priority' => $event['priority'],
                        ];

                        $extensionsByTarget[$oldTarget][] = $transitive;

                        if ($oldTarget === $target) {
                            $newSources[] = $transitive;
                        }
                    }
                }
            }

            foreach (array_keys($selectorsBySimple[$target] ?? []) as $affectedBoxId) {
                $box          = $boxes[$affectedBoxId];
                $oldSelectors = $box['selectors'];
                $newSelectors = $this->trimBoxSelectors(
                    $this->extendList($oldSelectors, [$target => $newSources]),
                    $originals,
                    $sourceSpecificity,
                );

                if ($newSelectors === $oldSelectors) {
                    continue;
                }

                foreach ($oldSelectors as $complex) {
                    foreach ($this->simpleSelectorsOf($complex) as $simple) {
                        unset($selectorsBySimple[$simple][$affectedBoxId]);
                    }
                }

                $box['selectors']      = $newSelectors;
                $boxes[$affectedBoxId] = $box;

                foreach ($newSelectors as $complex) {
                    foreach ($this->simpleSelectorsOf($complex) as $simple) {
                        $selectorsBySimple[$simple][$affectedBoxId] = true;
                    }
                }
            }
        }

        $state->boxes     = $boxes;
        $state->extendMap = [];

        foreach ($extensionsByTarget as $target => $extensions) {
            foreach ($extensions as $extension) {
                $state->extendMap[$target][] = $extension;
            }
        }
    }

    /**
     * @param array<int, string> $selectors
     * @param array<string, array<int, array{source: string, priority: int}>> $extensionsByTarget
     * @return array<int, string>
     */
    private function extendList(array $selectors, array $extensionsByTarget): array
    {
        $result = [];

        foreach ($selectors as $complex) {
            $result[] = $complex;

            foreach ($extensionsByTarget as $target => $extensions) {
                $variants = [];

                foreach ($extensions as $extension) {
                    $source = $extension['source'];

                    if ($source === $target) {
                        continue;
                    }

                    foreach ($this->replaceExtendTargetInSelectorPart($complex, $target, $source) as $variant) {
                        if ($variant !== '' && $variant !== $complex && ! in_array($variant, $variants, true)) {
                            $variants[] = $variant;
                        }
                    }
                }

                array_push($result, ...$variants);
            }
        }

        return $result;
    }

    /**
     * @param array<int, string> $selectors
     * @param array<string, true> $originals
     * @param array<string, int> $sourceSpecificity
     * @return array<int, string>
     */
    private function trimBoxSelectors(array $selectors, array $originals, array $sourceSpecificity): array
    {
        $protectedLookup = array_flip(array_keys($originals));

        $result = [];

        for ($i = count($selectors) - 1; $i >= 0; $i--) {
            $complex = $selectors[$i];

            if (isset($protectedLookup[$complex])) {
                $duplicateIndex = array_search($complex, $result, true);

                if ($duplicateIndex === false) {
                    array_unshift($result, $complex);

                    continue;
                }

                $slice   = array_slice($result, 0, $duplicateIndex + 1);
                $rotated = array_merge([array_pop($slice)], $slice);
                $result  = array_merge($rotated, array_slice($result, $duplicateIndex + 1));

                continue;
            }

            $maxSpecificity = $this->maxSourceSpecificity($complex, $sourceSpecificity);

            $isRedundant = false;

            foreach ($result as $candidate) {
                if (
                    $this->selectorSpecificity($candidate) >= $maxSpecificity
                    && $this->isSuperselectorOf($candidate, $complex)
                ) {
                    $isRedundant = true;

                    break;
                }
            }

            if (! $isRedundant) {
                for ($j = 0; $j < $i; $j++) {
                    $candidate = $selectors[$j];

                    if (
                        $this->selectorSpecificity($candidate) >= $maxSpecificity
                        && $this->isSuperselectorOf($candidate, $complex)
                    ) {
                        $isRedundant = true;

                        break;
                    }
                }
            }

            if (! $isRedundant) {
                array_unshift($result, $complex);
            }
        }

        return $result;
    }

    /**
     * @param array<string, int> $sourceSpecificity
     */
    private function maxSourceSpecificity(string $complex, array $sourceSpecificity): int
    {
        $max = 0;

        foreach ($this->splitSelectorCompoundsByDescendant($complex) as $compound) {
            foreach ($this->tokenizeSelectorCompound($compound) as $simple) {
                if ($simple !== '') {
                    $max = max($max, $sourceSpecificity[$simple] ?? 0);
                }
            }
        }

        return $max;
    }

    private function selectorSpecificity(string $complex): int
    {
        $specificity = 0;

        foreach ($this->splitSelectorCompoundsByDescendant($complex) as $compound) {
            foreach ($this->tokenizeSelectorCompound($compound) as $simple) {
                $specificity += $this->simpleSpecificity($simple);
            }
        }

        return $specificity;
    }

    private function simpleSpecificity(string $simple): int
    {
        if ($simple === '' || $simple === '*' || $simple === '*|*' || $simple[0] === '%') {
            return 0;
        }

        if ($simple[0] === '#') {
            return 100;
        }

        if ($simple[0] === '.' || $simple[0] === '[') {
            return 10;
        }

        if ($simple[0] === ':' && ($simple[1] ?? '') !== ':') {
            return 10;
        }

        return 1;
    }

    /**
     * @return array<int, string>
     */
    private function simpleSelectorsOf(string $complex): array
    {
        $simples = [];

        foreach ($this->splitSelectorCompoundsByDescendant($complex) as $compound) {
            foreach ($this->tokenizeSelectorCompound($compound) as $simple) {
                if ($simple !== '') {
                    $simples[] = $simple;
                }
            }
        }

        return $simples;
    }

    /**
     * @param array<int, array{source: string, priority: int}> $extensions
     */
    private function hasExtensionSource(array $extensions, string $source): bool
    {
        foreach ($extensions as $extension) {
            if ($extension['source'] === $source) {
                return true;
            }
        }

        return false;
    }

    private function complexContainsSimple(string $complex, string $simple): bool
    {
        return in_array($simple, $this->simpleSelectorsOf($complex), true);
    }

    public function registerExtend(string $target, string $source, int $priority = 0): void
    {
        $target = $this->tokenizer->normalizeSelectorAttributes(trim($target));
        $source = $this->tokenizer->normalizeSelectorAttributes(trim($source));

        if ($target === '' || $source === '' || $target === $source) {
            return;
        }

        $state = $this->ctx->outputState;

        $state->extends->extendMap[$target] ??= [];
        $state->extends->extendMap[$target][] = [
            'source'   => $source,
            'priority' => $priority,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function extractSimpleExtendTargetSelectors(string $target): array
    {
        $target = $this->tokenizer->normalizeSelectorAttributes(trim($target));

        if ($target === '') {
            return [];
        }

        $targets = $this->splitTopLevelSelectorList($target);

        foreach ($targets as $item) {
            $this->assertSimpleExtendTargetSelector($item);
        }

        return $targets;
    }

    public function applyExtendsToSelector(string $selector): string
    {
        if (! $this->hasCollectedExtends() && ! str_contains($selector, '%')) {
            return $selector;
        }

        $state = $this->ctx->outputState->extends;

        if ($state->boxes !== []) {
            $parts = $this->splitTopLevelSelectorList($selector);

            foreach ($state->boxes as $box) {
                if ($box['rawParts'] === $parts) {
                    return $this->renderBoxSelectors($box['selectors']);
                }
            }
        }

        $parts  = SelectorHelper::splitList($selector, false);
        $result = [];
        $exact  = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $part = $this->tokenizer->normalizeSelectorAttributes($part);

            $isPlaceholderPart = str_contains($part, '%');

            if (! $isPlaceholderPart) {
                $result[] = $part;
                $exact[]  = $part;
            }

            foreach ($this->applyExtendsIncrementally($part) as $candidate) {
                if (str_contains($candidate, '%')) {
                    continue;
                }

                $result[] = $candidate;
            }
        }

        $unique      = array_values(array_unique($result));
        $uniqueExact = array_values(array_unique($exact));

        return implode(', ', $this->trimRedundantSelectors($unique, $uniqueExact));
    }

    /**
     * @param array<int, string> $selectors
     */
    private function renderBoxSelectors(array $selectors): string
    {
        $filtered = [];

        foreach ($selectors as $selector) {
            if (str_contains($selector, '%')) {
                continue;
            }

            $filtered[] = $selector;
        }

        return implode(', ', array_values(array_unique($filtered)));
    }

    /**
     * @return array<int, string>
     */
    private function applyExtendsIncrementally(string $part): array
    {
        $allResults = [$part];
        $seen       = [$part => true];

        foreach ($this->orderedExtends() as $extend) {
            $next = [];

            foreach ($allResults as $selector) {
                $next[] = $selector;

                foreach ($this->replaceExtendTargetInSelectorPart(
                    $selector,
                    $extend['target'],
                    $extend['source'],
                ) as $variant) {
                    if ($variant !== '' && ! isset($seen[$variant])) {
                        $seen[$variant] = true;
                        $next[]         = $variant;
                    }
                }
            }

            $allResults = $next;
        }

        return $allResults;
    }

    /**
     * @return array<int, array{target: string, source: string}>
     */
    private function orderedExtends(): array
    {
        $extends = [];

        foreach ($this->ctx->outputState->extends->extendMap as $target => $sources) {
            foreach ($sources as $source) {
                $extends[] = [
                    'target'   => $target,
                    'source'   => $source['source'],
                    'priority' => $source['priority'],
                ];
            }
        }

        usort(
            $extends,
            static fn(array $left, array $right): int => $left['priority'] <=> $right['priority'],
        );

        return $extends;
    }

    public function hasCollectedExtends(): bool
    {
        $state = $this->ctx->outputState->extends;

        return $state->extendMap !== []
            || $state->pendingExtends !== []
            || $state->selectorContexts !== []
            || $state->boxes !== [];
    }

    private function collectRootExtends(RootNode $node, Environment $env): void
    {
        foreach ($node->children as $child) {
            if ($child instanceof VariableDeclarationNode) {
                $env->getCurrentScope()->setVariable(
                    $child->name,
                    $child->value,
                    $child->global,
                    $child->default,
                    $child->line,
                );

                continue;
            }

            $this->collectExtends($child, $env);
        }
    }

    private function collectRuleExtends(RuleNode $node, Environment $env): void
    {
        $rawSelector = $this->text->interpolateText($node->selector, $env);
        $selector    = $rawSelector;

        $parentSelectorNode = $env->getCurrentScope()->getStringVariable('__parent_selector');
        $parentSelector     = $parentSelectorNode?->value;

        if ($parentSelector !== null) {
            $selector = str_contains($selector, '&')
                ? SelectorHelper::resolveNested($selector, $parentSelector)
                : $this->combineNestedSelectorWithParent($selector, $parentSelector);
        }

        $currentContext = $this->getCurrentExtendDirectiveContext($env);
        $outputState    = $this->ctx->outputState;

        $rawParts = [];

        foreach ($this->splitTopLevelSelectorList($rawSelector) as $selectorPart) {
            if ($selectorPart === '') {
                continue;
            }

            $rawParts[] = $selectorPart;
        }

        $resolvedParts = [];

        foreach ($this->splitTopLevelSelectorList($selector) as $selectorPart) {
            if ($selectorPart === '') {
                continue;
            }

            $resolvedParts[] = $selectorPart;

            $outputState->extends->selectorContexts[$selectorPart] ??= [];
            $outputState->extends->selectorContexts[$selectorPart][$currentContext] = true;

            foreach ($this->splitSelectorCompoundsByDescendant($selectorPart) as $compound) {
                foreach ($this->tokenizeSelectorCompound($compound) as $simpleSelector) {
                    $outputState->extends->selectorContexts[$simpleSelector] ??= [];
                    $outputState->extends->selectorContexts[$simpleSelector][$currentContext] = true;
                }
            }
        }

        $outputState->extends->ruleCount++;

        $outputState->extends->events[] = [
            'type'          => 'rule',
            'boxId'         => $outputState->extends->ruleCount - 1,
            'rawParts'      => $rawParts,
            'resolvedParts' => $resolvedParts,
            'context'       => $currentContext,
        ];

        $env->enterScope();
        $env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode($selector));

        $outputState->extends->ruleStack[] = $outputState->extends->ruleCount - 1;

        $this->collectChildren($node->children, $env, $selector, $currentContext, applyDeclarations: true);

        array_pop($outputState->extends->ruleStack);

        $env->exitScope();
    }

    private function collectSupportsExtends(SupportsNode $node, Environment $env): void
    {
        $condition      = trim($node->condition);
        $contextSegment = '@supports' . ($condition !== '' ? ' ' . $condition : '');

        $this->collectExtendsInDirectiveContext($node->body, $contextSegment, $env);
    }

    private function collectDirectiveExtends(DirectiveNode $node, Environment $env): void
    {
        if (! $node->hasBlock) {
            return;
        }

        $name           = strtolower(trim($node->name));
        $prelude        = trim($node->prelude);
        $contextSegment = '@' . $name . ($prelude !== '' ? ' ' . $prelude : '');

        /** @var array<int, AstNode> $body */
        $body = $node->body;

        $this->collectExtendsInDirectiveContext($body, $contextSegment, $env);
    }

    private function collectIfExtends(IfNode $node, Environment $env, ?string $selector = null, string $currentContext = ''): void
    {
        $branch = $this->resolveIfBranch($node, $env);

        $this->collectChildren($branch, $env, $selector, $currentContext, applyDeclarations: true);
    }

    private function collectEachExtends(EachNode $node, Environment $env, ?string $selector = null, string $currentContext = ''): void
    {
        $iterableValue = $this->valueEvaluator->evaluate($node->list, $env);
        $items         = $this->eachLoopBinder->items($iterableValue);

        $env->enterScope();

        foreach ($items as $item) {
            $this->eachLoopBinder->assign($node->variables, $item, $env);

            $this->collectChildren($node->body, $env, $selector, $currentContext, applyDeclarations: true);
        }

        $env->exitScope();
    }

    private function collectForExtends(ForNode $node, Environment $env, ?string $selector = null, string $currentContext = ''): void
    {
        $from = (int) $this->toLoopNumber($node->from, $env);
        $to   = (int) $this->toLoopNumber($node->to, $env);

        if (! $node->inclusive) {
            $to += $from <= $to ? -1 : 1;
        }

        $step       = $from <= $to ? 1 : -1;
        $iterations = 0;

        $env->enterScope();

        for ($i = $from; $step > 0 ? $i <= $to : $i >= $to; $i += $step) {
            $iterations++;

            $this->assertIterationLimit($iterations, '@for');

            $env->getCurrentScope()->setVariable($node->variable, new NumberNode($i));

            $this->collectChildren($node->body, $env, $selector, $currentContext, applyDeclarations: true);
        }

        $env->exitScope();
    }

    private function collectWhileExtends(WhileNode $node, Environment $env, ?string $selector = null, string $currentContext = ''): void
    {
        $iterations = 0;

        while ($this->conditionEvaluator->evaluate($node->condition, $env)) {
            $iterations++;

            $this->assertIterationLimit($iterations, '@while');

            $this->collectChildren($node->body, $env, $selector, $currentContext, applyDeclarations: true);
        }
    }

    /**
     * @return array<int, string>
     */
    private function collectTransitiveExactExtenders(string $part): array
    {
        $result  = [];
        $pending = $this->orderedExtendSources($part);
        $seen    = [];
        $index   = 0;

        while ($index < count($pending)) {
            $extender = $pending[$index++];

            if (isset($seen[$extender])) {
                continue;
            }

            $seen[$extender] = true;

            $result[] = $extender;

            foreach ($this->orderedExtendSources($extender) as $nestedExtender) {
                if (isset($seen[$nestedExtender])) {
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
    private function orderedExtendSources(string $target): array
    {
        $extenders = $this->ctx->outputState->extends->extendMap[$target] ?? [];

        usort(
            $extenders,
            static fn(array $left, array $right): int => $right['priority'] <=> $left['priority'],
        );

        return array_column($extenders, 'source');
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

            if (count($this->splitSelectorCompoundsByDescendant($currentPart)) > self::MAX_EXTEND_VARIANT_COMPOUNDS) {
                continue;
            }

            foreach ($this->getOrderedReplacementTargets($currentPart) as $target) {
                foreach ($this->generateExtendedVariants($currentPart, $target) as $extendedPart) {
                    if ($extendedPart === '' || isset($seen[$extendedPart])) {
                        continue;
                    }

                    $seen[$extendedPart] = true;

                    $result[]  = $extendedPart;
                    $pending[] = $extendedPart;

                    if (count($result) >= self::MAX_EXTEND_VARIANTS) {
                        return $result;
                    }
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

        foreach ($this->orderedExtendSources($target) as $extender) {
            if ($extender === $target) {
                continue;
            }

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
                    || ! isset($this->ctx->outputState->extends->extendMap[$target])
                    || $this->ctx->outputState->extends->extendMap[$target] === []
                ) {
                    continue;
                }

                $seen[$target] = true;

                $targets[] = $target;
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

        if (count($superselectorCompounds) > count($selectorCompounds)) {
            return false;
        }

        $superselectorPseudo = $this->lastCompoundPseudoElement($superselectorCompounds);
        $selectorPseudo      = $this->lastCompoundPseudoElement($selectorCompounds);

        if ($superselectorPseudo !== $selectorPseudo) {
            return false;
        }

        $index  = 0;
        $length = count($selectorCompounds);

        foreach ($superselectorCompounds as $superselectorCompound) {
            $matched = false;

            while ($index < $length) {
                $selectorCompound = $selectorCompounds[$index];

                if ($this->compoundContainsUniversal($selectorCompound)) {
                    $matched = $this->universalSuperselectorMatch($selectorCompound, $superselectorCompound);
                } else {
                    $matched = $this->tokenizer->doesCompoundSatisfy($selectorCompound, $superselectorCompound);
                }

                if ($matched) {
                    $index++;

                    break;
                }

                $index++;
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function compoundContainsUniversal(string $compound): bool
    {
        foreach ($this->tokenizer->tokenizeCompound($compound) as $token) {
            if ($this->isUniversalTypeToken($token)) {
                return true;
            }
        }

        return false;
    }

    private function isUniversalTypeToken(string $token): bool
    {
        return $token === '*'
            || $token === '*|*'
            || str_ends_with($token, '|*');
    }

    private function universalSuperselectorMatch(string $selectorCompound, string $superselectorCompound): bool
    {
        $selectorTokens      = $this->tokenizer->tokenizeCompound($selectorCompound);
        $superselectorTokens = $this->tokenizer->tokenizeCompound($superselectorCompound);

        $selectorUniversalNamespace = null;

        foreach ($selectorTokens as $token) {
            if ($this->isUniversalTypeToken($token)) {
                $selectorUniversalNamespace = $this->typeTokenNamespace($token);
            }
        }

        foreach ($superselectorTokens as $token) {
            if ($this->isUniversalTypeToken($token)) {
                $namespace = $this->typeTokenNamespace($token);

                if (
                    $namespace !== null
                    && $namespace !== '*'
                    && ($selectorUniversalNamespace === null || $selectorUniversalNamespace !== $namespace)
                ) {
                    return false;
                }

                continue;
            }

            if (! in_array($token, $selectorTokens, true)) {
                return false;
            }
        }

        return true;
    }

    private function typeTokenNamespace(string $token): ?string
    {
        if ($token === '*') {
            return null;
        }

        $separatorIndex = strpos($token, '|');

        if ($separatorIndex === false) {
            return null;
        }

        return substr($token, 0, $separatorIndex);
    }

    /**
     * @param array<int, string> $compounds
     */
    private function lastCompoundPseudoElement(array $compounds): ?string
    {
        if ($compounds === []) {
            return null;
        }

        $lastCompound = $compounds[count($compounds) - 1];

        foreach ($this->tokenizer->tokenizeCompound($lastCompound) as $token) {
            if ($this->tokenizer->isPseudoElementToken($token)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * @param array<int, AstNode> $children
     */
    private function collectExtendsInDirectiveContext(array $children, string $contextSegment, Environment $env): void
    {
        $parentContext = $this->getCurrentExtendDirectiveContext($env);

        $context = $parentContext === '' ? $contextSegment : $parentContext . '|' . $contextSegment;

        $env->enterScope();
        $env->getCurrentScope()->setVariableLocal('__extend_directive_context', new StringNode($context));

        $this->collectChildren($children, $env);

        $env->exitScope();
    }

    /**
     * @param array<int, AstNode> $children
     */
    private function collectChildren(
        array $children,
        Environment $env,
        ?string $selector = null,
        string $currentContext = '',
        bool $applyDeclarations = false,
    ): void {
        foreach ($children as $child) {
            if ($child instanceof ExtendNode && $selector !== null) {
                $extendTargetSelector = $this->text->interpolateText($child->selector, $env);

                $state = $this->ctx->outputState->extends;
                $state->extendSequence++;

                $boxId = $state->ruleStack !== [] ? $state->ruleStack[count($state->ruleStack) - 1] : null;

                foreach ($this->extractSimpleExtendTargetSelectors($extendTargetSelector) as $extendTarget) {
                    if ($boxId !== null) {
                        $state->events[] = [
                            'type'     => 'extend',
                            'boxId'    => $boxId,
                            'target'   => $extendTarget,
                            'context'  => $currentContext,
                            'optional' => $child->optional,
                            'priority' => $state->extendSequence,
                        ];
                    }

                    foreach ($this->splitTopLevelSelectorList($selector) as $sourcePart) {
                        if ($sourcePart === '') {
                            continue;
                        }

                        $state->pendingExtends[] = [
                            'target'   => $extendTarget,
                            'source'   => $sourcePart,
                            'context'  => $currentContext,
                            'optional' => $child->optional,
                            'priority' => $state->extendSequence,
                        ];
                    }
                }

                continue;
            }

            if ($applyDeclarations && $this->variableDeclarationApplier->apply($child, $env)) {
                continue;
            }

            if ($child instanceof IfNode) {
                $this->collectIfExtends($child, $env, $selector, $currentContext);

                continue;
            }

            if ($child instanceof EachNode) {
                $this->collectEachExtends($child, $env, $selector, $currentContext);

                continue;
            }

            if ($child instanceof ForNode) {
                $this->collectForExtends($child, $env, $selector, $currentContext);

                continue;
            }

            if ($child instanceof WhileNode) {
                $this->collectWhileExtends($child, $env, $selector, $currentContext);

                continue;
            }

            $this->collectExtends($child, $env);
        }
    }

    /**
     * @return array<int, AstNode>
     */
    private function resolveIfBranch(IfNode $node, Environment $env): array
    {
        if ($this->conditionEvaluator->evaluate($node->condition, $env)) {
            return $node->body;
        }

        foreach ($node->elseIfBranches as $elseIfBranch) {
            if ($this->conditionEvaluator->evaluate($elseIfBranch->condition, $env)) {
                return $elseIfBranch->body;
            }
        }

        return $node->elseBody;
    }

    private function toLoopNumber(AstNode $node, Environment $env): float
    {
        $resolved = $this->valueEvaluator->evaluate($node, $env);

        if ($resolved instanceof NumberNode) {
            return (float) $resolved->value;
        }

        $formatted = $this->valueFormatter->format($resolved, $env);

        if (! is_numeric($formatted)) {
            throw new InvalidLoopBoundaryException($formatted);
        }

        return (float) $formatted;
    }

    private function assertIterationLimit(int $iterations, string $atRule): void
    {
        if ($iterations > 10000) {
            throw new MaxIterationsExceededException($atRule);
        }
    }

    private function getCurrentExtendDirectiveContext(Environment $env): string
    {
        $node = $env->getCurrentScope()->getStringVariable('__extend_directive_context');

        return $node !== null ? $node->value : '';
    }

    private function assertSimpleExtendTargetSelector(string $target): void
    {
        $target = trim($target);

        if ($this->tokenizer->hasUnsupportedTopLevelCombinator($target)) {
            throw new SassErrorException(
                'Complex selectors may not be extended. Use a simple selector target in @extend.',
            );
        }

        $compounds = $this->splitSelectorCompoundsByDescendant($target);

        if (count($compounds) !== 1) {
            throw new SassErrorException(
                'Complex selectors may not be extended. Use a simple selector target in @extend.',
            );
        }

        $tokens = $this->tokenizeSelectorCompound($compounds[0]);

        if (count($tokens) !== 1) {
            throw new SassErrorException(
                'Compound selectors may not be extended. Use separate @extend directives for each simple selector.',
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
        /** @var array<string, array<int, string>> $cache */
        static $cache = [];

        return $cache[$compound] ??= $this->tokenizer->tokenizeCompound($compound);
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
        $targetTokens = $this->tokenizeSelectorCompound($target);

        if ($targetTokens === []) {
            return null;
        }

        if ($this->tokenizer->hasUnsupportedTopLevelCombinator($extender)) {
            return null;
        }

        return $this->tokenizer->weaveExtendedSelector($part, $target, $extender);
    }

    private function replaceExtendTargetInSelectorPartFallback(string $part, string $target, string $extender): ?string
    {
        $partLength   = strlen($part);
        $targetLength = strlen($target);
        $offset       = 0;
        $replacement  = null;

        while (($position = strpos($part, $target, $offset)) !== false) {
            $start      = $position;
            $end        = $start + $targetLength;
            $beforeChar = $start > 0 ? $part[$start - 1] : '';
            $afterChar  = $end < $partLength ? $part[$end] : '';

            if ($this->isValidSelectorTokenBoundary($target, $beforeChar, $afterChar)) {
                $woven = $this->weaveFallbackExtendedSelector(
                    trim(substr($part, 0, $start)),
                    trim(substr($part, $end)),
                    $extender,
                );

                if ($woven !== null) {
                    $replacement = $woven;

                    break;
                }

                $replacement = substr($part, 0, $start) . $extender . substr($part, $end);

                break;
            }

            $offset = $start + 1;
        }

        return $replacement;
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
        foreach (array_keys($this->ctx->outputState->extends->selectorContexts[$target] ?? []) as $targetContext) {
            if ($targetContext !== $sourceContext) {
                throw new SassErrorException('You may not @extend selectors across media queries.');
            }
        }
    }

    private function assertExtendTargetExists(string $target, bool $optional = false): bool
    {
        if (isset($this->ctx->outputState->extends->selectorContexts[$target])) {
            return true;
        }

        if (! str_starts_with($target, '%')) {
            return true;
        }

        if (! NameNormalizer::isPrivate(substr($target, 1))) {
            return true;
        }

        if ($optional) {
            return false;
        }

        throw new SassErrorException('The target selector was not found.');
    }

    /**
     * @return array<int, string>
     */
    private function splitTopLevelSelectorList(string $selector): array
    {
        return $this->tokenizer->splitAtTopLevel($selector, [','], handleQuotes: true);
    }

    private function combineNestedSelectorWithParent(string $selector, string $parentSelector): string
    {
        $selectorParts = $this->splitTopLevelSelectorList($selector);
        $parentParts   = $this->splitTopLevelSelectorList($parentSelector);

        if ($selectorParts === [] || $parentParts === []) {
            return $selector;
        }

        $combined = [];

        foreach ($parentParts as $parentPart) {
            foreach ($selectorParts as $selectorPart) {
                $combined[] = $parentPart . ' ' . $selectorPart;
            }
        }

        return implode(', ', array_values(array_unique($combined)));
    }
}
