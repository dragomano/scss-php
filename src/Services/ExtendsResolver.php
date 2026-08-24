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

use function array_flip;
use function array_keys;
use function array_pop;
use function array_reverse;
use function array_slice;
use function array_unique;
use function array_unshift;
use function array_values;
use function count;
use function ctype_alnum;
use function explode;
use function implode;
use function in_array;
use function is_numeric;
use function max;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function usort;

/**
 * @phpstan-import-type Complex from SelectorTokenizer
 * @phpstan-type Extension array{extender: Complex, target: string, context: string, optional: bool, priority: int}
 * @phpstan-type Extender array{selector: Complex, original: bool, context: string}
 * @phpstan-type ExtensionMap array<string, array<string, Extension>>
 * @phpstan-type ExtensionStore array{
 *     selectors: array<string, array<int, true>>,
 *     extensions: ExtensionMap,
 *     byExtender: array<string, list<Extension>>,
 *     contexts: array<int, string>,
 *     sourceSpecificity: array<string, int>,
 *     originals: array<string, true>,
 *     boxes: array<int, array<int, Complex>>
 * }
 *
 * @psalm-import-type Complex from SelectorTokenizer
 * @psalm-type Extension=array{extender: Complex, target: string, context: string, optional: bool, priority: int}
 * @psalm-type Extender=array{selector: Complex, original: bool, context: string}
 * @psalm-type ExtensionMap=array<string, array<string, Extension>>
 * @psalm-type ExtensionStore=array{
 *     selectors: array<string, array<int, true>>,
 *     extensions: ExtensionMap,
 *     byExtender: array<string, list<Extension>>,
 *     contexts: array<int, string>,
 *     sourceSpecificity: array<string, int>,
 *     originals: array<string, true>,
 *     boxes: array<int, array<int, Complex>>
 * }
 */
final readonly class ExtendsResolver
{
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

        /** @var ExtensionStore $store */
        $store = [
            'selectors'         => [],
            'extensions'        => [],
            'byExtender'        => [],
            'contexts'          => [],
            'sourceSpecificity' => [],
            'originals'         => [],
            'boxes'             => [],
        ];

        /**
         * @var array<int, array{rawParts: array<int, string>, originals: array<int, string>, context: string}> $boxMeta
         */
        $boxMeta = [];

        foreach ($state->events as $event) {
            if ($event['type'] === 'rule') {
                /** @var array{type: 'rule', boxId: int, rawParts: array<int, string>, resolvedParts: array<int, string>, context: string} $event */
                $boxMeta[$event['boxId']] = [
                    'rawParts'  => $event['rawParts'],
                    'originals' => $event['resolvedParts'],
                    'context'   => $event['context'],
                ];

                $complexes = [];

                foreach ($event['resolvedParts'] as $part) {
                    foreach ($this->tokenizer->parseSelectorList($part) as $complex) {
                        $complexes[] = $complex;
                    }
                }

                $this->addSelectorToStore($store, $event['boxId'], $complexes, $event['context']);

                continue;
            }

            /** @var array{type: 'extend', boxId: int, target: string, context: string, optional: bool, priority: int} $event */
            if (! $this->assertExtendTargetExists($event['target'], $event['optional'])) {
                continue;
            }

            $this->addExtensionToStore(
                $store,
                $store['boxes'][$event['boxId']] ?? [],
                $event['target'],
                $event['optional'],
                $event['context'],
                $event['priority'],
            );
        }

        $state->boxes = [];

        foreach ($boxMeta as $boxId => $meta) {
            $selectors = [];

            foreach ($store['boxes'][$boxId] ?? [] as $complex) {
                $selectors[] = $this->complexKey($complex);
            }

            $state->boxes[$boxId] = [
                'rawParts'  => $meta['rawParts'],
                'selectors' => $selectors,
                'originals' => $meta['originals'],
                'context'   => $meta['context'],
            ];
        }

        $state->extendMap = [];

        foreach ($store['extensions'] as $target => $sources) {
            foreach ($sources as $extension) {
                $state->extendMap[$target][] = [
                    'source'   => $this->complexKey($extension['extender']),
                    'priority' => $extension['priority'],
                ];
            }
        }
    }

    /**
     * @param ExtensionStore $store
     * @param array<int, Complex> $complexes
     */
    private function addSelectorToStore(array &$store, int $boxId, array $complexes, string $context): void
    {
        $isInvisible = true;

        foreach ($complexes as $complex) {
            if (! $this->isComplexInvisible($complex)) {
                $isInvisible = false;

                break;
            }
        }

        if (! $isInvisible) {
            foreach ($complexes as $complex) {
                $store['originals'][$this->complexKey($complex)] = true;
            }
        }

        $extended = $store['extensions'] === []
            ? $complexes
            : ($this->extendComplexListInStore($store, $complexes, $store['extensions'], $context) ?? $complexes);

        $store['boxes'][$boxId]    = $extended;
        $store['contexts'][$boxId] = $context;

        $this->registerBoxSelectors($store, $extended, $boxId);
    }

    /**
     * @param ExtensionStore $store
     * @param array<int, Complex> $complexes
     */
    private function registerBoxSelectors(array &$store, array $complexes, int $boxId): void
    {
        foreach ($complexes as $complex) {
            foreach ($complex as $component) {
                foreach ($this->tokenizer->tokenizeCompound($component['sel']) as $simple) {
                    if ($simple === '') {
                        continue;
                    }

                    $store['selectors'][$simple][$boxId] = true;

                    $pseudo = $this->tokenizer->parsePseudoToken($simple);

                    if ($pseudo !== null && $pseudo['selector'] !== null) {
                        $innerComplexes = $this->tokenizer->parseSelectorList($pseudo['selector']);
                        $this->registerBoxSelectors($store, $innerComplexes, $boxId);
                    }
                }
            }
        }
    }

    /**
     * @param ExtensionStore $store
     * @param array<int, Complex> $extenderComplexes
     */
    private function addExtensionToStore(
        array &$store,
        array $extenderComplexes,
        string $target,
        bool $optional,
        string $context,
        int $priority,
    ): void {
        $selectorsForTarget = $store['selectors'][$target] ?? null;
        $hadExistingExtensions = isset($store['byExtender'][$target]);

        if (! isset($store['extensions'][$target])) {
            $store['extensions'][$target] = [];
        }

        $sources = & $store['extensions'][$target];

        /** @var array<string, Extension> $newExtensions */
        $newExtensions = [];

        foreach ($extenderComplexes as $extender) {
            if ($this->isUselessComplex($extender)) {
                continue;
            }

            $key = $this->complexKey($extender);

            if (isset($sources[$key])) {
                if (! $optional) {
                    $sources[$key]['optional'] = false;
                }

                continue;
            }

            $extension = [
                'extender' => $extender,
                'target'   => $target,
                'context'  => $context,
                'optional' => $optional,
                'priority' => $priority,
            ];

            $sources[$key] = $extension;

            foreach ($this->simpleSelectorsRecursive($extender) as $simple) {
                $store['byExtender'][$simple][] = $extension;

                if (! isset($store['sourceSpecificity'][$simple])) {
                    $store['sourceSpecificity'][$simple] = $this->structuralSpecificity($extender);
                }
            }

            if ($selectorsForTarget !== null || $hadExistingExtensions) {
                $newExtensions[$key] = $extension;
            }
        }

        $existingExtensions = $store['byExtender'][$target] ?? [];

        if ($newExtensions === []) {
            return;
        }

        /** @var ExtensionMap $newExtensionsByTarget */
        $newExtensionsByTarget = [$target => $newExtensions];

        if ($existingExtensions !== []) {
            foreach (
                $this->extendExistingExtensionsInStore($store, $existingExtensions, $newExtensionsByTarget) as $additionalTarget => $map
            ) {
                foreach ($map as $key => $extension) {
                    $newExtensionsByTarget[$additionalTarget][$key] = $extension;
                }
            }
        }

        if ($selectorsForTarget !== null) {
            $this->extendExistingSelectorsInStore($store, array_keys($selectorsForTarget), $newExtensionsByTarget);
        }
    }

    /**
     * @param ExtensionStore $store
     * @param list<Extension> $extensions
     * @param ExtensionMap $newExtensions
     * @return ExtensionMap
     */
    private function extendExistingExtensionsInStore(array &$store, array $extensions, array $newExtensions): array
    {
        /** @var ExtensionMap $additional */
        $additional = [];

        foreach ($extensions as $extension) {
            $target = $extension['target'];

            if (! isset($store['extensions'][$target])) {
                continue;
            }

            $extendedExtender = $this->extendSingleComplex(
                $store,
                $extension['extender'],
                $newExtensions,
                $extension['context'],
            );

            if ($extendedExtender === null) {
                continue;
            }

            $start = 0;

            if ($this->complexKey($extendedExtender[0]) === $this->complexKey($extension['extender'])) {
                $start = 1;
            }

            $count = count($extendedExtender);

            for ($i = $start; $i < $count; $i++) {
                $variant    = $extendedExtender[$i];
                $variantKey = $this->complexKey($variant);

                if ($variantKey === $this->complexKey($extension['extender'])) {
                    continue;
                }

                $withExtender           = $extension;
                $withExtender['extender'] = $variant;

                if (isset($store['extensions'][$target][$variantKey])) {
                    $store['extensions'][$target][$variantKey]['optional']
                        = $store['extensions'][$target][$variantKey]['optional'] && $withExtender['optional'];

                    continue;
                }

                $store['extensions'][$target][$variantKey] = $withExtender;

                foreach ($variant as $component) {
                    foreach ($this->tokenizer->tokenizeCompound($component['sel']) as $simple) {
                        if ($simple === '') {
                            continue;
                        }

                        $store['byExtender'][$simple][] = $withExtender;
                    }
                }

                if (isset($newExtensions[$target])) {
                    $additional[$target][$variantKey] = $withExtender;
                }
            }
        }

        return $additional;
    }

    /**
     * @param ExtensionStore $store
     * @param list<int> $boxIds
     * @param ExtensionMap $newExtensions
     */
    private function extendExistingSelectorsInStore(array &$store, array $boxIds, array $newExtensions): void
    {
        foreach ($boxIds as $boxId) {
            $oldValue = $store['boxes'][$boxId] ?? [];
            $context  = $store['contexts'][$boxId] ?? '';

            $newValue = $this->extendComplexListInStore($store, $oldValue, $newExtensions, $context);

            if ($newValue === null) {
                continue;
            }

            $store['boxes'][$boxId] = $newValue;

            $this->registerBoxSelectors($store, $newValue, $boxId);
        }
    }

    /**
     * @param ExtensionStore $store
     * @param array<int, Complex> $list
     * @param ExtensionMap $extensionsMap
     * @return array<int, Complex>|null
     */
    private function extendComplexListInStore(array &$store, array $list, array $extensionsMap, string $context): ?array
    {
        $extended  = null;
        $unchanged = [];

        foreach ($list as $complex) {
            $result = $this->extendSingleComplex($store, $complex, $extensionsMap, $context);

            if ($result === null) {
                if ($extended !== null) {
                    $extended[] = $complex;
                } else {
                    $unchanged[] = $complex;
                }

                continue;
            }

            $extended ??= $unchanged;

            foreach ($result as $item) {
                $extended[] = $item;
            }
        }

        if ($extended === null) {
            return null;
        }

        return $this->trimExtendedComplexes(
            $store,
            $extended,
            fn(array $complex): bool => isset($store['originals'][$this->complexKey($complex)]),
        );
    }

    /**
     * @param ExtensionStore $store
     * @param Complex $complex
     * @param ExtensionMap $extensionsMap
     * @return array<int, Complex>|null
     */
    private function extendSingleComplex(array &$store, array $complex, array $extensionsMap, string $context): ?array
    {
        $lead = $complex[0]['lead'] ?? '';

        if ($this->countCombinatorWords($lead) > 1) {
            return null;
        }

        $isOriginal = isset($store['originals'][$this->complexKey($complex)]);

        /** @var list<list<Complex>>|null $extendedNotExpanded */
        $extendedNotExpanded = null;

        $componentCount = count($complex);

        for ($i = 0; $i < $componentCount; $i++) {
            $component = $complex[$i];
            $extended  = $this->extendCompoundComponent($store, $component, $extensionsMap, $context, $isOriginal);

            if ($extended === null) {
                if ($extendedNotExpanded !== null) {
                    $extendedNotExpanded[] = [[[
                        'sel'  => $component['sel'],
                        'comb' => $component['comb'],
                    ]]];
                }
            } elseif ($extendedNotExpanded !== null) {
                $extendedNotExpanded[] = $extended;
            } elseif ($i !== 0) {
                $extendedNotExpanded = [[array_slice($complex, 0, $i)], $extended];
            } elseif ($lead === '') {
                $extendedNotExpanded = [$extended];
            } else {
                $filtered = [];

                foreach ($extended as $newComplex) {
                    if (! isset($newComplex[0])) {
                        continue;
                    }

                    $newLead = $newComplex[0]['lead'] ?? '';

                    if ($newLead === '' || $newLead === $lead) {
                        $newComplex[0]['lead'] = $lead;
                        $filtered[]            = $newComplex;
                    }
                }

                $extendedNotExpanded = [$filtered];
            }
        }

        if ($extendedNotExpanded === null) {
            return null;
        }

        $result = [];
        $first  = true;

        foreach ($this->tokenizer->paths($extendedNotExpanded) as $path) {
            foreach ($this->tokenizer->weave($path) as $output) {
                if ($first && $isOriginal) {
                    $store['originals'][$this->complexKey($output)] = true;
                }

                $first = false;

                $result[] = $output;
            }
        }

        return $result;
    }

    /**
     * @param ExtensionStore $store
     * @param array{sel: string, comb: string, lead?: string} $component
     * @param ExtensionMap $extensionsMap
     * @return array<int, Complex>|null
     */
    private function extendCompoundComponent(
        array &$store,
        array $component,
        array $extensionsMap,
        string $context,
        bool $inOriginal,
    ): ?array {
        /** @var list<string>|null $targetsUsed */
        $targetsUsed = null;

        $simples = $this->tokenizer->tokenizeCompound($component['sel']);

        /** @var list<list<Extender>>|null $options */
        $options = null;

        foreach ($simples as $index => $simple) {
            if ($simple === '') {
                continue;
            }

            $extended = $this->extendSimpleSelector($store, $simple, $extensionsMap, $context, $targetsUsed);

            if ($extended === null) {
                if ($options !== null) {
                    $options[] = [$this->extenderForSimple($simple)];
                }
            } else {
                if ($options === null) {
                    $options = [];

                    if ($index !== 0) {
                        $options[] = [$this->extenderForCompound(array_slice($simples, 0, $index))];
                    }
                }

                foreach ($extended as $choiceGroup) {
                    $options[] = $choiceGroup;
                }
            }
        }

        if ($options === null) {
            return null;
        }

        if ($targetsUsed !== null && count($targetsUsed) !== count($extensionsMap)) {
            return null;
        }

        if (count($options) === 1) {
            /** @var list<Complex>|null $result */
            $result = null;

            foreach ($options[0] as $extender) {
                $this->assertExtensionMediaContext($extender, $context);

                $candidate = $this->withAdditionalCombinators($extender['selector'], $component['comb']);

                if ($this->isUselessComplex($candidate)) {
                    continue;
                }

                $result[] = $candidate;
            }

            return $result;
        }

        $paths     = $this->tokenizer->paths($options);
        $firstPath = $paths[0];

        $originalTokens = [];

        foreach ($firstPath as $extender) {
            $lastComponent = $extender['selector'][count($extender['selector']) - 1];

            foreach ($this->tokenizer->tokenizeCompound($lastComponent['sel']) as $token) {
                if ($token !== '') {
                    $originalTokens[] = $token;
                }
            }
        }

        /** @var list<Complex> $result */
        $result = [[[ 'sel' => implode('', $originalTokens), 'comb' => $component['comb'] ]]];

        foreach (array_slice($paths, 1) as $path) {
            $unified = $this->unifyExtenderPath($path, $context);

            if ($unified === null) {
                continue;
            }

            foreach ($unified as $unifiedComplex) {
                $withCombinators = $this->withAdditionalCombinators($unifiedComplex, $component['comb']);

                if (! $this->isUselessComplex($withCombinators)) {
                    $result[] = $withCombinators;
                }
            }
        }

        $originalKey = $this->complexKey($result[0]);

        return $this->trimExtendedComplexes(
            $store,
            $result,
            fn(array $complex): bool => $inOriginal && $this->complexKey($complex) === $originalKey,
        );
    }

    /**
     * @param list<Extender> $path
     * @return array<int, Complex>|null
     */
    private function unifyExtenderPath(array $path, string $context): ?array
    {
        /** @var list<Complex> $toUnify */
        $toUnify        = [];
        $originalTokens = null;

        foreach ($path as $extender) {
            if ($extender['original']) {
                $lastComponent = $extender['selector'][count($extender['selector']) - 1];

                foreach ($this->tokenizer->tokenizeCompound($lastComponent['sel']) as $token) {
                    if ($token !== '') {
                        $originalTokens[] = $token;
                    }
                }
            } else {
                if ($this->isUselessComplex($extender['selector'])) {
                    return null;
                }

                $toUnify[] = $extender['selector'];
            }
        }

        if ($originalTokens !== null) {
            array_unshift($toUnify, [['sel' => implode('', $originalTokens), 'comb' => '']]);
        }

        $complexes = $this->unifyComplexList($toUnify);

        if ($complexes === null) {
            return null;
        }

        foreach ($path as $extender) {
            $this->assertExtensionMediaContext($extender, $context);
        }

        return $complexes;
    }

    /**
     * @param list<Complex> $complexes
     * @return list<Complex>|null
     */
    private function unifyComplexList(array $complexes): ?array
    {
        if (count($complexes) === 1) {
            return $complexes;
        }

        $lead     = '';
        $trailing = '';
        $base     = null;

        foreach ($complexes as $complex) {
            if ($this->isUselessComplex($complex)) {
                return null;
            }

            $complexLead = $complex[0]['lead'] ?? '';

            if (count($complex) === 1 && $complexLead !== '') {
                if ($lead === '') {
                    $lead = $complexLead;
                } elseif ($lead !== $complexLead) {
                    return null;
                }
            }

            $last            = $complex[count($complex) - 1];
            $lastCombination = trim($last['comb']);

            if ($lastCombination !== '' && $this->countCombinatorWords($lastCombination) === 1) {
                if ($trailing === '') {
                    $trailing = $lastCombination;
                } elseif ($trailing !== $lastCombination) {
                    return null;
                }
            }

            $candidate = $base === null
                ? $last['sel']
                : $this->tokenizer->unifyCompoundsStrict($base, $last['sel']);

            if ($candidate === null) {
                return null;
            }

            $base = $candidate;
        }

        if ($base === null) {
            return null;
        }

        $baseComplex = [['sel' => $base, 'comb' => $trailing]];

        if ($lead !== '') {
            $baseComplex[0]['lead'] = $lead;
        }

        /** @var list<Complex> $prefixes */
        $prefixes = [];

        foreach ($complexes as $complex) {
            if (count($complex) > 1) {
                $prefixes[] = array_slice($complex, 0, -1);
            }
        }

        if ($prefixes === []) {
            return [$baseComplex];
        }

        $lastPrefix   = array_pop($prefixes);
        $lastPrefix[] = $baseComplex[0];

        return $this->tokenizer->weave([...$prefixes, $lastPrefix]);
    }

    /**
     * @param ExtensionStore $store
     * @param string $simple
     * @param ExtensionMap $extensionsMap
     * @param list<string>|null $targetsUsed
     * @return list<list<Extender>>|null
     */
    private function extendSimpleSelector(
        array &$store,
        string $simple,
        array $extensionsMap,
        string $context,
        ?array &$targetsUsed,
    ): ?array {
        $pseudo = $this->tokenizer->parsePseudoToken($simple);

        if ($pseudo !== null && $pseudo['selector'] !== null) {
            $extendedPseudos = $this->extendPseudoSelector($store, $simple, $extensionsMap, $context);

            if ($extendedPseudos !== null) {
                $choices = [];

                foreach ($extendedPseudos as $pseudoToken) {
                    $choices[] = $this->withoutPseudoChoices($pseudoToken, $extensionsMap, $targetsUsed)
                        ?? [$this->extenderForSimple($pseudoToken)];
                }

                return $choices;
            }
        }

        $direct = $this->withoutPseudoChoices($simple, $extensionsMap, $targetsUsed);

        return $direct === null ? null : [$direct];
    }

    /**
     * @param string $simple
     * @param array<string, array<string, Extension>> $extensionsMap
     * @param list<string>|null $targetsUsed
     * @return list<Extender>|null
     */
    private function withoutPseudoChoices(string $simple, array $extensionsMap, ?array &$targetsUsed): ?array
    {
        $extensionsForSimple = $extensionsMap[$simple] ?? null;

        if ($extensionsForSimple === null) {
            return null;
        }

        if ($targetsUsed !== null) {
            $targetsUsed[] = $simple;
        }

        $choices = [$this->extenderForSimple($simple)];

        foreach ($extensionsForSimple as $extension) {
            $choices[] = [
                'selector' => $extension['extender'],
                'original' => false,
                'context'  => $extension['context'],
            ];
        }

        return $choices;
    }

    /**
     * @param ExtensionStore $store
     * @param ExtensionMap $extensionsMap
     * @return list<string>|null
     */
    private function extendPseudoSelector(array &$store, string $token, array $extensionsMap, string $context): ?array
    {
        $pseudo = $this->tokenizer->parsePseudoToken($token);

        if ($pseudo === null || $pseudo['selector'] === null) {
            return null;
        }

        $inner = $this->tokenizer->parseSelectorList($pseudo['selector']);

        $extendedInner = $this->extendComplexListInStore($store, $inner, $extensionsMap, $context);

        if ($extendedInner === null) {
            return null;
        }

        $name           = $pseudo['name'];
        $normalizedName  = $this->normalizePseudoName($name);

        $hadComplexOriginally = false;

        foreach ($inner as $complex) {
            if (count($complex) > 1) {
                $hadComplexOriginally = true;

                break;
            }
        }

        $complexes = $extendedInner;

        if ($normalizedName === 'not' && ! $hadComplexOriginally) {
            $hasSingle = false;

            foreach ($extendedInner as $complex) {
                if (count($complex) === 1) {
                    $hasSingle = true;

                    break;
                }
            }

            if ($hasSingle) {
                $kept = [];

                foreach ($extendedInner as $complex) {
                    if (count($complex) <= 1) {
                        $kept[] = $complex;
                    }
                }

                $complexes = $kept;
            }
        }

        /** @var list<Complex> $expanded */
        $expanded = [];

        foreach ($complexes as $complex) {
            $innerSimple = $this->singleSimpleOfComplex($complex);

            if ($innerSimple === null) {
                $expanded[] = $complex;

                continue;
            }

            $innerPseudo = $this->tokenizer->parsePseudoToken($innerSimple);

            if ($innerPseudo === null || $innerPseudo['selector'] === null) {
                $expanded[] = $complex;

                continue;
            }

            $innerNormalizedName = $this->normalizePseudoName($innerPseudo['name']);

            $matched = match ($normalizedName) {
                'not'            => in_array($innerNormalizedName, ['is', 'matches', 'where'], true),
                'is',
                'matches',
                'where',
                'any',
                'current',
                'nth-child',
                'nth-last-child' => $innerPseudo['name'] === $name && $innerPseudo['argument'] === $pseudo['argument'],
                'has',
                'host',
                'host-context',
                'slotted'        => true,
                default          => false,
            };

            if (! $matched) {
                continue;
            }

            if (in_array($normalizedName, ['has', 'host', 'host-context', 'slotted'], true)) {
                $expanded[] = $complex;

                continue;
            }

            foreach ($this->tokenizer->parseSelectorList($innerPseudo['selector']) as $innerComplex) {
                $expanded[] = $innerComplex;
            }
        }

        if ($normalizedName === 'not' && count($inner) === 1) {
            $tokens = [];

            foreach ($expanded as $complex) {
                $tokens[] = ':' . $name . '(' . $this->complexKey($complex) . ')';
            }

            return $tokens === [] ? null : $tokens;
        }

        $joined = [];

        foreach ($expanded as $complex) {
            $joined[] = $this->complexKey($complex);
        }

        $replaced = implode(', ', $joined);
        $position = strrpos($token, $pseudo['selector']);

        if ($position === false || $pseudo['selector'] === '') {
            return [':' . $name . '(' . $replaced . ')'];
        }

        return [
            substr($token, 0, $position)
            . $replaced
            . substr($token, $position + strlen($pseudo['selector'])),
        ];
    }

    /**
     * @param ExtensionStore $store
     * @param list<Complex> $complexes
     * @param callable(Complex): bool $isOriginal
     * @return list<Complex>
     */
    private function trimExtendedComplexes(array $store, array $complexes, callable $isOriginal): array
    {
        if (count($complexes) > 100) {
            return $complexes;
        }

        /** @var list<Complex> $result */
        $result       = [];
        $numOriginals = 0;

        $reversed   = array_reverse($complexes);
        $totalCount = count($complexes);

        foreach ($reversed as $reverseIndex => $complex) {
            $i = $totalCount - 1 - $reverseIndex;

            if ($isOriginal($complex)) {
                $duplicateIndex = -1;
                $scanIndex      = 0;

                foreach (array_slice($result, 0, $numOriginals) as $originalCandidate) {
                    if ($this->complexKey($originalCandidate) === $this->complexKey($complex)) {
                        $duplicateIndex = $scanIndex;

                        break;
                    }

                    $scanIndex++;
                }

                if ($duplicateIndex >= 0) {
                    $result = $this->rotateSlice($result, 0, $duplicateIndex + 1);

                    continue;
                }

                $numOriginals++;
                array_unshift($result, $complex);

                continue;
            }

            $maxSpecificity = 0;

            foreach ($complex as $component) {
                $maxSpecificity = max(
                    $maxSpecificity,
                    $this->sourceSpecificityForCompound($store, $component['sel']),
                );
            }

            $redundant = false;

            foreach ($result as $candidate) {
                if (
                    $this->structuralSpecificity($candidate) >= $maxSpecificity
                    && $this->tokenizer->complexesAreSuperselector($candidate, $complex)
                ) {
                    $redundant = true;

                    break;
                }
            }

            if (! $redundant) {
                foreach (array_slice($complexes, 0, $i) as $earlierCandidate) {
                    if (
                        $this->structuralSpecificity($earlierCandidate) >= $maxSpecificity
                        && $this->tokenizer->complexesAreSuperselector($earlierCandidate, $complex)
                    ) {
                        $redundant = true;

                        break;
                    }
                }
            }

            if (! $redundant) {
                array_unshift($result, $complex);
            }
        }

        return $result;
    }

    /**
     * @param list<Complex> $list
     * @return list<Complex>
     */
    private function rotateSlice(array $list, int $start, int $end): array
    {
        $moved = $list[$end - 1];

        return [
            ...array_slice($list, 0, $start),
            $moved,
            ...array_slice($list, $start, $end - $start - 1),
            ...array_slice($list, $end),
        ];
    }

    /**
     * @param Complex $complex
     */
    private function complexKey(array $complex): string
    {
        return $this->tokenizer->complexComponentsToString($complex);
    }

    /**
     * @param Complex $complex
     */
    private function isUselessComplex(array $complex): bool
    {
        if ($this->countCombinatorWords($complex[0]['lead'] ?? '') > 1) {
            return true;
        }

        foreach ($complex as $component) {
            if ($this->countCombinatorWords($component['comb']) > 1) {
                return true;
            }
        }

        return false;
    }

    private function countCombinatorWords(string $combinators): int
    {
        $count = 0;

        foreach (explode(' ', $combinators) as $word) {
            if ($word !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param Complex $complex
     */
    private function structuralSpecificity(array $complex): int
    {
        $specificity = ($complex[0]['lead'] ?? '') !== '' ? 1 : 0;

        foreach ($complex as $component) {
            foreach ($this->tokenizer->tokenizeCompound($component['sel']) as $token) {
                if ($token !== '') {
                    $specificity += $this->simpleStructuralSpecificity($token);
                }
            }
        }

        return $specificity;
    }

    private function simpleStructuralSpecificity(string $token): int
    {
        $first = $token[0];

        if ($first === '#') {
            return 1000000;
        }

        if ($first === '.' || $first === '%' || $first === '[') {
            return 1000;
        }

        if ($first === '*') {
            return 0;
        }

        if ($first === ':') {
            return $this->pseudoStructuralSpecificity($token);
        }

        if (str_ends_with($token, '|*')) {
            return 0;
        }

        return 1;
    }

    private function pseudoStructuralSpecificity(string $token): int
    {
        $parsed = $this->tokenizer->parsePseudoToken($token);

        if ($parsed === null || $parsed['isElement']) {
            return 1;
        }

        if ($parsed['selector'] === null) {
            return 1000;
        }

        $normalizedName = $this->normalizePseudoName($parsed['name']);

        return match ($normalizedName) {
            'where'          => 0,
            'is',
            'not',
            'has',
            'matches'        => $this->maxInnerStructuralSpecificity($parsed['selector']),
            'nth-child',
            'nth-last-child' => 1000 + $this->maxInnerStructuralSpecificity($parsed['selector']),
            default          => 1000,
        };
    }

    private function maxInnerStructuralSpecificity(string $selectorText): int
    {
        $max = 0;

        foreach ($this->tokenizer->parseSelectorList($selectorText) as $complex) {
            $max = max($max, $this->structuralSpecificity($complex));
        }

        return $max;
    }

    private function normalizePseudoName(string $name): string
    {
        $lowered = strtolower($name);

        if ($lowered !== '' && $lowered[0] === '-') {
            $secondDash = strpos($lowered, '-', 1);

            if ($secondDash !== false && $secondDash > 1) {
                $lowered = substr($lowered, $secondDash + 1);
            }
        }

        return $lowered;
    }

    /**
     * @param ExtensionStore $store
     */
    private function sourceSpecificityForCompound(array $store, string $compound): int
    {
        $specificity = 0;

        foreach ($this->tokenizer->tokenizeCompound($compound) as $token) {
            if ($token === '') {
                continue;
            }

            $specificity = max($specificity, $store['sourceSpecificity'][$token] ?? 0);
        }

        return $specificity;
    }

    /**
     * @param Complex $complex
     * @return Complex
     */
    private function withAdditionalCombinators(array $complex, string $combinators): array
    {
        if ($complex === [] || $combinators === '') {
            return $complex;
        }

        $out       = $complex;
        $lastIndex = count($out) - 1;

        $out[$lastIndex]['comb'] = $out[$lastIndex]['comb'] === ''
            ? $combinators
            : $out[$lastIndex]['comb'] . ' ' . $combinators;

        return $out;
    }

    /**
     * @param Complex $complex
     */
    private function isComplexInvisible(array $complex): bool
    {
        foreach ($this->simpleSelectorsRecursive($complex) as $simple) {
            if ($simple !== '' && $simple[0] === '%') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Complex $complex
     * @return list<string>
     */
    private function simpleSelectorsRecursive(array $complex): array
    {
        $simples = [];

        foreach ($complex as $component) {
            foreach ($this->tokenizer->tokenizeCompound($component['sel']) as $simple) {
                if ($simple === '') {
                    continue;
                }

                $simples[] = $simple;

                $pseudo = $this->tokenizer->parsePseudoToken($simple);

                if ($pseudo !== null && $pseudo['selector'] !== null) {
                    foreach ($this->tokenizer->parseSelectorList($pseudo['selector']) as $innerComplex) {
                        foreach ($this->simpleSelectorsRecursive($innerComplex) as $innerSimple) {
                            $simples[] = $innerSimple;
                        }
                    }
                }
            }
        }

        return $simples;
    }

    /**
     * @param Complex $complex
     */
    private function singleSimpleOfComplex(array $complex): ?string
    {
        if (count($complex) !== 1 || ($complex[0]['lead'] ?? '') !== '') {
            return null;
        }

        $tokens = $this->tokenizer->tokenizeCompound($complex[0]['sel']);

        return count($tokens) === 1 && $tokens[0] !== '' ? $tokens[0] : null;
    }

    /**
     * @return array{selector: Complex, original: bool, context: string}
     */
    private function extenderForSimple(string $simple): array
    {
        return [
            'selector' => [['sel' => $simple, 'comb' => '']],
            'original' => true,
            'context'  => '',
        ];
    }

    /**
     * @param list<string> $tokens
     * @return array{selector: Complex, original: bool, context: string}
     */
    private function extenderForCompound(array $tokens): array
    {
        return [
            'selector' => [['sel' => implode('', $tokens), 'comb' => '']],
            'original' => true,
            'context'  => '',
        ];
    }

    /**
     * @param array{selector: Complex, original: bool, context: string} $extender
     */
    private function assertExtensionMediaContext(array $extender, string $context): void
    {
        $extensionContext = $extender['context'];

        if ($extensionContext === '' || $extensionContext === $context) {
            return;
        }

        throw new SassErrorException('You may not @extend selectors across media queries.');
    }

    public function registerExtend(string $target, string $source, int $priority = 0): void
    {
        $target = $this->tokenizer->canonicalizeSelectorEscapes(
            $this->tokenizer->normalizeSelectorAttributes(trim($target)),
        );
        $source = $this->tokenizer->canonicalizeSelectorEscapes(
            $this->tokenizer->normalizeSelectorAttributes(trim($source)),
        );

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
        $target = $this->tokenizer->canonicalizeSelectorEscapes(
            $this->tokenizer->normalizeSelectorAttributes(trim($target)),
        );

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
        $lineBreaks = array_merge(
            $this->ctx->outputState->extends->partLineBreaks,
            $this->collectLineBreakMap($selector),
        );

        $selector = $this->tokenizer->canonicalizeSelectorEscapes($selector);

        if (! $this->hasCollectedExtends() && ! str_contains($selector, '%')) {
            return $selector;
        }

        $state = $this->ctx->outputState->extends;

        if ($state->boxes !== []) {
            $parts = $this->splitTopLevelSelectorList($selector);

            foreach ($state->boxes as $box) {
                if ($box['originals'] === $parts) {
                    return $this->joinSelectorListWithLineBreaks(
                        $this->renderBoxSelectors($box['selectors']),
                        $lineBreaks,
                    );
                }
            }

            foreach ($state->boxes as $box) {
                if (in_array($selector, $box['selectors'], true)) {
                    return $selector;
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

        return $this->joinSelectorListWithLineBreaks(
            $this->trimRedundantSelectors($unique, $uniqueExact),
            $lineBreaks,
        );
    }

    /**
     * @return array<string, bool>
     */
    private function collectLineBreakMap(string $rawSelector): array
    {
        $map = [];

        foreach ($this->tokenizer->splitAtTopLevel($rawSelector, [','], handleQuotes: true, trim: false) as $part) {
            if (trim($part) === '') {
                continue;
            }

            $leadingLength = strlen($part) - strlen(ltrim($part));

            $canonical = $this->tokenizer->canonicalizeSelectorEscapes(trim($part));

            if (! array_key_exists($canonical, $map)) {
                $map[$canonical] = str_contains(substr($part, 0, $leadingLength), "\n");
            }
        }

        return $map;
    }

    /**
     * @param array<int, string> $parts
     * @param array<string, bool> $lineBreaks
     */
    private function joinSelectorListWithLineBreaks(array $parts, array $lineBreaks): string
    {
        if ($parts === []) {
            return '';
        }

        $result        = $parts[0];
        $previousBreak = $lineBreaks[$parts[0]] ?? false;

        foreach (array_slice($parts, 1) as $part) {
            $currentBreak = $lineBreaks[$part] ?? false;

            $result .= ($previousBreak || $currentBreak ? ",\n" : ', ') . $part;

            $previousBreak = $currentBreak;
        }

        return $result;
    }

    /**
     * @param array<int, string> $selectors
     * @return array<int, string>
     */
    private function renderBoxSelectors(array $selectors): array
    {
        $filtered = [];

        foreach ($selectors as $selector) {
            if (str_contains($selector, '%')) {
                continue;
            }

            $filtered[] = $selector;
        }

        return array_values(array_unique($filtered));
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
     * @return array<int, array{target: string, source: string, priority: int}>
     */
    private function orderedExtends(): array
    {
        /** @var array<int, array{target: string, source: string, priority: int}> $extends */
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

        $rawSelector = $this->tokenizer->canonicalizeSelectorEscapes($rawSelector);
        $selector    = $this->tokenizer->canonicalizeSelectorEscapes($selector);

        $currentContext = $this->getCurrentExtendDirectiveContext($env);
        $outputState    = $this->ctx->outputState;

        foreach ($this->collectLineBreakMap($selector) as $part => $hasBreak) {
            $outputState->extends->partLineBreaks += [$part => $hasBreak];
        }

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
