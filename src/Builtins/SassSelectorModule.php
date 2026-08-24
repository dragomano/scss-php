<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Exceptions\UnknownSassFunctionException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Utils\SelectorTokenizer;

use function array_map;
use function array_search;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function ksort;
use function method_exists;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function trim;

final class SassSelectorModule extends AbstractModule
{
    private const FUNCTIONS = [
        'append',
        'extend',
        'is-superselector',
        'nest',
        'parse',
        'replace',
        'simple-selectors',
        'unify',
    ];

    private const GLOBAL_FUNCTIONS = [
        'is-superselector',
        'simple-selectors',
    ];

    private const GLOBAL_ALIASES = [
        'selector-append'  => 'append',
        'selector-extend'  => 'extend',
        'selector-nest'    => 'nest',
        'selector-parse'   => 'parse',
        'selector-replace' => 'replace',
        'selector-unify'   => 'unify',
    ];

    private const PARAMETER_NAMES = [
        'extend'           => ['selector', 'extendee', 'extender'],
        'is-superselector' => ['super', 'sub'],
        'parse'            => ['selector'],
        'replace'          => ['selector', 'original', 'replacement'],
        'simple-selectors' => ['selector'],
        'unify'            => ['selector1', 'selector2'],
    ];

    public function __construct(
        private readonly SelectorTokenizer $tokenizer = new SelectorTokenizer(),
    ) {}

    public function getName(): string
    {
        return 'selector';
    }

    public function getFunctions(): array
    {
        return self::FUNCTIONS;
    }

    public function getGlobalAliases(): array
    {
        return $this->globalAliases(self::GLOBAL_FUNCTIONS, self::GLOBAL_ALIASES);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function call(string $name, array $positional, array $named, ?BuiltinCallContext $context = null): AstNode
    {
        $previousDisplayName = $this->beginBuiltinCall($name, $context);

        try {
            if ($named !== []) {
                $positional = $this->mergeNamedArguments($name, $positional, $named);
            }

            return match ($name) {
                'append'           => $this->append($positional, $context),
                'extend'           => $this->extend($positional, $context),
                'is-superselector' => $this->isSuperselector($positional, $context),
                'nest'             => $this->nest($positional, $context),
                'parse'            => $this->parse($positional, $context),
                'replace'          => $this->replace($positional, $context),
                'simple-selectors' => $this->simpleSelectors($positional, $context),
                'unify'            => $this->unify($positional, $context),
                default            => throw new UnknownSassFunctionException('selector', $name),
            };
        } finally {
            $this->endBuiltinCall($previousDisplayName);
        }
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     * @return array<int, AstNode>
     */
    private function mergeNamedArguments(string $name, array $positional, array $named): array
    {
        $names = self::PARAMETER_NAMES[$name] ?? [];

        foreach ($named as $key => $value) {
            $index = array_search($key, $names, true);

            if ($index === false || isset($positional[$index])) {
                continue;
            }

            $positional[$index] = $value;
        }

        ksort($positional);

        return $positional;
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function append(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('selector.append'),
                1,
                true,
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'append', $positional);

        $parent = $this->assertSelector($positional[0], 'selector.append');

        for ($i = 1; $i < count($positional); $i++) {
            $child  = $this->assertSelector($positional[$i], 'selector.append');
            $parent = $this->appendSelectors($parent, $child);
        }

        return $this->selectorListNode($parent);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function extend(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 3) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('selector.extend'),
                3,
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'extend', $positional);

        $selector = $this->selectorTextArgument($positional[0], 'selector.extend');
        $target   = $this->selectorTextArgument($positional[1], 'selector.extend');
        $source   = $this->selectorTextArgument($positional[2], 'selector.extend');

        $this->assertNoParentSelector($selector);
        $this->assertNoParentSelector($target);
        $this->assertNoParentSelector($source);

        foreach ($this->tokenizer->splitAtTopLevel($target, [','], true) as $targetPart) {
            if ($this->hasUnsupportedTopLevelCombinator($targetPart)) {
                throw new SassErrorException(
                    'Complex selectors may not be extended. Use a simple selector target in @extend.',
                );
            }

            $compounds = $this->splitSelectorCompounds($targetPart);

            if (count($compounds) > 1) {
                throw new SassErrorException(
                    'Complex selectors may not be extended. Use a simple selector target in @extend.',
                );
            }
        }

        $result = [];
        $parts  = $this->tokenizer->splitAtTopLevel($selector, [','], true);

        foreach ($parts as $part) {
            $result[] = $part;

            foreach ($this->replaceExtendTargetInSelectorPart($part, $target, $source) as $extendedPart) {
                $result[] = $extendedPart;
            }
        }

        return $this->selectorListNode(
            $this->tokenizer->parseSelectorList(implode(', ', array_values(array_unique($result)))),
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function isSuperselector(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 2) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('selector.is-superselector'),
                2,
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'is-superselector', $positional);

        $super = $this->assertSelector($positional[0], 'selector.is-superselector');
        $sub   = $this->assertSelector($positional[1], 'selector.is-superselector');

        return $this->boolNode($this->tokenizer->listsAreSuperselectors($super, $sub));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function nest(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('selector.nest'),
                1,
                true,
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'nest', $positional);

        $parent = $this->assertSelector($positional[0], 'selector.nest', true);

        if (count($positional) === 1) {
            return $this->selectorListNode($parent);
        }

        for ($i = 1; $i < count($positional); $i++) {
            $child  = $this->assertSelector($positional[$i], 'selector.nest', true);
            $parent = $this->nestWithin($parent, $child);
        }

        return $this->selectorListNode($parent);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function parse(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('selector.parse'),
                'a string selector argument',
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'parse', $positional);

        return $this->selectorListNode($this->assertSelector($positional[0], 'selector.parse'));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function replace(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 3) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('selector.replace'),
                3,
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'replace', $positional);

        $selector    = $this->selectorTextArgument($positional[0], 'selector.replace');
        $original    = $this->selectorTextArgument($positional[1], 'selector.replace');
        $replacement = $this->selectorTextArgument($positional[2], 'selector.replace');

        $this->assertNoParentSelector($selector);
        $this->assertNoParentSelector($original);
        $this->assertNoParentSelector($replacement);

        $complexes = $this->tokenizer->splitAtTopLevel($selector, [',']);

        foreach ($this->tokenizer->splitAtTopLevel($original, [',']) as $target) {
            if ($this->hasUnsupportedTopLevelCombinator($target)) {
                throw new SassErrorException("Can't extend complex selector {$target}.");
            }

            if (count($this->splitSelectorCompounds($target)) > 1) {
                throw new SassErrorException("Can't extend complex selector {$target}.");
            }

            $complexes = $this->tokenizer->replaceSelectorTargetInComplexes($complexes, $target, $replacement);
        }

        return $this->selectorListNode(
            $this->tokenizer->parseSelectorList(implode(', ', array_values(array_unique($complexes)))),
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function simpleSelectors(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('selector.simple-selectors'),
                'a string selector argument',
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'simple-selectors', $positional);

        $complexes = $this->assertSelector($positional[0], 'selector.simple-selectors');

        if (
            count($complexes) !== 1
            || count($complexes[0]) !== 1
            || ($complexes[0][0]['lead'] ?? '') !== ''
            || $complexes[0][0]['comb'] !== ''
        ) {
            throw new SassErrorException('expected selector.');
        }

        $tokens = $this->tokenizer->tokenizeCompound($complexes[0][0]['sel']);

        if ($tokens === []) {
            throw new SassErrorException('expected selector.');
        }

        return new ListNode(
            array_map(fn(string $token): AstNode => new StringNode($token), $tokens),
            'comma',
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function unify(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 2) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('selector.unify'),
                2,
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'unify', $positional);

        $first  = $this->assertSelector($positional[0], 'selector.unify');
        $second = $this->assertSelector($positional[1], 'selector.unify');

        $result = [];

        foreach ($first as $firstComplex) {
            foreach ($second as $secondComplex) {
                $unified = $this->tokenizer->unifyComplexes($firstComplex, $secondComplex);

                if ($unified !== null) {
                    $result = [...$result, ...$unified];
                }
            }
        }

        if ($result === []) {
            return $this->nullNode();
        }

        return $this->selectorListNode($this->uniqueComplexes($result));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function warnAboutDeprecatedSelectorFunction(
        ?BuiltinCallContext $context,
        string $name,
        array $positional,
    ): void {
        if (! $this->isGlobalBuiltinCall()) {
            return;
        }

        $this->warnAboutDeprecatedBuiltinFunctionWithSingleSuggestion(
            $context,
            $this->deprecatedSelectorSuggestion($name, $positional),
            'selector.' . $name,
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function deprecatedSelectorSuggestion(string $name, array $positional): string
    {
        $arguments = $positional;
        $rawArguments = $this->activeBuiltinContext?->rawArguments;

        if ($rawArguments !== null) {
            $arguments = $this->rawPositionalArguments($rawArguments);
        }

        return 'selector.' . $name . '(' . implode(', ', $this->describeBuiltinArguments($arguments)) . ')';
    }

    /**
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    private function assertSelector(AstNode $value, string $context, bool $allowParent = false): array
    {
        $text = $this->selectorTextArgument($value, $context);

        if (! $allowParent && $this->tokenizer->textContainsParentSelector($text)) {
            throw new SassErrorException("Parent selectors aren't allowed here.");
        }

        if (! $this->isBalancedSelectorSyntax($text)) {
            throw new SassErrorException('expected more input.');
        }

        return $this->tokenizer->parseSelectorList($text);
    }

    private function selectorTextArgument(AstNode $value, string $context): string
    {
        return $this->normalizeSelector($this->selectorValueToText($value));
    }

    private function assertNoParentSelector(string $text): void
    {
        if ($this->tokenizer->textContainsParentSelector($text)) {
            throw new SassErrorException("Parent selectors aren't allowed here.");
        }
    }

    private function selectorValueToText(AstNode $value): string
    {
        if ($value instanceof StringNode) {
            return $value->value;
        }

        if ($value instanceof ListNode && ! $value->bracketed) {
            $separator = $value->separator === 'comma' ? ', ' : ' ';
            $parts     = [];

            foreach ($value->items as $item) {
                $parts[] = $this->selectorValueToText($item);
            }

            return implode($separator, $parts);
        }

        throw new SassErrorException(
            $this->displayAstValue($value)
            . " is not a valid selector: it must be a string,\na list of strings, or a list of lists of strings.",
        );
    }

    private function displayAstValue(AstNode $value): string
    {
        if ($value instanceof ListNode) {
            $parts = [];

            foreach ($value->items as $item) {
                $parts[] = $this->displayAstValue($item);
            }

            return implode($value->separator === 'comma' ? ', ' : ' ', $parts);
        }

        if (method_exists($value, '__toString')) {
            return (string) $value;
        }

        $classParts = explode('\\', $value::class);

        return lcfirst($classParts[count($classParts) - 1]);
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $complexes
     */
    private function selectorListNode(array $complexes): ListNode
    {
        $items = [];

        foreach ($complexes as $components) {
            $parts = [];
            $lead  = $components[0]['lead'] ?? '';

            if ($lead !== '') {
                foreach (explode(' ', $lead) as $piece) {
                    if ($piece !== '') {
                        $parts[] = new StringNode($piece);
                    }
                }
            }

            foreach ($components as $component) {
                if ($component['sel'] !== '') {
                    $parts[] = new StringNode($component['sel']);
                }

                if ($component['comb'] !== '') {
                    foreach (explode(' ', $component['comb']) as $piece) {
                        if ($piece !== '') {
                            $parts[] = new StringNode($piece);
                        }
                    }
                }
            }

            $inner = new ListNode($parts, 'space');
            $inner->isComputed = true;

            $items[] = $inner;
        }

        $listNode = new ListNode($items, 'comma');
        $listNode->isComputed = true;

        return $listNode;
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $parent
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $child
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    private function appendSelectors(array $parent, array $child): array
    {
        $rewrittenChildren = [];

        foreach ($child as $childComplex) {
            $firstChildComponent = $childComplex[0];
            $childLead           = '';

            if (array_key_exists('lead', $firstChildComponent)) {
                $childLead = $firstChildComponent['lead'];
            }

            if ($childLead !== '') {
                throw new SassErrorException(
                    "Can't append {$this->tokenizer->complexComponentsToString($childComplex)}"
                    . " to {$this->tokenizer->complexesToString($parent)}.",
                );
            }

            $prepared = $this->prependParentToCompound($childComplex[0]['sel']);

            if ($prepared === null) {
                throw new SassErrorException(
                    "Can't append {$this->tokenizer->complexComponentsToString($childComplex)}"
                    . " to {$this->tokenizer->complexesToString($parent)}.",
                );
            }

            $rewritten = [['sel' => $prepared, 'comb' => $childComplex[0]['comb']]];

            foreach (array_slice($childComplex, 1) as $extra) {
                $rewritten[] = $extra;
            }

            $rewrittenChildren[] = $rewritten;
        }

        return $this->uniqueComplexes($this->nestWithin($parent, $rewrittenChildren));
    }

    private function prependParentToCompound(string $sel): ?string
    {
        if ($sel === '') {
            return '&';
        }

        if (in_array($sel[0], ['.', '#', '%', ':', '['], true)) {
            return '&' . $sel;
        }

        if (str_contains($sel, '|') || $sel === '*') {
            return null;
        }

        return '&' . $sel;
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $parent
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $child
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    private function nestWithin(array $parent, array $child): array
    {
        $groups = [];

        foreach ($child as $childComplex) {
            if (! $this->complexContainsParentSelector($childComplex)) {
                $group = [];

                foreach ($parent as $parentComplex) {
                    $group[] = $this->concatenateComplexes($parentComplex, $childComplex);
                }

                $groups[] = $group;

                continue;
            }

            $newComplexes = [];

            foreach ($childComplex as $component) {
                $resolvedSelectors = $this->nestWithinCompound($component['sel'], $parent);

                if ($resolvedSelectors === null) {
                    if ($newComplexes === []) {
                        $newComplexes = [[['sel' => $component['sel'], 'comb' => $component['comb']]]];
                    } else {
                        $newComplexes = $this->appendComponentToGroups($newComplexes, $component);
                    }

                    continue;
                }

                $withCombinators = $this->applyCombinatorToVariants($resolvedSelectors, $component['comb']);

                if ($newComplexes === []) {
                    $newComplexes = $withCombinators;
                } else {
                    $newComplexes = $this->combineComplexGroups($newComplexes, $withCombinators);
                }
            }

            $lead = $childComplex[0]['lead'] ?? '';

            if ($lead !== '') {
                $newComplexes = $this->applyLeadingCombinator($newComplexes, $lead);
            }

            $groups[] = $newComplexes;
        }

        return $this->uniqueComplexes($this->flattenVertically($groups));
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $groups
     * @param array{sel: string, comb: string, lead?: string} $component
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    private function appendComponentToGroups(array $groups, array $component): array
    {
        foreach ($groups as $index => $group) {
            $groups[$index] = [...$group, $component];
        }

        return $groups;
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $variants
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    private function applyCombinatorToVariants(array $variants, string $combinator): array
    {
        $result = [];

        foreach ($variants as $variant) {
            if ($variant === []) {
                continue;
            }

            $last         = $variant[count($variant) - 1];
            $last['comb'] = $combinator;

            $result[] = [...array_slice($variant, 0, -1), $last];
        }

        return $result;
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $bases
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $extensions
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    private function combineComplexGroups(array $bases, array $extensions): array
    {
        $combined = [];

        foreach ($bases as $base) {
            foreach ($extensions as $extension) {
                $combined[] = $this->concatenateComplexes($base, $extension);
            }
        }

        return $combined;
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $complexes
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    private function applyLeadingCombinator(array $complexes, string $lead): array
    {
        foreach ($complexes as $index => $complex) {
            if ($complex === []) {
                continue;
            }

            $first = $complex[0];

            if (($first['lead'] ?? '') !== '') {
                continue;
            }

            $complexes[$index] = [
                ['sel' => $first['sel'], 'comb' => $first['comb'], 'lead' => $lead],
                ...array_slice($complex, 1),
            ];
        }

        return $complexes;
    }

    /**
     * @param array<int, array<int, array<int, array{sel: string, comb: string, lead?: string}>>> $groups
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    private function flattenVertically(array $groups): array
    {
        $queues = array_values(array_filter($groups, fn(array $group): bool => $group !== []));

        if (count($queues) <= 1) {
            return $queues[0] ?? [];
        }

        $result = [];

        while ($queues !== []) {
            $remaining = [];

            foreach ($queues as $queue) {
                $result[] = $queue[0];

                if (count($queue) > 1) {
                    $remaining[] = array_slice($queue, 1);
                }
            }

            $queues = $remaining;
        }

        return $result;
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $parent
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>|null
     */
    private function nestWithinCompound(string $sel, array $parent): ?array
    {
        if ($sel === '' || ! $this->stringContainsParent($sel)) {
            return null;
        }

        if (! str_starts_with($sel, '&')) {
            $variants = $this->resolveCompoundRemainder($sel, $parent);

            if ($variants === []) {
                return null;
            }

            $results = [];

            foreach ($variants as $variantTokens) {
                $results[] = [['sel' => implode('', $variantTokens), 'comb' => '']];
            }

            return $results;
        }

        $rest   = substr($sel, 1);
        $suffix = '';
        $length = strlen($rest);
        $pos    = 0;

        while ($pos < $length && $this->isSuffixCharacter($rest[$pos])) {
            $suffix .= $rest[$pos];

            $pos++;
        }

        $rest = substr($rest, $pos);

        if ($suffix === '' && $rest === '') {
            return $parent;
        }

        $variants = $this->resolveCompoundRemainder($rest, $parent);
        $results  = [];

        foreach ($parent as $parentComplex) {
            $lastIndex = count($parentComplex) - 1;

            if ($parentComplex[$lastIndex]['comb'] !== '') {
                throw new SassErrorException(
                    "Selector \"{$this->tokenizer->complexComponentsToString($parentComplex)}\""
                    . " can't be used as a parent in a compound selector.",
                );
            }

            $tokens = $this->tokenizer->tokenizeCompound($parentComplex[$lastIndex]['sel']);

            if ($tokens === []) {
                continue;
            }

            $lastToken    = $tokens[count($tokens) - 1];
            $prefixTokens = array_slice($tokens, 0, -1);

            if ($suffix !== '') {
                $lastToken .= $suffix;
            }

            foreach ($variants as $variantTokens) {
                $resolved = [
                    ...array_slice($parentComplex, 0, -1),
                    [
                        'sel'  => implode('', [...$prefixTokens, $lastToken, ...$variantTokens]),
                        'comb' => '',
                    ],
                ];

                $parentLead = $parentComplex[0]['lead'] ?? '';

                if ($parentLead !== '') {
                    $resolved[0]['lead'] = $parentLead;
                }

                $results[] = $resolved;
            }
        }

        return $results === [] ? null : $results;
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $parent
     * @return array<int, array<int, string>>
     */
    private function resolveCompoundRemainder(string $text, array $parent): array
    {
        if ($text === '') {
            return [[]];
        }

        $replacements = [];

        foreach ($parent as $parentComplex) {
            $replacements[] = $this->tokenizer->complexComponentsToString($parentComplex);
        }

        $variants = [[]];

        foreach ($this->tokenizer->tokenizeCompound($text) as $token) {
            if (! $this->stringContainsParent($token)) {
                foreach ($variants as $index => $variant) {
                    $variants[$index][] = $token;
                }

                continue;
            }

            $pseudoReplacement = $this->resolveParentInPseudoToken($token, $replacements);

            if ($pseudoReplacement !== null) {
                foreach ($variants as $index => $variant) {
                    $variants[$index][] = $pseudoReplacement;
                }

                continue;
            }

            $nextVariants = [];

            foreach ($variants as $variant) {
                foreach ($replacements as $replacement) {
                    $nextVariants[] = [...$variant, $replacement];
                }
            }

            $variants = $nextVariants;
        }

        return $variants;
    }

    /**
     * @param array<int, string> $replacements
     */
    private function resolveParentInPseudoToken(string $token, array $replacements): ?string
    {
        if ($token === '' || $token[0] !== ':' || ! str_ends_with($token, ')')) {
            return null;
        }

        $parenStart = strpos($token, '(');

        if ($parenStart === false) {
            return null;
        }

        $argument = substr($token, $parenStart + 1, -1);

        if (! $this->stringContainsParent($argument)) {
            return null;
        }

        $name = substr($token, 0, $parenStart);
        $list = implode(', ', $replacements);

        return $name . '(' . str_replace('&', $list, $argument) . ')';
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $parent
     * @param array<int, array{sel: string, comb: string, lead?: string}> $child
     * @return array<int, array{sel: string, comb: string, lead?: string}>
     */
    private function concatenateComplexes(array $parent, array $child): array
    {
        $childLead = $child[0]['lead'] ?? '';

        if ($childLead === '') {
            return [...$parent, ...$child];
        }

        $count = count($parent);

        if ($count === 0) {
            return $child;
        }

        $last     = $parent[$count - 1];
        $stripped = [['sel' => $child[0]['sel'], 'comb' => $child[0]['comb']], ...array_slice($child, 1)];

        if ($last['comb'] === '') {
            $last['comb'] = $childLead;
        } else {
            $last['comb'] .= ' ' . $childLead;
        }

        return [...array_slice($parent, 0, -1), $last, ...$stripped];
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $complexes
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    private function uniqueComplexes(array $complexes): array
    {
        $seen   = [];
        $result = [];

        foreach ($complexes as $complex) {
            $key = $this->tokenizer->complexesToString([$complex]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $result[] = $complex;
        }

        return $result;
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $complex
     */
    private function complexContainsParentSelector(array $complex): bool
    {
        foreach ($complex as $component) {
            if ($this->stringContainsParent($component['sel'])) {
                return true;
            }
        }

        return false;
    }

    private function stringContainsParent(string $text): bool
    {
        $length = strlen($text);
        $quote  = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '[') {
                $innerQuote = '';
                $depth      = 1;

                while ($i + 1 < $length && $depth > 0) {
                    $i++;
                    $inner = $text[$i];

                    if ($innerQuote !== '') {
                        if ($inner === $innerQuote) {
                            $innerQuote = '';
                        }

                        continue;
                    }

                    if ($inner === '"' || $inner === "'") {
                        $innerQuote = $inner;

                        continue;
                    }

                    if ($inner === '[') {
                        $depth++;
                    } elseif ($inner === ']') {
                        $depth--;
                    }
                }

                continue;
            }

            if ($char === '&') {
                return true;
            }
        }

        return false;
    }

    private function isSuffixCharacter(string $char): bool
    {
        return in_array($char, ['-', '_'], true)
            || ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || ($char >= '0' && $char <= '9')
            || $char === '\\';
    }

    private function isBalancedSelectorSyntax(string $text): bool
    {
        $length       = strlen($text);
        $quote        = '';
        $parenDepth   = 0;
        $bracketDepth = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '(') {
                $parenDepth++;

                continue;
            }

            if ($char === ')') {
                if ($parenDepth === 0) {
                    return false;
                }

                $parenDepth--;

                continue;
            }

            if ($char === '[') {
                $bracketDepth++;

                continue;
            }

            if ($char === ']') {
                if ($bracketDepth === 0) {
                    return false;
                }

                $bracketDepth--;
            }
        }

        return $parenDepth === 0 && $bracketDepth === 0 && $quote === '';
    }

    private function normalizeSelector(string $selector): string
    {
        $selector = trim($selector);
        $result   = '';
        $length   = strlen($selector);

        $inWhitespace = false;
        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if (in_array($char, [' ', "\t", "\n", "\r", "\f"], true)) {
                if (! $inWhitespace) {
                    $result .= ' ';

                    $inWhitespace = true;
                }

                continue;
            }

            $result .= $char;

            $inWhitespace = false;
        }

        return trim($result);
    }

    /**
     * @return array<int, string>
     */
    private function replaceExtendTargetInSelectorPart(string $part, string $target, string $source): array
    {
        $structured = $this->replaceExtendTargetInStructuredSelectorPart($part, $target, $source);

        if ($structured !== null) {
            return $structured;
        }

        if (! str_contains($part, $target)) {
            return [];
        }

        return [$this->normalizeSelector(str_replace($target, $source, $part))];
    }

    /**
     * @return array<int, string>|null
     */
    private function replaceExtendTargetInStructuredSelectorPart(string $part, string $target, string $source): ?array
    {
        if (
            $this->hasUnsupportedTopLevelCombinator($part)
            || $this->hasUnsupportedTopLevelCombinator($target)
            || $this->hasUnsupportedTopLevelCombinator($source)
        ) {
            return null;
        }

        $targetTokens = $this->tokenizer->tokenizeCompound($this->singleTargetText($target));

        if ($targetTokens === []) {
            return null;
        }

        $partCompounds   = $this->tokenizer->splitAtTopLevel($part, [' ', '>', '+', '~']);
        $sourceCompounds = $this->tokenizer->splitAtTopLevel($source, [' ', '>', '+', '~']);

        return $this->tokenizer->replaceExtendTargetInStructuredSelector(
            $partCompounds,
            $targetTokens,
            $sourceCompounds,
        );
    }

    private function singleTargetText(string $target): string
    {
        $parts = $this->tokenizer->splitAtTopLevel($target, [','], true);

        return $parts === [] ? '' : $parts[0];
    }

    private function hasUnsupportedTopLevelCombinator(string $selector): bool
    {
        return $this->tokenizer->hasUnsupportedTopLevelCombinator($selector);
    }

    /**
     * @return array<int, string>
     */
    private function splitSelectorCompounds(string $selector): array
    {
        return $this->tokenizer->splitAtTopLevel($selector, [' ', '>', '+', '~']);
    }
}
