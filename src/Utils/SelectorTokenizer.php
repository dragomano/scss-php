<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function array_fill;
use function array_fill_keys;
use function array_merge;
use function array_pop;
use function array_reverse;
use function array_shift;
use function array_slice;
use function array_splice;
use function array_unique;
use function array_unshift;
use function array_values;
use function count;
use function ctype_alnum;
use function ctype_alpha;
use function implode;
use function in_array;
use function max;
use function str_starts_with;
use function strlen;
use function trim;

/**
 * @phpstan-type Complex array<int, array{sel: string, comb: string, lead?: string}>
 */
final readonly class SelectorTokenizer
{
    /**
     * @return array<int, string>
     */
    public function tokenizeCompound(string $compound): array
    {
        $tokens = [];
        $length = strlen($compound);
        $index  = 0;

        while ($index < $length) {
            $char = $compound[$index];

            if ($char === '[') {
                $tokens[] = $this->readBracketGroup($compound, $index, '[', ']');

                continue;
            }

            if ($char === ':') {
                $tokens[] = $this->readPseudoSelector($compound, $index);

                continue;
            }

            if ($char === '#' || $char === '.' || $char === '%') {
                $token = $char;

                $index++;

                $token .= $this->readIdentifier($compound, $index);

                if ($token !== '#' && $token !== '.') {
                    $tokens[] = $token;
                }

                continue;
            }

            if ($char === '*') {
                if ($index + 1 < $length && $compound[$index + 1] === '|') {
                    $index++;

                    $tokens[] = $this->readNamespacedType($compound, $index, '*');

                    continue;
                }

                $tokens[] = '*';

                $index++;

                continue;
            }

            if ($char === '|') {
                $tokens[] = $this->readNamespacedType($compound, $index, '');

                continue;
            }

            if ($this->isIdentifierChar($char)) {
                $token = $this->readIdentifier($compound, $index);

                if ($index < $length && $compound[$index] === '|') {
                    $token = $this->readNamespacedType($compound, $index, $token);
                }

                $tokens[] = $token;

                continue;
            }

            $index++;
        }

        return $tokens;
    }

    /**
     * @param array<int, string> $targetTokens
     */
    public function removeTokensFromCompound(string $compound, array $targetTokens): ?string
    {
        $compoundTokens = $this->tokenizeCompound($compound);

        if ($compoundTokens === []) {
            return null;
        }

        $remainingTokens = [];
        $required        = [];

        foreach ($targetTokens as $targetToken) {
            $required[] = $targetToken;
        }

        foreach ($compoundTokens as $compoundToken) {
            $matched = false;
            $key     = array_search($compoundToken, $required, true);

            if (is_int($key)) {
                $matched = true;
                array_splice($required, $key, 1);
            }

            if (! $matched) {
                $remainingTokens[] = $compoundToken;
            }
        }

        if ($required !== []) {
            return null;
        }

        return implode('', $remainingTokens);
    }

    /**
     * @param array<int, string> $targetTokens
     */
    public function replaceTokensInCompound(string $compound, array $targetTokens, string $replacement): ?string
    {
        $compoundTokens    = $this->tokenizeCompound($compound);
        $replacementTokens = $this->tokenizeCompound($replacement);

        if ($compoundTokens === []) {
            return null;
        }

        $remainingCompound = $this->removeTokensFromCompound($compound, $targetTokens);

        if ($remainingCompound === null) {
            return null;
        }

        if ($remainingCompound === '') {
            return $replacement;
        }

        if ($this->unifyCompounds($replacement, $remainingCompound) === null) {
            return null;
        }

        $remainingTokens = $this->tokenizeCompound($remainingCompound);
        $targetType      = $this->extractTypeToken($targetTokens);
        $orderedTokens   = [];

        if ($targetType !== '') {
            foreach ($replacementTokens as $token) {
                if (! in_array($token, $orderedTokens, true)) {
                    $orderedTokens[] = $token;
                }
            }

            foreach ($remainingTokens as $token) {
                if (! in_array($token, $orderedTokens, true)) {
                    $orderedTokens[] = $token;
                }
            }

            if ($this->shouldNormalizePseudoOrder($orderedTokens)) {
                return implode('', $this->orderTokens($orderedTokens));
            }

            return implode('', $orderedTokens);
        }

        if ($this->extractTypeToken($replacementTokens) !== '') {
            $unified = $this->unifyCompounds($replacement, $remainingCompound);

            return $unified;
        }

        foreach ($remainingTokens as $token) {
            if (! in_array($token, $orderedTokens, true)) {
                $orderedTokens[] = $token;
            }
        }

        foreach ($replacementTokens as $token) {
            if (! in_array($token, $orderedTokens, true)) {
                $orderedTokens[] = $token;
            }
        }

        if ($this->shouldNormalizePseudoOrder($orderedTokens)) {
            return implode('', $this->orderTokens($orderedTokens));
        }

        return implode('', $orderedTokens);
    }

    public function unifyCompounds(string $left, string $right): ?string
    {
        $leftTokens  = $this->tokenizeCompound($left);
        $rightTokens = $this->tokenizeCompound($right);

        if ($leftTokens === [] && $rightTokens === []) {
            return '';
        }

        $leftType  = $this->extractTypeToken($leftTokens);
        $rightType = $this->extractTypeToken($rightTokens);

        if ($leftType !== '' && $rightType !== '') {
            $resolvedType = $this->unifyTypeTokens($leftType, $rightType);

            if ($resolvedType === null) {
                return null;
            }
        } else {
            $resolvedType = $leftType !== '' ? $leftType : $rightType;
        }

        $leftId  = $this->extractIdToken($leftTokens);
        $rightId = $this->extractIdToken($rightTokens);

        if ($leftId !== '' && $rightId !== '' && $leftId !== $rightId) {
            return null;
        }

        $result = [];
        if ($resolvedType !== '' && ! $this->isUniversalTypeToken($resolvedType)) {
            $result[] = $resolvedType;
        }

        $mergedNonTypeTokens = [];

        foreach ($leftTokens as $token) {
            if (
                in_array($token, ['', '*', '*|*'], true)
                || $token === $leftType
                || in_array($token, $mergedNonTypeTokens, true)
            ) {
                continue;
            }

            $mergedNonTypeTokens[] = $token;
        }

        foreach ($rightTokens as $token) {
            if (
                in_array($token, ['', '*', '*|*'], true)
                || $token === $rightType
                || in_array($token, $mergedNonTypeTokens, true)
            ) {
                continue;
            }

            $mergedNonTypeTokens[] = $token;
        }

        foreach ($this->orderTokens($mergedNonTypeTokens) as $token) {
            if (! in_array($token, $result, true)) {
                $result[] = $token;
            }
        }

        if ($result === []) {
            if ($resolvedType !== '') {
                return $resolvedType;
            }

            return '';
        }

        return implode('', $result);
    }

    public function doesCompoundSatisfy(string $candidate, string $required): bool
    {
        $candidateTokens = $this->tokenizeCompound($candidate);
        $requiredTokens  = $this->tokenizeCompound($required);

        if ($requiredTokens === []) {
            return true;
        }

        if ($candidateTokens === []) {
            return false;
        }

        $requiredType  = $this->extractTypeToken($requiredTokens);
        $candidateType = $this->extractTypeToken($candidateTokens);

        if ($requiredType !== '' && ! $this->isUniversalTypeToken($requiredType)) {
            if (! $this->doesTypeSatisfy($candidateType, $requiredType)) {
                return false;
            }
        }

        /** @var array<string, true> $candidateTokenSet */
        $candidateTokenSet = array_fill_keys($candidateTokens, true);

        $candidateIsUniversal = $this->isUniversalTypeToken($candidateType);

        foreach ($requiredTokens as $requiredToken) {
            if (in_array($requiredToken, ['', '*', '*|*', $requiredType], true)) {
                continue;
            }

            if (isset($candidateTokenSet[$requiredToken])) {
                continue;
            }

            if ($this->isPseudoElementToken($requiredToken)) {
                return false;
            }

            if ($candidateIsUniversal) {
                continue;
            }

            return false;
        }

        return true;
    }

    public function hasUnsupportedTopLevelCombinator(string $selector): bool
    {
        return $this->inspectTopLevelCombinators(
            $selector,
            static fn(string $char): bool => in_array($char, ['>', '+', '~'], true),
        );
    }

    public function hasBogusTopLevelCombinatorSequence(string $selector): bool
    {
        $state = new class {
            public bool $lastTokenWasCombinator = false;
        };

        return $this->inspectTopLevelCombinators(
            $selector,
            static function (string $char) use ($state): bool {
                if (in_array($char, ['>', '+', '~'], true)) {
                    if ($state->lastTokenWasCombinator) {
                        return true;
                    }

                    $state->lastTokenWasCombinator = true;

                    return false;
                }

                if ($char !== ' ') {
                    $state->lastTokenWasCombinator = false;
                }

                return false;
            },
        );
    }

    public function hasAdjacentCompoundSelectors(string $selector): bool
    {
        $length = strlen($selector);
        $i      = 0;

        $seenNonTypeInCompound = false;

        while ($i < $length) {
            $char = $selector[$i];

            if ($char === ' ' || $char === '>' || $char === '+' || $char === '~') {
                $seenNonTypeInCompound = false;
                $i++;

                continue;
            }

            if ($char === '[') {
                $depth = 0;
                $quote = '';

                while ($i < $length) {
                    $c = $selector[$i];

                    if ($quote !== '') {
                        if ($c === $quote) {
                            $quote = '';
                        }

                        $i++;

                        continue;
                    }

                    if ($c === '"' || $c === "'") {
                        $quote = $c;
                        $i++;

                        continue;
                    }

                    if ($c === '[') {
                        $depth++;
                    } elseif ($c === ']') {
                        $depth--;

                        if ($depth === 0) {
                            $i++;

                            break;
                        }
                    }

                    $i++;
                }

                $seenNonTypeInCompound = true;

                continue;
            }

            if ($char === '.') {
                $i++;

                while ($i < $length && $this->isIdentifierChar($selector[$i])) {
                    $i++;
                }

                $seenNonTypeInCompound = true;

                continue;
            }

            if ($char === '#') {
                if (isset($selector[$i + 1]) && $selector[$i + 1] === '{') {
                    $i += 2;

                    while ($i < $length && $selector[$i] !== '}') {
                        $i++;
                    }

                    $i++;
                } else {
                    $i++;

                    while ($i < $length && $this->isIdentifierChar($selector[$i])) {
                        $i++;
                    }
                }

                $seenNonTypeInCompound = true;

                continue;
            }

            if ($char === ':') {
                $i++;

                if ($i < $length && $selector[$i] === ':') {
                    $i++;
                }

                while ($i < $length && $this->isIdentifierChar($selector[$i])) {
                    $i++;
                }

                if ($i < $length && $selector[$i] === '(') {
                    $depth = 0;

                    while ($i < $length) {
                        $c = $selector[$i];

                        if ($c === '(') {
                            $depth++;
                        } elseif ($c === ')') {
                            $depth--;

                            if ($depth === 0) {
                                $i++;

                                break;
                            }
                        }

                        $i++;
                    }
                }

                $seenNonTypeInCompound = true;

                continue;
            }

            if ($char === '*') {
                $seenNonTypeInCompound = true;
                $i++;

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                if ($seenNonTypeInCompound) {
                    return true;
                }

                while ($i < $length && $this->isIdentifierChar($selector[$i])) {
                    $i++;
                }

                continue;
            }

            $i++;
        }

        return false;
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     * @return array<int, array<int, string>>
     */
    public function interleaveSequences(array $left, array $right): array
    {
        if ($left === []) {
            return [$right];
        }

        if ($right === []) {
            return [$left];
        }

        $result = [array_merge($left, $right), array_merge($right, $left)];
        $unique = [];

        foreach ($result as $variant) {
            $unique[implode("\0", $variant)] = $variant;
        }

        return array_values($unique);
    }

    /**
     * @return string
     */
    public function normalizeSelectorAttributes(string $selector): string
    {
        if (! str_contains($selector, '[')) {
            return $selector;
        }

        $result = '';
        $length = strlen($selector);
        $index  = 0;

        while ($index < $length) {
            if ($selector[$index] === '[') {
                $result .= $this->normalizeAttributeToken($this->readBracketGroup($selector, $index, '[', ']'));

                continue;
            }

            $result .= $selector[$index];
            $index++;
        }

        return $result;
    }

    /**
     * @param array<int, string> $tokens
     * @return array<int, string>
     */
    public function orderTokens(array $tokens): array
    {
        $types                = [];
        $ids                  = [];
        $classesAndAttributes = [];
        $pseudoClasses        = [];
        $pseudoElements       = [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if ($token[0] === '#') {
                $ids[] = $token;

                continue;
            }

            if ($token[0] === '.' || $token[0] === '%' || $token[0] === '[') {
                $classesAndAttributes[] = $token;

                continue;
            }

            if ($token[0] === ':') {
                if ($this->isPseudoElementToken($token)) {
                    $pseudoElements[] = $token;
                } else {
                    $pseudoClasses[] = $token;
                }

                continue;
            }

            $types[] = $token;
        }

        return array_merge($types, $ids, $classesAndAttributes, $pseudoClasses, $pseudoElements);
    }

    /**
     * @param array<int, string> $tokens
     */
    public function extractTypeToken(array $tokens): string
    {
        foreach ($tokens as $token) {
            if (
                $token === ''
                || $token === '*'
                || $token[0] === '.'
                || $token[0] === '#'
                || $token[0] === '%'
                || $token[0] === ':'
            ) {
                continue;
            }

            if ($token[0] === '[') {
                continue;
            }

            return $token;
        }

        if (in_array('*', $tokens, true)) {
            return '*';
        }

        return '';
    }

    /**
     * @param array<int, string> $tokens
     */
    public function extractIdToken(array $tokens): string
    {
        foreach ($tokens as $token) {
            if ($token[0] === '#') {
                return $token;
            }
        }

        return '';
    }

    /**
     * @param array<int, string> $partCompounds
     * @param array<int, string> $targetTokens
     * @param array<int, string> $replacementCompounds
     * @return array<int, string>
     */
    public function replaceExtendTargetInStructuredSelector(
        array $partCompounds,
        array $targetTokens,
        array $replacementCompounds,
    ): array {
        if ($partCompounds === [] || $replacementCompounds === []) {
            return [];
        }

        $replacementSubject   = $replacementCompounds[count($replacementCompounds) - 1];
        $replacementAncestors = [];

        for ($i = 0; $i < count($replacementCompounds) - 1; $i++) {
            $replacementAncestors[] = $replacementCompounds[$i];
        }

        $resolved = [];

        for ($index = 0; $index < count($partCompounds); $index++) {
            $remainingCompound = $this->removeTokensFromCompound($partCompounds[$index], $targetTokens);

            if ($remainingCompound === null) {
                continue;
            }

            $unifiedSubject = $replacementAncestors === []
                ? $this->replaceTokensInCompound($partCompounds[$index], $targetTokens, $replacementSubject)
                : $this->unifyCompounds($replacementSubject, $remainingCompound);

            if ($unifiedSubject === null) {
                continue;
            }

            $prefix = [];

            for ($i = 0; $i < $index; $i++) {
                $prefix[] = $partCompounds[$i];
            }

            $suffix = [];

            for ($i = $index + 1; $i < count($partCompounds); $i++) {
                $suffix[] = $partCompounds[$i];
            }

            $requiredAncestors = [];

            foreach ($replacementAncestors as $ancestor) {
                $covered = false;

                foreach ($prefix as $prefixCompound) {
                    if ($this->doesCompoundSatisfy($prefixCompound, $ancestor)) {
                        $covered = true;

                        break;
                    }
                }

                if (! $covered) {
                    $requiredAncestors[] = $ancestor;
                }
            }

            foreach ($this->interleaveSequences($prefix, $requiredAncestors) as $prefixVariant) {
                $candidateCompounds = [];

                foreach ($prefixVariant as $compound) {
                    $candidateCompounds[] = $compound;
                }

                $candidateCompounds[] = $unifiedSubject;

                foreach ($suffix as $compound) {
                    $candidateCompounds[] = $compound;
                }

                $resolved[] = implode(' ', $candidateCompounds);
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @return array<int, string>
     */
    public function weaveExtendedSelector(string $part, string $target, string $extender): array
    {
        if (
            $this->hasBogusTopLevelCombinatorSequence($part)
            || $this->hasBogusTopLevelCombinatorSequence($target)
            || $this->hasBogusTopLevelCombinatorSequence($extender)
        ) {
            return [];
        }

        $partComponents     = $this->parseComplexComponents($part);
        $extenderComponents = $this->parseComplexComponents($extender);

        if ($partComponents === [] || $extenderComponents === []) {
            return [];
        }

        $partLeading     = $partComponents[0]['lead'] ?? '';
        $extenderLeading = $extenderComponents[0]['lead'] ?? '';

        if ($partLeading !== '' && $extenderLeading !== '' && $partLeading !== $extenderLeading) {
            return [];
        }

        $leading = $partLeading !== '' ? $partLeading : $extenderLeading;

        $targetTokens = $this->tokenizeCompound($target);

        $replacementSubject = $extenderComponents[count($extenderComponents) - 1];

        $multiCompoundExtender = count($extenderComponents) > 1;

        $resolved = [];

        if (! $multiCompoundExtender) {
            $replaceable = [];

            foreach ($partComponents as $index => $component) {
                if ($this->removeTokensFromCompound($component['sel'], $targetTokens) !== null) {
                    $replaceable[] = $index;
                }
            }

            $positionCount = count($replaceable);

            for ($size = 1; $size <= $positionCount; $size++) {
                $subsets = [];

                $this->collectCombinations($replaceable, $size, 0, [], $subsets);

                foreach ($subsets as $subset) {
                    $candidate = [];
                    $valid     = true;

                    foreach ($partComponents as $index => $component) {
                        if (in_array($index, $subset, true)) {
                            $unifiedSubject = $this->replaceTokensInCompound(
                                $component['sel'],
                                $targetTokens,
                                $replacementSubject['sel'],
                            );

                            if ($unifiedSubject === null) {
                                $valid = false;

                                break;
                            }

                            $candidate[] = [
                                'sel'  => $unifiedSubject,
                                'comb' => $component['comb'],
                                'lead' => '',
                            ];
                        } else {
                            $candidate[] = $component;
                        }
                    }

                    if (! $valid) {
                        continue;
                    }

                    if ($leading !== '' && ! isset($candidate[0]['lead'])) {
                        $candidate[0]['lead'] = $leading;
                    }

                    $resolved[] = $this->complexComponentsToString($candidate);
                }
            }

            return array_values(array_unique($resolved));
        }

        foreach ($partComponents as $index => $component) {
            $remainingCompound = $this->removeTokensFromCompound($component['sel'], $targetTokens);

            if ($remainingCompound === null) {
                continue;
            }

            $unifiedSubject = $this->unifyCompounds($remainingCompound, $replacementSubject['sel']);

            if ($unifiedSubject === null) {
                continue;
            }

            $prefix = array_slice($partComponents, 0, $index);
            $suffix = array_slice($partComponents, $index + 1);

            $wovenPrefixes = $this->weaveParents($prefix, $extenderComponents) ?? [];

            foreach ($wovenPrefixes as $wovenPrefix) {
                /** @var array<int, array{sel: string, comb: string, lead?: string}> $candidate */
                $candidate = $wovenPrefix;

                $candidate[] = [
                    'sel'  => $unifiedSubject,
                    'comb' => $suffix === [] ? '' : $component['comb'],
                    'lead' => '',
                ];

                foreach ($suffix as $suffixComponent) {
                    $candidate[] = $suffixComponent;
                }

                if ($leading !== '' && ! isset($candidate[0]['lead'])) {
                    $candidate[0] = ['sel' => $candidate[0]['sel'], 'comb' => $candidate[0]['comb'], 'lead' => $leading];
                }

                $resolved[] = $this->complexComponentsToString($candidate);
            }
        }

        return array_values(array_unique($resolved));
    }

    public function isPseudoElementToken(string $token): bool
    {
        return str_starts_with($token, '::')
            || in_array($token, [':before', ':after', ':first-line', ':first-letter'], true);
    }

    /**
     * @param array<int, string> $splitChars
     * @return array<int, string>
     */
    public function splitAtTopLevel(string $selector, array $splitChars, bool $handleQuotes = false, bool $trim = true): array
    {
        $result       = [];
        $buffer       = '';
        $parenDepth   = 0;
        $bracketDepth = 0;
        $quote        = '';
        $length       = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if ($handleQuotes && $quote !== '') {
                $buffer .= $char;

                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($handleQuotes && ($char === '"' || $char === "'")) {
                $quote   = $char;
                $buffer .= $char;

                continue;
            }

            if ($char === '[') {
                $bracketDepth++;

                $buffer .= $char;

                continue;
            }

            if ($char === ']' && $bracketDepth > 0) {
                $bracketDepth--;

                $buffer .= $char;

                continue;
            }

            if ($char === '(') {
                $parenDepth++;

                $buffer .= $char;

                continue;
            }

            if ($char === ')' && $parenDepth > 0) {
                $parenDepth--;

                $buffer .= $char;

                continue;
            }

            if ($parenDepth === 0 && $bracketDepth === 0 && in_array($char, $splitChars, true)) {
                $part = $trim ? trim($buffer) : $buffer;

                if ($part !== '') {
                    $result[] = $part;
                }

                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $part = $trim ? trim($buffer) : $buffer;

        if ($part !== '') {
            $result[] = $part;
        }

        return $result;
    }

    /**
     * @return array<int, array{sel: string, comb: string, lead?: string}>
     */
    public function parseComplexComponents(string $complex): array
    {
        /** @var array<string> $items */
        $items        = [];
        /** @var string $buffer */
        $buffer       = '';
        $length       = strlen($complex);
        $parenDepth   = 0;
        $bracketDepth = 0;
        $quote        = '';

        $flush = function () use (&$items, &$buffer): void {
            if ($buffer !== '') {
                $items[] = $buffer;
                $buffer = '';
            }
        };

        for ($i = 0; $i < $length; $i++) {
            $char = $complex[$i];

            if ($quote !== '') {
                $buffer .= $char;

                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;

                continue;
            }

            if ($char === '[') {
                $bracketDepth++;
                $buffer .= $char;

                continue;
            }

            if ($char === ']' && $bracketDepth > 0) {
                $bracketDepth--;
                $buffer .= $char;

                continue;
            }

            if ($char === '(') {
                $parenDepth++;
                $buffer .= $char;

                continue;
            }

            if ($char === ')' && $parenDepth > 0) {
                $parenDepth--;
                $buffer .= $char;

                continue;
            }

            if ($parenDepth !== 0 || $bracketDepth !== 0) {
                $buffer .= $char;

                continue;
            }

            if ($char === '>' || $char === '+' || $char === '~') {
                $flush();

                $items[] = $char;

                continue;
            }

            if ($char === ' ') {
                $flush();

                continue;
            }

            $buffer .= $char;
        }

        $flush();

        $components = [];
        $count      = count($items);
        $leading    = '';

        if ($count > 0 && in_array($items[0], ['>', '+', '~'], true)) {
            $leading = $items[0];
        }

        for ($i = 0; $i < $count; $i++) {
            if (in_array($items[$i], ['>', '+', '~'], true)) {
                continue;
            }

            $next = $i + 1 < $count ? $items[$i + 1] : '';
            $comb = in_array($next, ['>', '+', '~'], true) ? $next : '';

            $component = ['sel' => $items[$i], 'comb' => $comb];

            if ($leading !== '' && $components === []) {
                $component['lead'] = $leading;
            }

            $components[] = $component;
        }

        return $components;
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $components
     */
    public function complexComponentsToString(array $components): string
    {
        $result = '';
        $count  = count($components);

        foreach ($components as $i => $component) {
            if ($i === 0 && isset($component['lead']) && $component['lead'] !== '') {
                $result .= $component['lead'] . ' ';
            }

            $result .= $component['sel'];

            if ($i < $count - 1) {
                $result .= $component['comb'] === '' ? ' ' : ' ' . $component['comb'] . ' ';
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $complexes
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    public function weave(array $complexes): array
    {
        if ($complexes === []) {
            return [];
        }

        $prefixes = [$complexes[0]];
        $count    = count($complexes);

        for ($index = 1; $index < $count; $index++) {
            $complex = $complexes[$index];

            if (count($complex) === 1) {
                foreach ($prefixes as $i => $prefix) {
                    $prefixes[$i] = [...$prefix, $complex[0]];
                }

                continue;
            }

            $newPrefixes = [];

            foreach ($prefixes as $prefix) {
                foreach ($this->weaveParents($prefix, $complex) ?? [] as $parentPrefix) {
                    $newPrefixes[] = [...$parentPrefix, $complex[count($complex) - 1]];
                }
            }

            $prefixes = $newPrefixes;
        }

        return $prefixes;
    }

    /**
     * @param Complex $prefix
     * @param Complex $base
     * @return array<int, Complex>|null
     */
    public function weaveParents(array $prefix, array $base): ?array
    {
        $queue1 = $prefix;
        $queue2 = $base === [] ? [] : array_slice($base, 0, -1);

        $rootish1 = $this->firstIfRootish($queue1);
        $rootish2 = $this->firstIfRootish($queue2);

        if ($rootish1 !== null && $rootish2 !== null) {
            $rootish = $this->unifyCompounds($rootish1['sel'], $rootish2['sel']);

            if ($rootish === null) {
                return null;
            }

            array_unshift($queue1, ['sel' => $rootish, 'comb' => $rootish1['comb']]);
            array_unshift($queue2, ['sel' => $rootish, 'comb' => $rootish2['comb']]);
        } elseif ($rootish1 !== null || $rootish2 !== null) {
            $rootish = $rootish1 ?? $rootish2;

            array_unshift($queue1, $rootish);
            array_unshift($queue2, $rootish);
        }

        $trailingCombinators = $this->mergeTrailingCombinators($queue1, $queue2);

        if ($trailingCombinators === null) {
            return null;
        }

        $groups1 = $this->groupSelectors($queue1);
        $groups2 = $this->groupSelectors($queue2);

        $lcs = $this->longestCommonSubsequence(
            $groups2,
            $groups1,
            /**
             * @param Complex $group1
             * @param Complex $group2
             * @return Complex|null
             */
            function (array $group1, array $group2): ?array {
                if ($group1 === $group2) {
                    return $group1;
                }

                if ($this->complexIsParentSuperselector($group1, $group2)) {
                    return $group2;
                }

                if ($this->complexIsParentSuperselector($group2, $group1)) {
                    return $group1;
                }

                if (! $this->mustUnify($group1, $group2)) {
                    return null;
                }

                return $this->unifyGroups($group1, $group2);
            },
        );

        $choices = [];

        foreach ($lcs as $group) {
            $options = [];

            foreach ($this->chunks(
                $groups1,
                $groups2,
                /**
                 * @param array<int, Complex> $queue
                 */
                fn(array $queue): bool => $queue !== [] && $this->complexIsParentSuperselector($queue[0], $group),
            ) as $chunk) {
                $flat = [];

                foreach ($chunk as $components) {
                    $flat = [...$flat, ...$components];
                }

                $options[] = $flat;
            }

            if ($options !== []) {
                $choices[] = $options;
            }

            $choices[] = [$group];

            array_shift($groups1);
            array_shift($groups2);
        }

        $options = [];

        foreach ($this->chunks($groups1, $groups2, static fn(array $queue): bool => $queue === []) as $chunk) {
            $flat = [];

            foreach ($chunk as $components) {
                $flat = [...$flat, ...$components];
            }

            $options[] = $flat;
        }

        if ($options !== []) {
            $choices[] = $options;
        }

        foreach ($trailingCombinators as $trailingChoice) {
            $choices[] = $trailingChoice;
        }

        $result = [];

        foreach ($this->paths($choices) as $path) {
            $components = [];

            foreach ($path as $option) {
                $components = [...$components, ...$option];
            }

            $result[] = $components;
        }

        return $result;
    }

    /**
     * @param array<int, int> $positions
     * @param array<int, int> $current
     * @param array<int, array<int, int>> $result
     */
    private function collectCombinations(array $positions, int $size, int $start, array $current, array &$result): void
    {
        if (count($current) === $size) {
            $result[] = $current;

            return;
        }

        for ($i = $start; $i < count($positions); $i++) {
            $current[] = $positions[$i];

            $this->collectCombinations($positions, $size, $i + 1, $current, $result);

            array_pop($current);
        }
    }

    private function readPseudoSelector(string $compound, int &$index): string
    {
        $length = strlen($compound);
        $token  = ':';

        $index++;

        if ($index < $length && $compound[$index] === ':') {
            $token .= ':';

            $index++;
        }

        $token .= $this->readIdentifier($compound, $index);

        if ($index < $length && $compound[$index] === '(') {
            $token .= $this->readBracketGroup($compound, $index, '(', ')');
        }

        return $token;
    }

    private function readBracketGroup(string $input, int &$index, string $open, string $close): string
    {
        $length = strlen($input);
        $depth  = 0;
        $token  = '';
        $quote  = '';

        while ($index < $length) {
            $char   = $input[$index];
            $token .= $char;

            if ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                }

                $index++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                $index++;

                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;

                if ($depth === 0) {
                    $index++;

                    break;
                }
            }

            $index++;
        }

        if ($open === '[') {
            return $this->normalizeAttributeToken($token);
        }

        return $this->normalizePseudoToken($token);
    }

    private function normalizeAttributeToken(string $token): string
    {
        $inner = substr($token, 1, -1);
        $eq    = strpos($inner, '=');

        if ($eq === false) {
            return $token;
        }

        $operatorStart = $eq;

        while ($operatorStart > 0 && in_array($inner[$operatorStart - 1], ['~', '|', '^', '$', '*'], true)) {
            $operatorStart--;
        }

        $name = rtrim(substr($inner, 0, $operatorStart));

        if ($name === '') {
            return $token;
        }

        $operator = substr($inner, $operatorStart, $eq - $operatorStart + 1);
        $value    = ltrim(substr($inner, $eq + 1));

        return '[' . $name . $operator . $this->unquoteIdentifierValue($value) . ']';
    }

    private function normalizePseudoToken(string $token): string
    {
        if (! str_contains($token, '[')) {
            return $token;
        }

        $result = '';
        $length = strlen($token);
        $index  = 0;

        while ($index < $length) {
            if ($token[$index] === '[') {
                $result .= $this->normalizeAttributeToken($this->readBracketGroup($token, $index, '[', ']'));

                continue;
            }

            $result .= $token[$index];
            $index++;
        }

        return $result;
    }

    private function unquoteIdentifierValue(string $value): string
    {
        $length = strlen($value);

        if ($length < 2) {
            return $value;
        }

        $quote = $value[0];

        if (($quote !== '"' && $quote !== "'") || $value[$length - 1] !== $quote) {
            return $value;
        }

        $inner = substr($value, 1, -1);

        if (! $this->isIdentifier($inner)) {
            return $value;
        }

        return $inner;
    }

    private function isIdentifier(string $value): bool
    {
        $length = strlen($value);

        if ($length === 0) {
            return false;
        }

        if (! (ctype_alpha($value[0]) || $value[0] === '_' || $value[0] === '-')) {
            return false;
        }

        if ($value[0] === '-' && ($length === 1 || $value[1] === '-')) {
            return false;
        }

        for ($index = 1; $index < $length; $index++) {
            if (! (ctype_alnum($value[$index]) || $value[$index] === '_' || $value[$index] === '-')) {
                return false;
            }
        }

        return true;
    }

    private function readIdentifier(string $input, int &$index): string
    {
        $length     = strlen($input);
        $identifier = '';

        while ($index < $length && $this->isIdentifierChar($input[$index])) {
            $identifier .= $input[$index];

            $index++;
        }

        return $identifier;
    }

    private function isIdentifierChar(string $char): bool
    {
        return $char !== '' && (ctype_alnum($char) || $char === '-' || $char === '_');
    }

    /**
     * @param callable(string): bool $inspector
     */
    private function inspectTopLevelCombinators(string $selector, callable $inspector): bool
    {
        $parenDepth   = 0;
        $bracketDepth = 0;
        $quote        = '';
        $length       = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

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
                $bracketDepth++;

                continue;
            }

            if ($char === ']' && $bracketDepth > 0) {
                $bracketDepth--;

                continue;
            }

            if ($char === '(') {
                $parenDepth++;

                continue;
            }

            if ($char === ')' && $parenDepth > 0) {
                $parenDepth--;

                continue;
            }

            if ($parenDepth !== 0 || $bracketDepth !== 0) {
                continue;
            }

            if ($inspector($char)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $tokens
     */
    private function shouldNormalizePseudoOrder(array $tokens): bool
    {
        $hasPseudoClass  = false;
        $hasPseudoElement = false;
        $hasClassLike    = false;

        foreach ($tokens as $token) {
            if ($token[0] === ':') {
                if ($this->isPseudoElementToken($token)) {
                    $hasPseudoElement = true;
                } else {
                    $hasPseudoClass = true;
                }

                continue;
            }

            if ($token[0] === '.' || $token[0] === '#' || $token[0] === '%' || $token[0] === '[') {
                $hasClassLike = true;
            }
        }

        return ($hasPseudoClass && $hasPseudoElement) || (($hasPseudoClass || $hasPseudoElement) && $hasClassLike);
    }

    private function isUniversalTypeToken(string $token): bool
    {
        return $token === '*' || $token === '*|*';
    }

    private function unifyTypeTokens(string $left, string $right): ?string
    {
        $leftType  = $this->parseTypeToken($left);
        $rightType = $this->parseTypeToken($right);

        if ($leftType['element'] === $rightType['element'] || $leftType['element'] === '*') {
            $namespace = $this->unifyNamespaces($leftType['namespace'], $rightType['namespace']);

            if ($namespace === null) {
                return null;
            }

            return $namespace === '' ? $rightType['element'] : $namespace . '|' . $rightType['element'];
        }

        if ($rightType['element'] === '*') {
            $namespace = $this->unifyNamespaces($leftType['namespace'], $rightType['namespace']);

            if ($namespace === null) {
                return null;
            }

            return $namespace === '' ? $leftType['element'] : $namespace . '|' . $leftType['element'];
        }

        return null;
    }

    private function unifyNamespaces(?string $left, ?string $right): ?string
    {
        if ($left === $right || $left === '*') {
            return $right ?? '';
        }

        if ($right === '*') {
            return $left ?? '';
        }

        return null;
    }

    /**
     * @return array{namespace: ?string, element: string}
     */
    private function parseTypeToken(string $token): array
    {
        $pipe = strpos($token, '|');

        if ($pipe === false) {
            return ['namespace' => null, 'element' => $token];
        }

        $namespace = substr($token, 0, $pipe);

        return ['namespace' => $namespace, 'element' => substr($token, $pipe + 1)];
    }

    private function doesTypeSatisfy(string $candidateType, string $requiredType): bool
    {
        $candidate = $this->parseTypeToken($candidateType);
        $required  = $this->parseTypeToken($requiredType);

        if ($required['element'] === '*') {
            if ($required['namespace'] === '*') {
                return true;
            }

            if ($required['namespace'] === null) {
                return $candidate['namespace'] === null;
            }

            return $required['namespace'] === $candidate['namespace'];
        }

        if ($candidate['element'] !== $required['element']) {
            return false;
        }

        if ($required['namespace'] === '*') {
            return true;
        }

        return $required['namespace'] === $candidate['namespace'];
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $queue
     * @return array{sel: string, comb: string, lead?: string}|null
     */
    private function firstIfRootish(array &$queue): ?array
    {
        if ($queue === []) {
            return null;
        }

        foreach ($this->tokenizeCompound($queue[0]['sel']) as $token) {
            if (! str_starts_with($token, ':')) {
                continue;
            }

            $name  = substr($token, 1);
            $paren = strpos($name, '(');

            if ($paren !== false) {
                $name = substr($name, 0, $paren);
            }

            if (in_array($name, ['root', 'scope', 'host', 'host-context'], true)) {
                return array_shift($queue);
            }
        }

        return null;
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $components1
     * @param array<int, array{sel: string, comb: string, lead?: string}> $components2
     * @param array<int, array<int, array<int, array{sel: string, comb: string, lead?: string}>>> $result
     * @return array<int, array<int, array<int, array{sel: string, comb: string, lead?: string}>>>|null
     */
    private function mergeTrailingCombinators(array &$components1, array &$components2, array $result = []): ?array
    {
        $combinators1 = $components1 === [] ? '' : $components1[count($components1) - 1]['comb'];
        $combinators2 = $components2 === [] ? '' : $components2[count($components2) - 1]['comb'];

        if ($combinators1 === '' && $combinators2 === '') {
            return $result;
        }

        if ($combinators1 === '~' && $combinators2 === '~') {
            /** @var array{sel: string, comb: string, lead?: string} $component1 */
            $component1 = array_pop($components1);
            /** @var array{sel: string, comb: string, lead?: string} $component2 */
            $component2 = array_pop($components2);

            if ($this->compoundIsSuperselector($component1['sel'], $component2['sel'])) {
                array_unshift($result, [[$component2]]);
            } elseif ($this->compoundIsSuperselector($component2['sel'], $component1['sel'])) {
                array_unshift($result, [[$component1]]);
            } else {
                $choices = [[$component1, $component2], [$component2, $component1]];

                $unified = $this->unifyCompounds($component1['sel'], $component2['sel']);

                if ($unified !== null) {
                    $choices[] = [['sel' => $unified, 'comb' => $combinators1]];
                }

                array_unshift($result, $choices);
            }

            return $this->mergeTrailingCombinators($components1, $components2, $result);
        }

        if (in_array($combinators1, ['>', '+', '~'], true) && $combinators1 === $combinators2) {
            /** @var array{sel: string, comb: string, lead?: string} $component1 */
            $component1 = array_pop($components1);
            /** @var array{sel: string, comb: string, lead?: string} $component2 */
            $component2 = array_pop($components2);

            $unified = $this->unifyCompounds($component1['sel'], $component2['sel']);

            if ($unified === null) {
                return null;
            }

            array_unshift($result, [[['sel' => $unified, 'comb' => $combinators1]]]);

            return $this->mergeTrailingCombinators($components1, $components2, $result);
        }

        if (
            ($combinators1 === '~' && $combinators2 === '+')
            || ($combinators1 === '+' && $combinators2 === '~')
        ) {
            /** @var array{sel: string, comb: string, lead?: string} $next */
            $next      = $combinators1 === '+' ? array_pop($components1) : array_pop($components2);
            /** @var array{sel: string, comb: string, lead?: string} $following */
            $following = $combinators1 === '+' ? array_pop($components2) : array_pop($components1);

            if ($this->compoundIsSuperselector($following['sel'], $next['sel'])) {
                array_unshift($result, [[$next]]);
            } else {
                $choices = [[$following, $next]];
                $unified = $this->unifyCompounds($following['sel'], $next['sel']);

                if ($unified !== null) {
                    $choices[] = [['sel' => $unified, 'comb' => $next['comb']]];
                }

                array_unshift($result, $choices);
            }

            return $this->mergeTrailingCombinators($components1, $components2, $result);
        }

        $siblingSide = null;

        if (in_array($combinators1, ['+', '~'], true) && $combinators2 === '>') {
            $siblingSide = &$components1;
        } elseif (in_array($combinators2, ['+', '~'], true) && $combinators1 === '>') {
            $siblingSide = &$components2;
        }

        if ($siblingSide !== null) {
            /** @var array{sel: string, comb: string, lead?: string} $sibling */
            $sibling = array_pop($siblingSide);

            array_unshift($result, [[$sibling]]);

            return $this->mergeTrailingCombinators($components1, $components2, $result);
        }

        if ($combinators1 !== '' && $combinators2 === '') {
            $combinatorSide = &$components1;
            $descendantSide = &$components2;
        } elseif ($combinators1 === '') {
            $combinatorSide = &$components2;
            $descendantSide = &$components1;
        } else {
            return null;
        }

        if (
            ($combinators1 !== '' ? $combinators1 : $combinators2) === '>'
            && $descendantSide !== []
            && $combinatorSide !== []
            && $this->compoundIsSuperselector(
                $descendantSide[count($descendantSide) - 1]['sel'],
                $combinatorSide[count($combinatorSide) - 1]['sel'],
            )
        ) {
            array_pop($descendantSide);
        }

        /** @var array{sel: string, comb: string, lead?: string} $component */
        $component = array_pop($combinatorSide);

        array_unshift($result, [[$component]]);

        return $this->mergeTrailingCombinators($components1, $components2, $result);
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $components
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    private function groupSelectors(array $components): array
    {
        $groups = [];
        $group  = [];

        foreach ($components as $component) {
            $group[] = $component;

            if ($component['comb'] === '') {
                $groups[] = $group;
                $group    = [];
            }
        }

        if ($group !== []) {
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * @param array<int, Complex> $queue1
     * @param array<int, Complex> $queue2
     * @param callable(array<int, Complex>): bool $done
     * @return array<int, array<int, Complex>>
     */
    private function chunks(array &$queue1, array &$queue2, callable $done): array
    {
        $chunk1 = [];

        while ($queue1 !== [] && ! $done($queue1)) {
            $chunk1[] = array_shift($queue1);
        }

        $chunk2 = [];

        while ($queue2 !== [] && ! $done($queue2)) {
            $chunk2[] = array_shift($queue2);
        }

        if ($chunk1 === [] && $chunk2 === []) {
            return [];
        }

        if ($chunk1 === []) {
            return [$chunk2];
        }

        if ($chunk2 === []) {
            return [$chunk1];
        }

        return [[...$chunk1, ...$chunk2], [...$chunk2, ...$chunk1]];
    }

    /**
     * @param array<int, Complex> $sequence1
     * @param array<int, Complex> $sequence2
     * @param callable(Complex, Complex): ?Complex $select
     * @return array<int, Complex>
     */
    private function longestCommonSubsequence(array $sequence1, array $sequence2, callable $select): array
    {
        $m  = count($sequence1);
        $n  = count($sequence2);
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($select($sequence1[$i - 1], $sequence2[$j - 1]) !== null) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
                }
            }
        }

        $result = [];
        $i      = $m;
        $j      = $n;

        while ($i > 0 && $j > 0) {
            $selected = $select($sequence1[$i - 1], $sequence2[$j - 1]);

            if ($selected !== null) {
                $result[] = $selected;
                $i--;
                $j--;

                continue;
            }

            if ($dp[$i - 1][$j] >= $dp[$i][$j - 1]) {
                $i--;
            } else {
                $j--;
            }
        }

        return array_reverse($result);
    }

    /**
     * @param array<int, array<int, Complex>> $choices
     * @return array<int, array<int, Complex>>
     */
    private function paths(array $choices): array
    {
        $paths = [[]];

        foreach ($choices as $choice) {
            $newPaths = [];

            foreach ($choice as $option) {
                foreach ($paths as $path) {
                    $newPaths[] = [...$path, $option];
                }
            }

            $paths = $newPaths;
        }

        return $paths;
    }

    /**
     * @param Complex $complex1
     * @param Complex $complex2
     */
    private function complexIsParentSuperselector(array $complex1, array $complex2): bool
    {
        if (count($complex1) > count($complex2)) {
            return false;
        }

        $placeholder = ['sel' => '%_weave', 'comb' => ''];

        return $this->complexIsSuperselector(
            [...$complex1, $placeholder],
            [...$complex2, $placeholder],
        );
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $complex1
     * @param array<int, array{sel: string, comb: string, lead?: string}> $complex2
     */
    private function complexIsSuperselector(array $complex1, array $complex2): bool
    {
        if ($complex1 === [] || $complex2 === []) {
            return false;
        }

        if ($complex1[count($complex1) - 1]['comb'] !== '') {
            return false;
        }

        if ($complex2[count($complex2) - 1]['comb'] !== '') {
            return false;
        }

        $i1   = 0;
        $i2   = 0;
        $prev = '';

        while (true) {
            $remaining1 = count($complex1) - $i1;
            $remaining2 = count($complex2) - $i2;

            if ($remaining1 === 0 || $remaining2 === 0) {
                return false;
            }

            if ($remaining1 > $remaining2) {
                return false;
            }

            $component1  = $complex1[$i1];
            $combinator1 = $component1['comb'];

            if ($remaining1 === 1) {
                $last = $complex2[count($complex2) - 1];

                return $this->compoundIsSuperselector($component1['sel'], $last['sel'])
                    && $this->compatibleWithPreviousCombinator(
                        $prev,
                        $i2 < count($complex2) - 1 ? array_slice($complex2, $i2, count($complex2) - 1 - $i2) : [],
                    );
            }

            $end = $i2;

            while (true) {
                $component2 = $complex2[$end];

                if ($this->compoundIsSuperselector($component1['sel'], $component2['sel'])) {
                    break;
                }

                $end++;

                if ($end === count($complex2) - 1) {
                    return false;
                }
            }

            if (! $this->compatibleWithPreviousCombinator(
                $prev,
                array_slice($complex2, $i2, $end - $i2),
            )) {
                return false;
            }

            $combinator2 = $complex2[$end]['comb'];

            if (! $this->isSupercombinator($combinator1, $combinator2)) {
                return false;
            }

            $i1++;

            $i2   = $end + 1;
            $prev = $combinator1;

            if (count($complex1) - $i1 === 1) {
                if ($combinator1 === '~') {
                    foreach (array_slice($complex2, $i2, max(0, count($complex2) - 1 - $i2)) as $component) {
                        if (! $this->isSupercombinator($combinator1, $component['comb'])) {
                            return false;
                        }
                    }
                } elseif ($combinator1 !== '') {
                    if (count($complex2) - $i2 > 1) {
                        return false;
                    }
                }
            }
        }
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $parents
     */
    private function compatibleWithPreviousCombinator(string $previous, array $parents): bool
    {
        if ($parents === []) {
            return true;
        }

        if ($previous === '') {
            return true;
        }

        if ($previous !== '~') {
            return false;
        }

        foreach ($parents as $component) {
            if ($component['comb'] !== '~' && $component['comb'] !== '+') {
                return false;
            }
        }

        return true;
    }

    private function isSupercombinator(string $combinator1, string $combinator2): bool
    {
        return $combinator1 === $combinator2
            || ($combinator1 === '' && $combinator2 === '>')
            || ($combinator1 === '~' && $combinator2 === '+');
    }

    private function compoundIsSuperselector(string $general, string $specific): bool
    {
        return $this->doesCompoundSatisfy($specific, $general);
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $group1
     * @param array<int, array{sel: string, comb: string, lead?: string}> $group2
     */
    /**
     * @param Complex $group1
     * @param Complex $group2
     */
    private function mustUnify(array $group1, array $group2): bool
    {
        $uniqueSelectors = [];

        foreach ($group1 as $component) {
            foreach ($this->tokenizeCompound($component['sel']) as $token) {
                if ($token[0] === '#' || $this->isPseudoElementToken($token)) {
                    $uniqueSelectors[] = $token;
                }
            }
        }

        if ($uniqueSelectors === []) {
            return false;
        }

        foreach ($group2 as $component) {
            foreach ($this->tokenizeCompound($component['sel']) as $token) {
                if (in_array($token, $uniqueSelectors, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param Complex $group1
     * @param Complex $group2
     * @return Complex|null
     */
    private function unifyGroups(array $group1, array $group2): ?array
    {
        if (count($group1) === 1 && count($group2) === 1) {
            $unified = $this->unifyCompounds($group1[0]['sel'], $group2[0]['sel']);

            if ($unified === null) {
                return null;
            }

            $comb = $group1[0]['comb'] !== '' ? $group1[0]['comb'] : $group2[0]['comb'];

            return [['sel' => $unified, 'comb' => $comb]];
        }

        $woven = $this->weave([$group1, $group2]);

        if (count($woven) !== 1) {
            return null;
        }

        return $woven[0];
    }

    private function readNamespacedType(string $compound, int &$index, string $prefix): string
    {
        $token  = $prefix;
        $length = strlen($compound);

        if ($index < $length && $compound[$index] === '|') {
            $token .= '|';

            $index++;
        }

        if ($index < $length) {
            if ($compound[$index] === '*') {
                $token .= '*';

                $index++;
            } else {
                $token .= $this->readIdentifier($compound, $index);
            }
        }

        return $token;
    }
}
