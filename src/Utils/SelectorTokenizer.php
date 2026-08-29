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
use function end;
use function implode;
use function in_array;
use function max;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * @phpstan-type Complex array<int, array{sel: string, comb: string, lead?: string}>
 *
 * @psalm-type Complex=array<int, array{sel: string, comb: string, lead?: string}>
 */
final readonly class SelectorTokenizer
{
    private const SUBSELECTOR_PSEUDO_NAMES = ['is', 'matches', 'where', 'any', 'nth-child', 'nth-last-child'];

    private const SELECTOR_PSEUDO_ARGUMENT_NAMES = [
        'not', 'is', 'matches', 'any', 'where', 'has', 'host', 'host-context', 'slotted', 'current',
    ];

    private const FLATTENABLE_PSEUDO_BASE_NAMES = ['is', 'matches', 'where', 'any', 'current'];

    private const NOT_PSEUDO_BASE_NAME = 'not';

    private const NTH_OF_PSEUDO_BASE_NAMES = ['nth-child', 'nth-last-child'];

    private const PLAIN_INSERT_PSEUDO_BASE_NAMES = ['has', 'host', 'host-context', 'slotted'];

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

            if ($char === '\\') {
                $token = $this->readIdentifier($compound, $index);

                if ($token !== '') {
                    $tokens[] = $token;
                }

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
            if ($this->extractTypeToken($replacementTokens) !== '') {
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
            } else {
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

    /**
     * @template TChoice
     *
     * @param array<int, array<int, TChoice>> $choices
     *
     * @return list<list<TChoice>>
     */
    public function paths(array $choices): array
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

        if ($this->inspectTopLevelCombinators(
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
        )) {
            return true;
        }

        return $this->hasConsecutiveCombinatorsInParentheses($selector);
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

    public function hasBogusSelectorPseudoCombinator(string $selector): bool
    {
        foreach ([':is(', ':matches(', ':where(', ':not('] as $pseudo) {
            $offset = 0;

            while (($start = strpos(strtolower($selector), $pseudo, $offset)) !== false) {
                $end = $start + strlen($pseudo);
                $close = strpos($selector, ')', $end);

                if ($close === false) {
                    break;
                }

                $argument = trim(substr($selector, $end, $close - $end));

                if (
                    str_starts_with($argument, '>')
                    || str_starts_with($argument, '+')
                    || str_starts_with($argument, '~')
                    || $this->hasConsecutiveCombinatorsInParentheses($argument)
                ) {
                    return true;
                }

                $argument = rtrim($argument);

                if (str_ends_with($argument, '>') || str_ends_with($argument, '+') || str_ends_with($argument, '~')) {
                    return true;
                }

                $offset = $close + 1;
            }
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

    public function canonicalizeSelectorEscapes(string $selector): string
    {
        if (! str_contains($selector, '\\')) {
            return $selector;
        }

        $length  = strlen($selector);
        $result  = '';
        $index   = 0;
        $inIdent = false;

        while ($index < $length) {
            $char = $selector[$index];

            if ($char === '\\') {
                [$decoded, $nextIndex] = $this->decodeSelectorEscape($selector, $index);

                if ($decoded !== '') {
                    $result .= $this->encodeCanonicalIdentifierChar($decoded, $inIdent);
                }

                $index   = $nextIndex;
                $inIdent = true;

                continue;
            }

            if ($char === '[') {
                $end = $this->skipVerbatimRegion($selector, $index, '[', ']');

                $result .= substr($selector, $index, $end - $index);
                $index   = $end;
                $inIdent = false;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $end = StringEscapeDecoder::skipQuotedChunk($selector, $index);

                $result .= substr($selector, $index, $end - $index);
                $index   = $end;
                $inIdent = false;

                continue;
            }

            if ($char === '#' && ($selector[$index + 1] ?? '') === '{') {
                $end = StringEscapeDecoder::skipInterpolation($selector, $index + 1);

                $result .= substr($selector, $index, $end - $index);
                $index   = $end;
                $inIdent = true;

                continue;
            }

            $result .= $char;
            $inIdent = $this->isIdentifierBodyChar($char);

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
     * @param array<int, string> $targets single-compound target selectors
     * @param array<int, string> $extenders complex selectors
     * @return array<int, string>
     */
    public function extendSelectorPartByTargets(
        string $part,
        array $targets,
        array $extenders,
        bool $allowGuard = true,
    ): array {
        if ($this->hasBogusTopLevelCombinatorSequence($part)) {
            return [];
        }

        $variants      = [];
        $pseudoHandled = false;

        foreach ($extenders as $extender) {
            $pseudoExtenders = $pseudoHandled ? null : $extenders;

            foreach ($this->extendSelectorPartByExtender($part, $targets, $extender, $allowGuard, $pseudoExtenders, $pseudoHandled) as $variant) {
                if (! in_array($variant, $variants, true)) {
                    $variants[] = $variant;
                }
            }

            $pseudoHandled = true;
        }

        return $variants;
    }

    /**
     * @param array<int, string> $targets single-compound target selectors
     * @param array<int, string> $extenders complex selectors
     * @return array{0: array<int, string>, 1: bool}
     */
    public function extendSelectorPartByTargetsWithFlag(
        string $part,
        array $targets,
        array $extenders,
    ): array {
        $allVariants = $this->extendSelectorPartByTargets($part, $targets, $extenders, true);

        $partComponents = $this->parseComplexComponents($part);
        $hasPseudo      = false;

        if ($partComponents !== []) {
            $compoundTokens = $this->tokenizeCompound($this->normalizeCompoundPseudoTokens($partComponents[0]['sel']));

            foreach ($compoundTokens as $token) {
                $pseudo = $this->parsePseudoToken($token);

                if ($pseudo !== null && $pseudo['selector'] !== null) {
                    $baseName = $this->pseudoBaseName($pseudo['name']);

                    if (in_array($baseName, self::FLATTENABLE_PSEUDO_BASE_NAMES, true)
                        || $baseName === self::NOT_PSEUDO_BASE_NAME
                        || in_array($baseName, self::NTH_OF_PSEUDO_BASE_NAMES, true)
                        || in_array($baseName, self::PLAIN_INSERT_PSEUDO_BASE_NAMES, true)
                    ) {
                        $hasPseudo = true;

                        break;
                    }
                }
            }
        }

        return [$allVariants, $hasPseudo];
    }

    public function normalizeExtendPart(string $part): string
    {
        $components = $this->parseComplexComponents($part);

        if ($components === []) {
            return trim($part);
        }

        foreach ($components as $index => $component) {
            $components[$index]['sel'] = $this->normalizeCompoundPseudoTokens($component['sel']);
        }

        return $this->complexComponentsToString($components);
    }

    /**
     * @param array<int, string> $variants
     * @return array<int, string>
     */
    public function trimExtendedVariants(array $variants): array
    {
        $parsed = [];

        foreach ($variants as $index => $variant) {
            $complexes = $this->parseSelectorList($variant);

            if (count($complexes) === 1) {
                $parsed[$index] = $complexes[0];
            }
        }

        $trimmed = [];

        foreach ($variants as $index => $variant) {
            $isRedundant = false;

            if (isset($parsed[$index])) {
                foreach ($parsed as $otherIndex => $other) {
                    if ($otherIndex === $index) {
                        continue;
                    }

                    if ($this->complexesAreSuperselector($other, $parsed[$index])) {
                        $isRedundant = true;

                        break;
                    }
                }
            }

            if (! $isRedundant) {
                $trimmed[$index] = $variant;
            }
        }

        return array_values($trimmed);
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

    /**
     * @param array<int, string> $complexes
     * @return array<int, string>
     */
    public function replaceSelectorTargetInComplexes(array $complexes, string $target, string $source): array
    {
        $resolved = [];

        foreach ($complexes as $complex) {
            foreach ($this->replaceSelectorTargetInComplex($complex, $target, $source) as $variant) {
                if (! in_array($variant, $resolved, true)) {
                    $resolved[] = $variant;
                }
            }
        }

        return $resolved;
    }

    public function isPseudoElementToken(string $token): bool
    {
        return str_starts_with($token, '::')
            || in_array($token, [':before', ':after', ':first-line', ':first-letter'], true);
    }

    public function textContainsParentSelector(string $text): bool
    {
        $length     = strlen($text);
        $quote      = '';
        $skipDepth  = 0;
        $index      = 0;

        while ($index < $length) {
            $char = $text[$index];

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

            if ($skipDepth > 0) {
                if ($char === '(') {
                    $skipDepth++;
                } elseif ($char === ')') {
                    $skipDepth--;
                }

                $index++;

                continue;
            }

            if ($char === '&') {
                return true;
            }

            if ($char === ':') {
                $nameStart = $index + 1;

                if ($nameStart < $length && $text[$nameStart] === ':') {
                    $nameStart++;
                }

                $nameEnd = $nameStart;

                while (
                    $nameEnd < $length
                    && (ctype_alnum($text[$nameEnd])
                        || $text[$nameEnd] === '-'
                        || $text[$nameEnd] === '_')
                ) {
                    $nameEnd++;
                }

                $name = strtolower(substr($text, $nameStart, $nameEnd - $nameStart));

                if (
                    $nameEnd < $length
                    && $text[$nameEnd] === '('
                    && ! in_array($name, self::SELECTOR_PSEUDO_ARGUMENT_NAMES, true)
                    && $name !== 'nth-child'
                    && $name !== 'nth-last-child'
                ) {
                    $skipDepth = 1;
                    $index     = $nameEnd + 1;

                    continue;
                }

                $index = $nameEnd;

                continue;
            }

            $index++;
        }

        return false;
    }

    /**
     * @param array<int, string> $splitChars
     * @return array<int, string>
     */
    public function splitAtTopLevel(
        string $selector,
        array $splitChars,
        bool $handleQuotes = false,
        bool $trim = true,
    ): array {
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
                $buffer  = '';
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
        $leadCombs  = [];
        $index      = 0;

        while ($index < $count && in_array($items[$index], ['>', '+', '~'], true)) {
            $leadCombs[] = $items[$index];

            $index++;
        }

        $leading = implode(' ', $leadCombs);

        if ($index === $count && $leading !== '') {
            return [['sel' => '', 'comb' => '', 'lead' => $leading]];
        }

        while ($index < $count) {
            $sel = $items[$index];

            $index++;

            $combs = [];

            while ($index < $count && in_array($items[$index], ['>', '+', '~'], true)) {
                $combs[] = $items[$index];

                $index++;
            }

            $component = ['sel' => $sel, 'comb' => implode(' ', $combs)];

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
        $pieces = [];

        foreach ($components as $i => $component) {
            $lead = $component['lead'] ?? '';

            if ($i === 0 && $lead !== '') {
                foreach (explode(' ', $lead) as $piece) {
                    if ($piece !== '') {
                        $pieces[] = $piece;
                    }
                }
            }

            if ($component['sel'] !== '') {
                $pieces[] = $component['sel'];
            }

            if ($component['comb'] !== '') {
                foreach (explode(' ', $component['comb']) as $piece) {
                    if ($piece !== '') {
                        $pieces[] = $piece;
                    }
                }
            }
        }

        return implode(' ', $pieces);
    }

    /**
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>
     */
    public function parseSelectorList(string $selector): array
    {
        $complexes = [];

        foreach ($this->splitAtTopLevel($selector, [','], true) as $part) {
            $components = $this->parseComplexComponents($part);

            if ($components !== []) {
                $complexes[] = $components;
            }
        }

        return $complexes;
    }

    /**
     * @return array{name: string, argument: string, selector: ?string, isElement: bool}|null
     */
    public function parsePseudoToken(string $token): ?array
    {
        if ($token === '' || $token[0] !== ':') {
            return null;
        }

        $body      = substr($token, 1);
        $isElement = false;

        if (str_starts_with($body, ':')) {
            $isElement = true;
            $body      = substr($body, 1);
        }

        $parenStart = strpos($body, '(');

        if ($parenStart === false) {
            return [
                'name'      => $body,
                'argument'  => '',
                'selector'  => null,
                'isElement' => $isElement || $this->isPseudoElementToken($token),
            ];
        }

        $name     = substr($body, 0, $parenStart);
        $argument = substr($body, $parenStart + 1, -1);
        $lowered  = $this->pseudoBaseName($name);

        [$nthPart, $ofSelector] = $this->splitNthOfSelector($lowered, $argument);

        if ($ofSelector !== null) {
            $selector = $ofSelector;
        } elseif (in_array($lowered, self::SELECTOR_PSEUDO_ARGUMENT_NAMES, true)) {
            $selector = trim($argument);
        } else {
            $selector = null;
        }

        return [
            'name'      => $name,
            'argument'  => $nthPart,
            'selector'  => $selector,
            'isElement' => $isElement || $this->isPseudoElementToken($token),
        ];
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $complexes
     */
    public function complexesToString(array $complexes): string
    {
        $parts = [];

        foreach ($complexes as $components) {
            $parts[] = $this->complexComponentsToString($components);
        }

        return implode(', ', $parts);
    }

    /**
     * @param Complex $complex1
     * @param Complex $complex2
     */
    public function complexesAreSuperselector(array $complex1, array $complex2): bool
    {
        if (($complex1[0]['lead'] ?? '') !== '' || ($complex2[0]['lead'] ?? '') !== '') {
            return false;
        }

        return $this->complexIsSuperselector($complex1, $complex2, true);
    }

    /**
     * @param array<int, Complex> $superComplexes
     * @param array<int, Complex> $subComplexes
     */
    public function listsAreSuperselectors(array $superComplexes, array $subComplexes): bool
    {
        foreach ($subComplexes as $subComplex) {
            $matched = false;

            foreach ($superComplexes as $superComplex) {
                if ($this->complexesAreSuperselector($superComplex, $subComplex)) {
                    $matched = true;

                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Complex $left
     * @param Complex $right
     * @return array<int, array<int, array{sel: string, comb: string, lead?: string}>>|null
     */
    public function unifyComplexes(array $left, array $right): ?array
    {
        $leftLead  = $left[0]['lead'] ?? '';
        $rightLead = $right[0]['lead'] ?? '';

        if ($leftLead !== '' && $rightLead !== '' && $leftLead !== $rightLead) {
            return null;
        }

        $lead      = $leftLead !== '' ? $leftLead : $rightLead;
        $leftLast  = $left[count($left) - 1];
        $rightLast = $right[count($right) - 1];
        $trailing  = $leftLast['comb'];

        if ($rightLast['comb'] !== '') {
            if ($trailing !== '' && $trailing !== $rightLast['comb']) {
                return null;
            }

            $trailing = $rightLast['comb'];
        }

        $unifiedBase = $this->unifyCompoundsStrict($leftLast['sel'], $rightLast['sel']);

        if ($unifiedBase === null) {
            return null;
        }

        $base         = [['sel' => $unifiedBase, 'comb' => $trailing]];
        $withoutBases = [];

        if (count($left) > 1) {
            $withoutBases[] = array_slice($left, 0, -1);
        }

        if (count($right) > 1) {
            $withoutBases[] = array_slice($right, 0, -1);
        }

        if ($withoutBases === []) {
            $woven = [$base];
        } else {
            $lastPrefix = array_pop($withoutBases);
            $lastPrefix = [...$lastPrefix, ...$base];

            $woven = $this->weave([...$withoutBases, $lastPrefix]);
        }

        if ($lead === '') {
            return $woven;
        }

        foreach ($woven as $index => $complex) {
            if ($complex === []) {
                continue;
            }

            $first         = $complex[0];
            $woven[$index] = [
                ['sel' => $first['sel'], 'comb' => $first['comb'], 'lead' => $lead],
                ...array_slice($complex, 1),
            ];
        }

        return $woven;
    }

    public function unifyCompoundsStrict(string $left, string $right): ?string
    {
        $result             = $this->tokenizeCompound($left);
        $pseudoResult       = [];
        $pseudoElementFound = false;

        foreach ($this->tokenizeCompound($right) as $simple) {
            if ($pseudoElementFound && $this->parsePseudoToken($simple) !== null) {
                $unified = $this->strictUnifySimple($simple, $pseudoResult);

                if ($unified === null) {
                    return null;
                }

                $pseudoResult = $unified;

                continue;
            }

            if ($this->isPseudoElementToken($simple)) {
                $pseudoElementFound = true;
            }

            $unified = $this->strictUnifySimple($simple, $result);

            if ($unified === null) {
                return null;
            }

            $result = $unified;
        }

        return implode('', [...$result, ...$pseudoResult]);
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $complexes
     * @return list<array<int, array{sel: string, comb: string, lead?: string}>>
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
                /** @param array<int, Complex> $queue */
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

        $baseLead = $base === [] ? '' : ($base[0]['lead'] ?? '');

        if ($baseLead !== '') {
            foreach ($result as &$complex) {
                if ($complex !== [] && ($complex[0]['lead'] ?? '') === '') {
                    $complex[0]['lead'] = $baseLead;
                }
            }

            unset($complex);
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function replaceSelectorTargetInComplex(string $complex, string $target, string $source): array
    {
        if (
            $this->hasUnsupportedTopLevelCombinator($complex)
            || $this->hasUnsupportedTopLevelCombinator($target)
            || $this->hasUnsupportedTopLevelCombinator($source)
        ) {
            return [$complex];
        }

        $targetTokens = $this->tokenizeCompound($target);

        if ($targetTokens === []) {
            return [$complex];
        }

        $compounds = $this->splitAtTopLevel($complex, [' ', '>', '+', '~']);

        if ($compounds === []) {
            return [$complex];
        }

        $resolved = [];

        foreach ($this->splitAtTopLevel($source, [',']) as $sourcePart) {
            foreach ($this->replaceSelectorTargetWithSource($compounds, $targetTokens, $target, $source, $sourcePart) as $variant) {
                if (! in_array($variant, $resolved, true)) {
                    $resolved[] = $variant;
                }
            }
        }

        if ($resolved === []) {
            return [$complex];
        }

        return $resolved;
    }

    /**
     * @param array<int, string> $compounds
     * @param array<int, string> $targetTokens
     * @return array<int, string>
     */
    private function replaceSelectorTargetWithSource(
        array $compounds,
        array $targetTokens,
        string $target,
        string $source,
        string $sourcePart,
    ): array {
        $sourceCompounds      = $this->splitAtTopLevel($sourcePart, [' ', '>', '+', '~']);
        $replacementSubject   = $sourceCompounds === [] ? '' : $sourceCompounds[count($sourceCompounds) - 1];
        $replacementAncestors = $sourceCompounds === [] ? [] : array_slice($sourceCompounds, 0, -1);

        $resolved = [];
        $changed  = false;

        for ($index = 0; $index < count($compounds); $index++) {
            $remainingCompound = $this->removeTokensFromCompound($compounds[$index], $targetTokens);

            $unifiedSubject = null;

            if ($remainingCompound !== null) {
                $unifiedSubject = $replacementAncestors === []
                    ? $this->replaceTokensInCompound($compounds[$index], $targetTokens, $replacementSubject)
                    : $this->unifyCompounds($replacementSubject, $remainingCompound);
            } elseif ($replacementAncestors === []) {
                $pseudoVariant = $this->replaceTargetInsidePseudoToken($compounds[$index], $target, $source);

                if ($pseudoVariant !== null) {
                    $unifiedSubject = $pseudoVariant;
                }
            }

            if ($unifiedSubject === null) {
                continue;
            }

            $changed = true;
            $prefix  = array_slice($compounds, 0, $index);
            $suffix  = array_slice($compounds, $index + 1);

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
                $candidate     = [...$prefixVariant, $unifiedSubject, ...$suffix];
                $candidateText = implode(' ', $candidate);

                if (! in_array($candidateText, $resolved, true)) {
                    $resolved[] = $candidateText;
                }
            }
        }

        if (! $changed) {
            return [implode(' ', $compounds)];
        }

        return $resolved;
    }

    private function replaceTargetInsidePseudoToken(string $token, string $target, string $source): ?string
    {
        $pseudo = $this->parsePseudoToken($token);

        if ($pseudo === null || $pseudo['selector'] === null) {
            return null;
        }

        $argumentComplexes = array_map(
            fn(array $complex): string => $this->complexesToString([$complex]),
            $this->parseSelectorList($pseudo['selector']),
        );

        $replaced = $this->replaceSelectorTargetInComplexes($argumentComplexes, $target, $source);

        if ($replaced === [] || $replaced === $argumentComplexes) {
            return null;
        }

        $newArgument = implode(', ', $replaced);
        $loweredName = strtolower($pseudo['name']);

        if ($loweredName === 'nth-child' || $loweredName === 'nth-last-child') {
            $newArgument = $pseudo['argument'] . ' of ' . $newArgument;
        }

        $prefix = str_starts_with($token, '::') ? '::' : ':';

        return $prefix . $pseudo['name'] . '(' . $newArgument . ')';
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

        while ($index < $length) {
            $char = $input[$index];

            if ($this->isIdentifierChar($char)) {
                $identifier .= $char;

                $index++;

                continue;
            }

            if ($char === '\\') {
                $escape = $this->readEscapeSequence($input, $index);

                if ($escape === null) {
                    break;
                }

                $identifier .= $escape;

                continue;
            }

            break;
        }

        return $identifier;
    }

    private function readEscapeSequence(string $input, int &$index): ?string
    {
        $length = strlen($input);

        if ($index + 1 >= $length) {
            return null;
        }

        $next = $input[$index + 1];

        if (! ctype_xdigit($next)) {
            $escape = substr($input, $index, 2);

            $index += 2;

            return $escape;
        }

        $cursor = $index + 1;
        $hex    = '';

        while ($cursor < $length && strlen($hex) < 6 && ctype_xdigit($input[$cursor])) {
            $hex .= $input[$cursor];

            $cursor++;
        }

        if ($cursor < $length && ($input[$cursor] === ' ' || $input[$cursor] === "\t")) {
            $cursor++;
        }

        $escape = substr($input, $index, $cursor - $index);
        $index  = $cursor;

        return $escape;
    }

    /**
     * @param array<int, string> $targets single-compound target selectors
     * @param array<int, string>|null $allExtenders  all extenders being applied
     * @return array<int, string>
     */
    private function extendSelectorPartByExtender(
        string $part,
        array $targets,
        string $extender,
        bool $allowGuard = true,
        ?array $allExtenders = null,
        bool $skipPseudo = false,
    ): array {
        $extenderComplexes = $this->parseSelectorList($extender);

        if ($extenderComplexes === []) {
            return [];
        }

        $extenderLead = $extenderComplexes[0][0]['lead'] ?? '';

        $isCombinatorOnly = true;

        foreach ($extenderComplexes[0] as $component) {
            if ($component['sel'] !== '') {
                $isCombinatorOnly = false;

                break;
            }
        }

        if ($isCombinatorOnly) {
            $results = $this->extendByCombinatorOnlyExtender($part, $targets, $extenderComplexes);

            if ($results !== []) {
                $normalized = $this->normalizeExtendPart($part);

                if (! in_array($normalized, $results, true)) {
                    array_unshift($results, $normalized);
                }
            }

            return $results;
        }

        if ($this->hasBogusTopLevelCombinatorSequence($extender)) {
            return [];
        }

        $guardedTargets   = [];
        $unguardedTargets = [];

        foreach ($targets as $target) {
            $targetComplexes = $this->parseSelectorList($target);

            if ($targetComplexes === [] || count($targetComplexes[0]) !== 1) {
                continue;
            }

            $targetComponent = $targetComplexes[0][0];

            if (
                $targetComponent['sel'] === ''
                || $targetComponent['comb'] !== ''
                || ($targetComponent['lead'] ?? '') !== ''
            ) {
                continue;
            }

            $tokens = $this->tokenizeCompound($this->normalizeCompoundPseudoTokens($targetComponent['sel']));

            if ($tokens === []) {
                continue;
            }

            if (! $allowGuard || ! $this->complexesAreSuperselector($targetComplexes[0], $extenderComplexes[0])) {
                $guardedTargets[] = $tokens;
            }

            $unguardedTargets[] = $tokens;
        }

        $partComponents = $this->parseComplexComponents($part);

        if ($partComponents === []) {
            return [];
        }

        $partLead = $partComponents[0]['lead'] ?? '';

        if ($partLead !== '' && $extenderLead !== '' && $partLead !== $extenderLead) {
            return [];
        }

        $lead              = $partLead !== '' ? $partLead : $extenderLead;
        $lastExtenderIndex = count($extenderComplexes[0]) - 1;
        $extenderSubject   = $extenderComplexes[0][$lastExtenderIndex]['sel'];
        $extenderTrailing  = $extenderComplexes[0][$lastExtenderIndex]['comb'];
        $extenderAncestors = array_slice($extenderComplexes[0], 0, -1);

        if ($extenderLead !== '' && in_array($extenderLead, ['>', '+', '~'], true)) {
            array_unshift($extenderAncestors, ['sel' => '', 'comb' => $extenderLead]);

            if ($lead === $extenderLead) {
                $lead = '';
            }
        }

        if ($extenderSubject === '') {
            return [];
        }

        $extenders        = $allExtenders ?? [$extender];
        $results          = [];
        $hasPseudoChanges = false;

        foreach ($partComponents as $index => $component) {
            $compoundSel = $this->normalizeCompoundPseudoTokens($component['sel']);
            $remainders  = [$compoundSel];

            foreach ($guardedTargets as $targetTokens) {
                foreach ($remainders as $remainder) {
                    $next = $this->removeTokensFromCompound($remainder, $targetTokens);

                    if ($next !== null && ! in_array($next, $remainders, true)) {
                        $remainders[] = $next;
                    }
                }
            }

            foreach (array_slice($remainders, 1) as $remainder) {
                foreach (
                    $this->buildExtensionVariants(
                        $partComponents,
                        $index,
                        $remainder,
                        '',
                        $extenderSubject,
                        $extenderTrailing,
                        $extenderAncestors,
                        $lead,
                    ) as $variant
                ) {
                    if (! in_array($variant, $results, true)) {
                        $results[] = $variant;
                    }
                }
            }

            if ($unguardedTargets !== [] && ! $skipPseudo) {
                foreach (
                    $this->extendCompoundPseudoArguments(
                        $partComponents,
                        $index,
                        $unguardedTargets,
                        $targets,
                        $extenders,
                        $lead,
                    ) as $pseudoVariant
                ) {
                    $hasPseudoChanges = true;

                    if (! in_array($pseudoVariant, $results, true)) {
                        $results[] = $pseudoVariant;
                    }
                }
            }
        }

        if ($allowGuard && ! $hasPseudoChanges && ! $skipPseudo) {
            $normalized = $this->normalizeExtendPart($part);

            if (! in_array($normalized, $results, true)) {
                array_unshift($results, $normalized);
            }
        }

        return $results;
    }

    /**
     * @param Complex $partComponents
     * @param Complex $extenderAncestors
     * @return array<int, string>
     */
    private function buildExtensionVariants(
        array $partComponents,
        int $index,
        string $remainder,
        string $replacementCompound,
        string $extenderSubject,
        string $extenderTrailing,
        array $extenderAncestors,
        string $lead,
    ): array {
        if ($replacementCompound === '') {
            $base = $remainder === ''
                ? $extenderSubject
                : $this->unifyCompoundsStrict($remainder, $extenderSubject);

            if ($base === null || $base === '') {
                return [];
            }
        } else {
            $base = $replacementCompound;
        }

        $trailingCombinator = $this->mergeExtendCombinators($partComponents[$index]['comb'], $extenderTrailing);

        if ($trailingCombinator === null) {
            return [];
        }

        $prefix        = array_slice($partComponents, 0, $index);
        $suffix        = array_slice($partComponents, $index + 1);
        $baseComponent = ['sel' => $base, 'comb' => $trailingCombinator];

        $variants = [];

        foreach ($this->weavePrefixWithAncestors($prefix, $extenderAncestors) as $body) {
            $assembled = [...$body, $baseComponent, ...$suffix];

            if ($lead !== '') {
                $assembled[0] = ['sel' => $assembled[0]['sel'], 'comb' => $assembled[0]['comb'], 'lead' => $lead];
            }

            $variants[] = $this->complexComponentsToString($assembled);
        }

        return $variants;
    }

    /**
     * @param Complex $partComponents
     * @param array<int, array<int, string>> $unguardedTargets target token sets
     * @param array<int, string> $targets raw target strings
     * @param array<int, string> $extenders
     * @return array<int, string>
     */
    private function extendCompoundPseudoArguments(
        array $partComponents,
        int $index,
        array $unguardedTargets,
        array $targets,
        array $extenders,
        string $lead,
    ): array {
        $compoundTokens = $this->tokenizeCompound($partComponents[$index]['sel']);
        $variants       = [];

        foreach ($compoundTokens as $tokenIndex => $token) {
            $pseudo = $this->parsePseudoToken($token);

            if ($pseudo === null || $pseudo['selector'] === null) {
                continue;
            }

            $baseName = $this->pseudoBaseName($pseudo['name']);

            if ($baseName === self::NOT_PSEUDO_BASE_NAME) {
                $replacement = $this->extendedNotPseudoToken($pseudo, $targets, $extenders, $compoundTokens, $tokenIndex);

                if ($replacement === null) {
                    continue;
                }

                $modifiedCompounds = [$replacement];
            } elseif (
                in_array($baseName, self::FLATTENABLE_PSEUDO_BASE_NAMES, true)
                || in_array($baseName, self::PLAIN_INSERT_PSEUDO_BASE_NAMES, true)
                || in_array($baseName, self::NTH_OF_PSEUDO_BASE_NAMES, true)
            ) {
                $replacement = $this->extendedSelectorPseudoToken($pseudo, $baseName, $unguardedTargets, $extenders);

                if ($replacement === null) {
                    continue;
                }

                $tokensCopy           = $compoundTokens;
                $tokensCopy[$tokenIndex] = $replacement;
                $modifiedCompounds    = [implode('', $tokensCopy)];
            } else {
                continue;
            }

            foreach ($modifiedCompounds as $modifiedCompound) {
                foreach (
                    $this->buildExtensionVariants(
                        $partComponents,
                        $index,
                        '',
                        $modifiedCompound,
                        '',
                        '',
                        [],
                        $lead,
                    ) as $variant
                ) {
                    if (! in_array($variant, $variants, true)) {
                        $variants[] = $variant;
                    }
                }
            }
        }

        return $variants;
    }

    /**
     * @param array{name: string, argument: string, selector: ?string, isElement: bool} $pseudo
     * @param array<int, array<int, string>> $targetTokenSets
     * @param array<int, string> $extenders
     */
    private function extendedSelectorPseudoToken(
        array $pseudo,
        string $baseName,
        array $targetTokenSets,
        array $extenders,
    ): ?string {
        $argComplexes = $this->parseSelectorList((string) $pseudo['selector']);

        if ($argComplexes === []) {
            return null;
        }

        $isNthOf   = in_array($baseName, self::NTH_OF_PSEUDO_BASE_NAMES, true);
        $normalizedPrefix = $this->normalizeAnB($pseudo['argument']);
        $inserted  = [];

        foreach ($extenders as $extender) {
            $extenderComplexes = $this->parseSelectorList($extender);

            if ($extenderComplexes === []) {
                continue;
            }

            $first = $extenderComplexes[0];

            if ($this->isFamilySinglePseudoComplex($first, self::NTH_OF_PSEUDO_BASE_NAMES)) {
                $nestedPseudo = $this->parsePseudoToken($this->tokenizeCompound($first[0]['sel'])[0]);

                if (
                    $nestedPseudo === null
                    || $this->pseudoBaseName($nestedPseudo['name']) !== $baseName
                    || $this->normalizeAnB($nestedPseudo['argument']) !== $normalizedPrefix
                    || $nestedPseudo['selector'] === null
                ) {
                    continue;
                }

                foreach ($this->parseSelectorList($nestedPseudo['selector']) as $inner) {
                    $inserted[] = $inner;
                }

                continue;
            }

            if (! $isNthOf && $this->isFamilySinglePseudoComplex($first, self::FLATTENABLE_PSEUDO_BASE_NAMES)) {
                $nestedPseudo = $this->parsePseudoToken($this->tokenizeCompound($first[0]['sel'])[0]);

                if ($nestedPseudo !== null && $nestedPseudo['selector'] !== null) {
                    if (strtolower($nestedPseudo['name']) === strtolower($pseudo['name'])) {
                        foreach ($this->parseSelectorList($nestedPseudo['selector']) as $inner) {
                            $inserted[] = $inner;
                        }

                        continue;
                    }

                    continue;
                }
            }

            if ($this->complexContainsNot($first)) {
                continue;
            }

            foreach ($extenderComplexes as $complex) {
                $inserted[] = $complex;
            }
        }

        if ($inserted === []) {
            return null;
        }

        $newList  = [];
        $extended = false;

        foreach ($argComplexes as $complex) {
            if ($this->isFamilySinglePseudoComplex($complex, self::FLATTENABLE_PSEUDO_BASE_NAMES)) {
                $nestedPseudo = $this->parsePseudoToken($this->tokenizeCompound($complex[0]['sel'])[0]);
                $inner = $nestedPseudo !== null && $nestedPseudo['selector'] !== null
                    ? $this->parseSelectorList($nestedPseudo['selector'])
                    : [];

                if ($inner !== [] && $this->anyComplexMatchesTarget($inner, $targetTokenSets)) {
                    foreach ([...$inner, ...$inserted] as $piece) {
                        $newList[] = $piece;
                    }

                    $extended = true;

                    continue;
                }

                $newList[] = $complex;

                continue;
            }

            $newList[] = $complex;

            if ($this->complexMatchesAnyTarget($complex, $targetTokenSets)) {
                foreach ($inserted as $piece) {
                    $newList[] = $piece;
                }

                $extended = true;
            }
        }

        if (! $extended) {
            return null;
        }

        $argument = $isNthOf
            ? $normalizedPrefix . ' of ' . $this->complexesToString($newList)
            : $this->complexesToString($newList);

        return ($pseudo['isElement'] ? '::' : ':') . $pseudo['name'] . '(' . $argument . ')';
    }

    /**
     * @param array{name: string, argument: string, selector: ?string, isElement: bool} $pseudo
     * @param array<int, string> $targets
     * @param array<int, string> $extenders
     * @param array<int, string> $compoundTokens
     * @return string|null new token text replacing the original one
     */
    private function extendedNotPseudoToken(
        array $pseudo,
        array $targets,
        array $extenders,
        array $compoundTokens,
        int $tokenIndex,
    ): ?string {
        $argComplexes = $this->parseSelectorList((string) $pseudo['selector']);

        if ($argComplexes === []) {
            return null;
        }

        if (count($argComplexes) > 1) {
            return $this->extendedNotPseudoInList($pseudo, $argComplexes, $targets, $extenders);
        }

        $extras = $this->notSiblingTokens($argComplexes[0], $targets, $extenders);

        if ($extras === []) {
            return null;
        }

        $tokensCopy             = $compoundTokens;
        $tokensCopy[$tokenIndex] .= implode('', $extras);

        return implode('', $tokensCopy);
    }

    /**
     * @param array{name: string, argument: string, selector: ?string, isElement: bool} $pseudo
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>>  $argComplexes
     * @param array<int, string> $targets
     * @param array<int, string> $extenders
     */
    private function extendedNotPseudoInList(
        array $pseudo,
        array $argComplexes,
        array $targets,
        array $extenders,
    ): ?string {
        $targetTokenSets = [];
        $targetStrings   = [];

        foreach ($targets as $target) {
            $targetComplexes = $this->parseSelectorList($target);

            if ($targetComplexes === [] || count($targetComplexes[0]) !== 1) {
                continue;
            }

            $component = $targetComplexes[0][0];

            if ($component['sel'] === '' || $component['comb'] !== '' || ($component['lead'] ?? '') !== '') {
                continue;
            }

            $tokens = $this->tokenizeCompound($this->normalizeCompoundPseudoTokens($component['sel']));

            if ($tokens !== []) {
                $targetTokenSets[] = $tokens;
                $targetStrings[]   = $target;
            }
        }

        $inserted = $this->notInsertableComplexes($extenders);

        if ($inserted === []) {
            return null;
        }

        $newList = [];

        foreach ($argComplexes as $complex) {
            $newList[] = $complex;

            if ($this->complexMatchesAnyTarget($complex, $targetTokenSets)) {
                foreach ($inserted as $piece) {
                    $newList[] = $piece;
                }
            }
        }

        if ($newList === $argComplexes) {
            return null;
        }

        return ($pseudo['isElement'] ? '::' : ':') . $pseudo['name'] . '(' . $this->complexesToString($newList) . ')';
    }

    /**
     * @param Complex $argComplex
     * @param array<int, string> $targets
     * @param array<int, string> $extenders
     * @return array<int, string>
     */
    private function notSiblingTokens(array $argComplex, array $targets, array $extenders): array
    {
        $argText = $this->complexComponentsToString($argComplex);
        $extras  = [];

        foreach ($extenders as $extender) {
            $extenderComplexes = $this->parseSelectorList($extender);

            if ($extenderComplexes === []) {
                continue;
            }

            $first = $extenderComplexes[0];

            if ($this->isFamilySinglePseudoComplex($first, self::FLATTENABLE_PSEUDO_BASE_NAMES)) {
                $nestedPseudo = $this->parsePseudoToken($this->tokenizeCompound($first[0]['sel'])[0]);

                if ($nestedPseudo !== null && $nestedPseudo['selector'] !== null) {
                    foreach ($this->parseSelectorList($nestedPseudo['selector']) as $inner) {
                        $extras[] = ':not(' . $this->complexComponentsToString($inner) . ')';
                    }

                    continue;
                }
            }

            if ($this->complexContainsNot($first)) {
                continue;
            }

            foreach (
                $this->extendSelectorPartByTargets($argText, $targets, [$extender], false) as $variant
            ) {
                $extras[] = ':not(' . $variant . ')';
            }
        }

        return array_values(array_unique($extras));
    }

    /**
     * @param array<int, string> $extenders
     * @return array<int, Complex>
     */
    private function notInsertableComplexes(array $extenders): array
    {
        $inserted = [];

        foreach ($extenders as $extender) {
            $extenderComplexes = $this->parseSelectorList($extender);

            if ($extenderComplexes === []) {
                continue;
            }

            $first = $extenderComplexes[0];

            if ($this->isFamilySinglePseudoComplex($first, self::FLATTENABLE_PSEUDO_BASE_NAMES)) {
                $nestedPseudo = $this->parsePseudoToken($this->tokenizeCompound($first[0]['sel'])[0]);

                if ($nestedPseudo !== null && $nestedPseudo['selector'] !== null) {
                    foreach ($this->parseSelectorList($nestedPseudo['selector']) as $inner) {
                        $inserted[] = $inner;
                    }

                    continue;
                }
            }

            if ($this->complexContainsNot($first)) {
                continue;
            }

            foreach ($extenderComplexes as $complex) {
                $inserted[] = $complex;
            }
        }

        return $inserted;
    }

    /**
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $complexes
     * @param array<int, array<int, string>> $targetTokenSets
     */
    private function anyComplexMatchesTarget(array $complexes, array $targetTokenSets): bool
    {
        foreach ($complexes as $complex) {
            if ($this->complexMatchesAnyTarget($complex, $targetTokenSets)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $components
     * @param array<int, array<int, string>> $targetTokenSets
     */
    private function complexMatchesAnyTarget(array $components, array $targetTokenSets): bool
    {
        if ($targetTokenSets === []) {
            return false;
        }

        foreach ($components as $component) {
            foreach ($targetTokenSets as $tokens) {
                if ($this->removeTokensFromCompound($component['sel'], $tokens) !== null) {
                    return true;
                }
            }

            foreach ($this->tokenizeCompound($component['sel']) as $token) {
                $pseudo = $this->parsePseudoToken($token);

                if ($pseudo === null || $pseudo['selector'] === null) {
                    continue;
                }

                if ($this->anyComplexMatchesTarget($this->parseSelectorList($pseudo['selector']), $targetTokenSets)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $components
     * @param array<int, string> $baseNames
     */
    private function isFamilySinglePseudoComplex(array $components, array $baseNames): bool
    {
        if (count($components) !== 1 || $components[0]['comb'] !== '') {
            return false;
        }

        $tokens = $this->tokenizeCompound($components[0]['sel']);

        if (count($tokens) !== 1) {
            return false;
        }

        $pseudo = $this->parsePseudoToken($tokens[0]);

        return $pseudo !== null
            && $pseudo['selector'] !== null
            && in_array($this->pseudoBaseName($pseudo['name']), $baseNames, true);
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $components
     */
    private function complexContainsNot(array $components): bool
    {
        foreach ($components as $component) {
            foreach ($this->tokenizeCompound($component['sel']) as $token) {
                $pseudo = $this->parsePseudoToken($token);

                if ($pseudo !== null && $this->pseudoBaseName($pseudo['name']) === self::NOT_PSEUDO_BASE_NAME) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pseudoBaseName(string $name): string
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

    private function normalizeAnB(string $prefix): string
    {
        $result = '';

        $length = strlen($prefix);

        for ($i = 0; $i < $length; $i++) {
            if ($prefix[$i] !== ' ') {
                $result .= $prefix[$i];
            }
        }

        return $result;
    }

    private function normalizeCompoundPseudoTokens(string $compound): string
    {
        $tokens       = $this->tokenizeCompound($compound);
        $changedToken = false;

        foreach ($tokens as $index => $token) {
            $pseudo = $this->parsePseudoToken($token);

            if ($pseudo === null || $pseudo['selector'] === null) {
                continue;
            }

            if (! in_array($this->pseudoBaseName($pseudo['name']), self::NTH_OF_PSEUDO_BASE_NAMES, true)) {
                continue;
            }

            $ofPart = trim($pseudo['selector']);

            if ($ofPart === '') {
                continue;
            }

            $parts = [];

            foreach ($this->splitAtTopLevel($ofPart, [','], true) as $piece) {
                $parts[] = $piece;
            }

            $tokens[$index] = ($pseudo['isElement'] ? '::' : ':')
                . $pseudo['name'] . '(' . $this->normalizeAnB($pseudo['argument'])
                . ' of ' . implode(', ', $parts) . ')';
            $changedToken   = true;
        }

        return $changedToken ? implode('', $tokens) : $compound;
    }

    /**
     * @param array<int, string> $targets
     * @param array<int, array<int, array{sel: string, comb: string, lead?: string}>> $extenderComplexes
     * @return array<int, string>
     */
    private function extendByCombinatorOnlyExtender(string $part, array $targets, array $extenderComplexes): array
    {
        $partComponents = $this->parseComplexComponents($part);

        if ($partComponents === []) {
            return [];
        }

        foreach ($targets as $target) {
            $targetComplexes = $this->parseSelectorList($target);

            if ($targetComplexes === [] || count($targetComplexes[0]) !== 1) {
                continue;
            }

            $tokens = $this->tokenizeCompound($targetComplexes[0][0]['sel']);

            if ($tokens === []) {
                continue;
            }

            foreach ($partComponents as $component) {
                if ($this->removeTokensFromCompound($component['sel'], $tokens) === '') {
                    return [$this->complexComponentsToString($extenderComplexes[0])];
                }
            }
        }

        return [];
    }

    /**
     * @param Complex $prefix
     * @param Complex $ancestors
     * @return array<int, Complex>
     */
    private function weavePrefixWithAncestors(array $prefix, array $ancestors): array
    {
        if ($ancestors === []) {
            return [$prefix];
        }

        /** @var array{sel: string, comb: string} $sentinel */
        $sentinel = ['sel' => "\0extender-base", 'comb' => ''];

        return $this->weaveParents($prefix, [...$ancestors, $sentinel]) ?? [];
    }

    private function mergeExtendCombinators(string $left, string $right): ?string
    {
        if ($left === '') {
            return $right;
        }

        if ($right === '') {
            return $left;
        }

        return $left === $right ? $left : null;
    }

    private function isIdentifierChar(string $char): bool
    {
        return $char !== '' && (ctype_alnum($char) || $char === '-' || $char === '_');
    }

    private function hasConsecutiveCombinatorsInParentheses(string $selector): bool
    {
        $depth = 0;
        $lastTokenWasCombinator = false;
        $length = strlen($selector);

        for ($index = 0; $index < $length; $index++) {
            $char = $selector[$index];

            if ($char === '(') {
                $depth++;
                $lastTokenWasCombinator = false;

                continue;
            }

            if ($char === ')') {
                $depth = max(0, $depth - 1);
                $lastTokenWasCombinator = false;

                continue;
            }

            if ($depth === 0) {
                continue;
            }

            if (in_array($char, ['>', '+', '~'], true)) {
                if ($lastTokenWasCombinator) {
                    return true;
                }

                $lastTokenWasCombinator = true;

                continue;
            }

            if ($char !== ' ') {
                $lastTokenWasCombinator = false;
            }
        }

        return false;
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
        $hasPseudoClass   = false;
        $hasPseudoElement = false;
        $hasClassLike     = false;

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
    private function complexIsSuperselector(array $complex1, array $complex2, bool $strictSemantics = false): bool
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
        $last = $complex2[count($complex2) - 1];

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

            if ($strictSemantics && str_contains($combinator1, ' ')) {
                return false;
            }

            if ($remaining1 === 1) {
                if ($strictSemantics) {
                    for ($j = $i2; $j < count($complex2); $j++) {
                        if (str_contains($complex2[$j]['comb'], ' ')) {
                            return false;
                        }
                    }

                    $parents = $this->compoundHasComplicatedSuperselectorSemantics($component1['sel'])
                        ? array_slice($complex2, $i2, count($complex2) - 1 - $i2)
                        : [];

                    return $this->compoundIsSuperselector($component1['sel'], $last['sel'], $parents, true);
                }

                return $this->compoundIsSuperselector($component1['sel'], $last['sel'])
                    && $this->compatibleWithPreviousCombinator(
                        $prev,
                        $i2 < count($complex2) - 1 ? array_slice($complex2, $i2, count($complex2) - 1 - $i2) : [],
                    );
            }

            $end = $i2;

            while (true) {
                $component2 = $complex2[$end];

                if ($strictSemantics && str_contains($component2['comb'], ' ')) {
                    $end++;

                    if ($end === count($complex2) - 1) {
                        return false;
                    }

                    continue;
                }

                if ($strictSemantics) {
                    $parents = $this->compoundHasComplicatedSuperselectorSemantics($component1['sel'])
                        ? array_slice($complex2, $i2, $end - $i2)
                        : [];

                    if ($this->compoundIsSuperselector($component1['sel'], $component2['sel'], $parents, true)) {
                        break;
                    }
                } elseif ($this->compoundIsSuperselector($component1['sel'], $component2['sel'])) {
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

    /**
     * @param string $general
     * @param string $specific
     * @param array<int, array{sel: string, comb: string, lead?: string}> $parents
     */
    private function compoundIsSuperselector(
        string $general,
        string $specific,
        array $parents = [],
        bool $strictSemantics = false,
    ): bool {
        if (! $strictSemantics) {
            return $this->doesCompoundSatisfy($specific, $general);
        }

        return $this->strictCompoundIsSuperselector($general, $specific, $parents);
    }

    /**
     * @param array<int, array{sel: string, comb: string, lead?: string}> $group1
     * @param array<int, array{sel: string, comb: string, lead?: string}> $group2
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

    /**
     * @param string $general
     * @param string $specific
     * @param array<int, array{sel: string, comb: string, lead?: string}> $parents
     */
    private function strictCompoundIsSuperselector(string $general, string $specific, array $parents): bool
    {
        $generalTokens        = $this->tokenizeCompound($general);
        $specificTokens       = $this->tokenizeCompound($specific);
        $generalElementIndex  = $this->findPseudoElementTokenIndex($generalTokens);
        $specificElementIndex = $this->findPseudoElementTokenIndex($specificTokens);

        if ($generalElementIndex !== null || $specificElementIndex !== null) {
            if ($generalElementIndex === null || $specificElementIndex === null) {
                return false;
            }

            return $this->strictSimpleIsSuperselector(
                $generalTokens[$generalElementIndex],
                $specificTokens[$specificElementIndex],
            )
                && $this->strictCompoundPartsAreSuperselector(
                    array_slice($generalTokens, 0, $generalElementIndex),
                    array_slice($specificTokens, 0, $specificElementIndex),
                    $parents,
                )
                && $this->strictCompoundPartsAreSuperselector(
                    array_slice($generalTokens, $generalElementIndex + 1),
                    array_slice($specificTokens, $specificElementIndex + 1),
                    $parents,
                );
        }

        if (
            ! $this->tokensHaveComplicatedSuperselectorSemantics($generalTokens)
            && ! $this->tokensHaveComplicatedSuperselectorSemantics($specificTokens)
        ) {
            if (count($generalTokens) > count($specificTokens)) {
                return false;
            }

            foreach ($generalTokens as $generalToken) {
                $matched = false;

                foreach ($specificTokens as $specificToken) {
                    if ($this->strictSimpleIsSuperselector($generalToken, $specificToken)) {
                        $matched = true;

                        break;
                    }
                }

                if (! $matched) {
                    return false;
                }
            }

            return true;
        }

        foreach ($generalTokens as $generalToken) {
            if ($this->tokenHasSelectorArgument($generalToken)) {
                if (! $this->selectorPseudoIsSuperselector($generalToken, $specificTokens, $parents)) {
                    return false;
                }

                continue;
            }

            $matched = false;

            foreach ($specificTokens as $specificToken) {
                if ($this->strictSimpleIsSuperselector($generalToken, $specificToken)) {
                    $matched = true;

                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $generalTokens
     * @param array<int, string> $specificTokens
     * @param array<int, array{sel: string, comb: string, lead?: string}> $parents
     */
    private function strictCompoundPartsAreSuperselector(
        array $generalTokens,
        array $specificTokens,
        array $parents,
    ): bool {
        if ($generalTokens === []) {
            return true;
        }

        return $this->strictCompoundIsSuperselector(
            implode('', $generalTokens),
            implode('', $specificTokens === [] ? ['*'] : $specificTokens),
            $parents,
        );
    }

    private function strictSimpleIsSuperselector(string $general, string $specific): bool
    {
        if ($general === $specific) {
            return true;
        }

        $generalType = $this->parseTypeToken($general);

        if ($generalType['element'] === '*') {
            if ($generalType['namespace'] === null || $generalType['namespace'] === '*') {
                return true;
            }

            if ($this->isUniversalTypeToken($specific) || $this->isTypeLikeToken($specific)) {
                return $generalType['namespace'] === $this->parseTypeToken($specific)['namespace'];
            }

            return false;
        }

        if ($this->specificSubselectorPseudoCoversGeneral($specific, $general)) {
            return true;
        }

        if ($this->isTypeLikeToken($general)) {
            return $this->typeLikeIsSuperselector($general, $specific);
        }

        $generalPseudo = $this->parsePseudoToken($general);

        if ($generalPseudo === null) {
            return false;
        }

        $specificPseudo = $this->parsePseudoToken($specific);

        if ($specificPseudo === null) {
            return false;
        }

        if ($generalPseudo['selector'] === null) {
            $equalNames = strtolower($generalPseudo['name']) === strtolower($specificPseudo['name'])
                && $generalPseudo['isElement'] === $specificPseudo['isElement'];

            return $equalNames && $generalPseudo['argument'] === $specificPseudo['argument'];
        }

        if ($specificPseudo['selector'] !== null && $generalPseudo['isElement'] && $specificPseudo['isElement']) {
            $normalizedName = strtolower($generalPseudo['name']);

            if ($normalizedName !== '' && $normalizedName[0] === '-') {
                $secondDash = strpos($normalizedName, '-', 1);

                if ($secondDash !== false && $secondDash > 1) {
                    $normalizedName = substr($normalizedName, $secondDash + 1);
                }
            }

            if ($normalizedName === 'slotted' && $specificPseudo['name'] === $generalPseudo['name']) {
                return $this->listsAreSuperselectors(
                    $this->parseSelectorList($generalPseudo['selector']),
                    $this->parseSelectorList($specificPseudo['selector']),
                );
            }
        }

        return $this->selectorPseudoIsSuperselector($general, [$specific], []);
    }

    private function typeLikeIsSuperselector(string $general, string $specific): bool
    {
        if (! $this->isTypeLikeToken($specific)) {
            return false;
        }

        $generalInfo  = $this->parseTypeToken($general);
        $specificInfo = $this->parseTypeToken($specific);

        return $generalInfo['element'] === $specificInfo['element']
            && ($generalInfo['namespace'] === '*' || $generalInfo['namespace'] === $specificInfo['namespace']);
    }

    private function specificSubselectorPseudoCoversGeneral(string $specific, string $general): bool
    {
        $specificPseudo = $this->parsePseudoToken($specific);

        if (
            $specificPseudo === null
            || $specificPseudo['isElement']
            || $specificPseudo['selector'] === null
            || ! in_array(strtolower($specificPseudo['name']), self::SUBSELECTOR_PSEUDO_NAMES, true)
        ) {
            return false;
        }

        foreach ($this->parseSelectorList($specificPseudo['selector']) as $complex) {
            if ($complex === []) {
                return false;
            }

            $lastSelector = $complex[count($complex) - 1]['sel'];
            $covered      = false;

            foreach ($this->tokenizeCompound($lastSelector) as $token) {
                if ($this->strictSimpleIsSuperselector($general, $token)) {
                    $covered = true;

                    break;
                }
            }

            if (! $covered) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $generalToken
     * @param array<int, string>  $specificTokens
     * @param array<int, array{sel: string, comb: string, lead?: string}> $parents
     */
    private function selectorPseudoIsSuperselector(string $generalToken, array $specificTokens, array $parents): bool
    {
        $generalPseudo = $this->parsePseudoToken($generalToken);

        if ($generalPseudo === null) {
            return false;
        }

        $rawName     = $generalPseudo['name'];
        $loweredName = strtolower($rawName);

        if ($loweredName !== '' && $loweredName[0] === '-') {
            $secondDash = strpos($loweredName, '-', 1);

            if ($secondDash !== false && $secondDash > 1) {
                $loweredName = substr($loweredName, $secondDash + 1);
            }
        }

        $normalizedName = $loweredName;
        $generalText    = (string) $generalPseudo['selector'];

        switch ($normalizedName) {
            case 'is':
            case 'matches':
            case 'any':
            case 'where':
                $generalList = $this->parseSelectorList($generalText);

                foreach ($this->selectorPseudoArgs($specificTokens, $rawName) as $specificList) {
                    if ($this->listsAreSuperselectors($generalList, $specificList)) {
                        return true;
                    }
                }

                $target = [...$parents, ['sel' => implode('', $specificTokens), 'comb' => '']];

                foreach ($generalList as $complex) {
                    if (($complex[0]['lead'] ?? '') !== '') {
                        continue;
                    }

                    if ($this->complexIsSuperselector($complex, $target, true)) {
                        return true;
                    }
                }

                return false;

            case 'has':
            case 'host':
            case 'host-context':
                $generalList = $this->parseSelectorList($generalText);

                foreach ($this->selectorPseudoArgs($specificTokens, $rawName) as $specificList) {
                    if ($this->listsAreSuperselectors($generalList, $specificList)) {
                        return true;
                    }
                }

                return false;

            case 'slotted':
                $generalList = $this->parseSelectorList($generalText);

                foreach ($this->selectorPseudoArgs($specificTokens, $rawName, false) as $specificList) {
                    if ($this->listsAreSuperselectors($generalList, $specificList)) {
                        return true;
                    }
                }

                return false;

            case 'not':
                return $this->notPseudoIsSuperselector($generalPseudo, $rawName, $specificTokens);

            case 'current':
                $generalCanonical = $this->canonicalizeSelectorText($generalText);

                foreach ($this->selectorPseudoArgs($specificTokens, $rawName) as $specificList) {
                    if ($this->canonicalizeSelectorText($this->complexesToString($specificList)) === $generalCanonical) {
                        return true;
                    }
                }

                return false;

            case 'nth-child':
            case 'nth-last-child':
                $generalList = $this->parseSelectorList($generalText);

                foreach ($specificTokens as $specificToken) {
                    $specificPseudo = $this->parsePseudoToken($specificToken);

                    if ($specificPseudo === null || $specificPseudo['name'] !== $rawName) {
                        continue;
                    }

                    if ($specificPseudo['argument'] !== $generalPseudo['argument'] || $specificPseudo['selector'] === null) {
                        continue;
                    }

                    if ($this->listsAreSuperselectors($generalList, $this->parseSelectorList($specificPseudo['selector']))) {
                        return true;
                    }
                }

                return false;

            default:
                return false;
        }
    }

    /**
     * @param array{name: string, argument: string, selector: ?string, isElement: bool} $generalPseudo
     * @param array<int, string> $specificTokens
     */
    private function notPseudoIsSuperselector(array $generalPseudo, string $rawName, array $specificTokens): bool
    {
        foreach ($this->parseSelectorList((string) $generalPseudo['selector']) as $complex) {
            if (($complex[0]['lead'] ?? '') !== '') {
                return false;
            }

            $negated = false;

            foreach ($specificTokens as $specificToken) {
                $specificPseudo = $this->parsePseudoToken($specificToken);

                if ($specificPseudo === null && $this->isTypeLikeToken($specificToken)) {
                    $typeInfo = $this->parseTypeToken($specificToken);

                    if ($typeInfo['element'] === '*') {
                        continue;
                    }

                    $lastComponent = end($complex);

                    if ($lastComponent === false) {
                        continue;
                    }

                    foreach ($this->tokenizeCompound($lastComponent['sel']) as $lastToken) {
                        if (
                            $this->isTypeLikeToken($lastToken)
                            && $this->parsePseudoToken($lastToken) === null
                            && $this->parseTypeToken($lastToken)['element'] !== '*'
                            && $lastToken !== $specificToken
                        ) {
                            $negated = true;

                            break;
                        }
                    }
                } elseif ($specificToken !== '' && $specificToken[0] === '#') {
                    $lastComponent = end($complex);

                    if ($lastComponent === false) {
                        continue;
                    }

                    foreach ($this->tokenizeCompound($lastComponent['sel']) as $lastToken) {
                        if ($lastToken[0] === '#' && $lastToken !== $specificToken) {
                            $negated = true;

                            break;
                        }
                    }
                } elseif ($specificPseudo !== null && $specificPseudo['selector'] !== null && $specificPseudo['name'] === $rawName) {
                    $negated = $this->listsAreSuperselectors(
                        $this->parseSelectorList($specificPseudo['selector']),
                        [$complex],
                    );
                }

                if ($negated) {
                    break;
                }
            }

            if (! $negated) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $tokens
     * @return array<int, array<int, Complex>>
     */
    private function selectorPseudoArgs(array $tokens, string $rawName, bool $isClass = true): array
    {
        $args = [];

        foreach ($tokens as $token) {
            $pseudo = $this->parsePseudoToken($token);

            if ($pseudo === null || $pseudo['isElement'] === $isClass) {
                continue;
            }

            if ($pseudo['name'] !== $rawName || $pseudo['selector'] === null) {
                continue;
            }

            $args[] = $this->parseSelectorList($pseudo['selector']);
        }

        return $args;
    }

    /**
     * @return array{string, ?string}
     */
    private function splitNthOfSelector(string $loweredName, string $argument): array
    {
        if ($loweredName !== 'nth-child' && $loweredName !== 'nth-last-child') {
            return [$argument, null];
        }

        $length = strlen($argument);
        $quote  = '';
        $depth  = 0;
        $word   = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $argument[$i];

            if ($quote !== '') {
                $word .= $char;

                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $word .= $char;

                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;

                $word .= $char;

                continue;
            }

            if (($char === ')' || $char === ']') && $depth > 0) {
                $depth--;

                $word .= $char;

                continue;
            }

            if ($depth > 0) {
                $word .= $char;

                continue;
            }

            if (in_array($char, [' ', "\t", "\n", "\r", "\f"], true)) {
                if (strtolower($word) === 'of') {
                    $before = rtrim(substr($argument, 0, $i - strlen($word)));
                    $after  = ltrim(substr($argument, $i));

                    return [$before, $after];
                }

                $word = '';

                continue;
            }

            $word .= $char;
        }

        if (strtolower($word) === 'of') {
            $before = rtrim(substr($argument, 0, $length - strlen($word)));

            return [$before, ''];
        }

        return [trim($argument), null];
    }

    private function isIdentifierBodyChar(string $char): bool
    {
        if (ord($char[0]) >= 0x80) {
            return true;
        }

        return ctype_alnum($char) || $char === '-' || $char === '_' || $char === '\\';
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function decodeSelectorEscape(string $text, int $index): array
    {
        $length = strlen($text);

        $index++;

        if ($index >= $length) {
            return ['\\', $index];
        }

        $char = $text[$index];

        if ($char === "\n") {
            return ['', $index + 1];
        }

        if ($char === "\r") {
            $index++;

            if (($text[$index] ?? '') === "\n") {
                $index++;
            }

            return ['', $index];
        }

        if (ctype_xdigit($char)) {
            $hex = '';

            while ($index < $length && strlen($hex) < 6 && ctype_xdigit($text[$index])) {
                $hex .= $text[$index];

                $index++;
            }

            if ($index < $length && ($text[$index] === ' ' || $text[$index] === "\t")) {
                $index++;
            }

            return [StringEscapeDecoder::hexToUtf8($hex), $index];
        }

        return [$char, $index + 1];
    }

    private function encodeCanonicalIdentifierChar(string $char, bool $insideIdentifier): string
    {
        $byte = ord($char[0]);

        if ($byte >= 0x80) {
            return $char;
        }

        if (ctype_digit($char) && ! $insideIdentifier) {
            return '\\' . dechex($byte) . ' ';
        }

        if (
            ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || $char === '-'
            || $char === '_'
            || ctype_digit($char)
        ) {
            return $char;
        }

        if ($byte >= 0x20 && $byte <= 0x7E) {
            return '\\' . $char;
        }

        return '\\' . dechex($byte) . ' ';
    }

    private function skipVerbatimRegion(string $text, int $startIndex, string $open, string $close): int
    {
        $length = strlen($text);
        $depth  = 0;
        $quote  = '';
        $index  = $startIndex;

        while ($index < $length) {
            $char = $text[$index];

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
                    return $index + 1;
                }
            }

            $index++;
        }

        return $length;
    }

    /**
     * @param array<int, string> $tokens
     */
    private function tokensHaveComplicatedSuperselectorSemantics(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($this->tokenHasSelectorArgument($token)) {
                return true;
            }
        }

        return false;
    }

    private function compoundHasComplicatedSuperselectorSemantics(string $compound): bool
    {
        return $this->tokensHaveComplicatedSuperselectorSemantics($this->tokenizeCompound($compound));
    }

    private function tokenHasSelectorArgument(string $token): bool
    {
        $pseudo = $this->parsePseudoToken($token);

        if ($pseudo === null || $pseudo['selector'] === null) {
            return false;
        }

        return $pseudo['selector'] !== '';
    }

    /**
     * @param array<int, string> $tokens
     */
    private function findPseudoElementTokenIndex(array $tokens): ?int
    {
        foreach ($tokens as $index => $token) {
            if ($this->isPseudoElementToken($token)) {
                return $index;
            }
        }

        return null;
    }

    private function isTypeLikeToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return ! in_array($token[0], ['.', '#', '%', ':', '['], true);
    }

    /**
     * @param string $simple
     * @param array<int, string> $compound
     * @return array<int, string>|null
     */
    private function strictUnifySimple(string $simple, array $compound): ?array
    {
        if ($compound === []) {
            return [$simple];
        }

        $first          = $compound[0];
        $simplePseudo   = $this->parsePseudoToken($simple);
        $firstPseudo    = $this->parsePseudoToken($first);
        $simpleIsHost   = $simplePseudo !== null
            && in_array(strtolower($simplePseudo['name']), ['host', 'host-context'], true);
        $firstIsHostish = $firstPseudo !== null
            && in_array(strtolower($firstPseudo['name']), ['host', 'host-context'], true);

        if ($this->isUniversalTypeToken($simple)) {
            if ($this->isUniversalTypeToken($first) || $this->isTypeLikeToken($first)) {
                $unified = $this->unifyUniversalAndElement($simple, $first);

                return $unified === null ? null : [$unified, ...array_slice($compound, 1)];
            }

            if ($firstIsHostish) {
                return null;
            }

            $namespace = $this->parseTypeToken($simple)['namespace'];

            if ($namespace === null || $namespace === '*') {
                return $compound;
            }

            return [$simple, ...$compound];
        }

        if ($simplePseudo === null && $this->isTypeLikeToken($simple)) {
            if ($this->isUniversalTypeToken($first) || $this->isTypeLikeToken($first)) {
                $unified = $this->unifyUniversalAndElement($simple, $first);

                return $unified === null ? null : [$unified, ...array_slice($compound, 1)];
            }

            return [$simple, ...$compound];
        }

        if ($simpleIsHost) {
            foreach ($compound as $token) {
                $tokenPseudo = $this->parsePseudoToken($token);

                if ($tokenPseudo === null) {
                    return null;
                }

                if (
                    ! in_array(strtolower($tokenPseudo['name']), ['host', 'host-context'], true)
                    && $tokenPseudo['selector'] === null
                ) {
                    return null;
                }
            }
        } elseif (count($compound) === 1 && ($this->isUniversalTypeToken($first) || $firstIsHostish)) {
            if ($this->isUniversalTypeToken($first)) {
                $unified = $this->unifyUniversalAndElement($first, $simple);

                return $unified === null ? null : [$unified];
            }

            return $this->strictUnifySimple($first, [$simple]);
        }

        if ($simple !== '' && $simple[0] === '#') {
            foreach ($compound as $token) {
                if ($token !== '' && $token[0] === '#' && $token !== $simple) {
                    return null;
                }
            }
        }

        if (in_array($simple, $compound, true)) {
            return $compound;
        }

        $result                = [];
        $addedThis             = false;
        $insertBeforeAnyPseudo = $simplePseudo === null;
        $simpleIsElement       = $simplePseudo !== null && $this->isPseudoElementToken($simple);

        if ($simpleIsElement) {
            $simpleName = strtolower($simplePseudo['name']);

            foreach ($compound as $token) {
                if (! $this->isPseudoElementToken($token)) {
                    continue;
                }

                $tokenPseudo = $this->parsePseudoToken($token);

                if (
                    $tokenPseudo === null
                    || strtolower($tokenPseudo['name']) !== $simpleName
                    || $tokenPseudo['argument'] !== $simplePseudo['argument']
                ) {
                    return null;
                }

                return $compound;
            }
        }

        foreach ($compound as $token) {
            $trigger = $insertBeforeAnyPseudo
                ? $this->parsePseudoToken($token) !== null
                : $this->isPseudoElementToken($token);

            if ($trigger && ! $addedThis) {
                if ($simpleIsElement) {
                    return null;
                }

                $result[]  = $simple;
                $addedThis = true;
            }

            $result[] = $token;
        }

        if (! $addedThis) {
            $result[] = $simple;
        }

        return $result;
    }

    private function unifyUniversalAndElement(string $left, string $right): ?string
    {
        $leftInfo  = $this->parseTypeToken($left);
        $rightInfo = $this->parseTypeToken($right);

        if ($leftInfo['namespace'] === $rightInfo['namespace'] || $rightInfo['namespace'] === '*') {
            $namespace = $leftInfo['namespace'];
        } elseif ($leftInfo['namespace'] === '*') {
            $namespace = $rightInfo['namespace'];
        } else {
            return null;
        }

        if ($leftInfo['element'] === $rightInfo['element'] || $rightInfo['element'] === '*') {
            $element = $leftInfo['element'];
        } elseif ($leftInfo['element'] === '*') {
            $element = $rightInfo['element'];
        } else {
            return null;
        }

        if ($namespace === null) {
            return $element;
        }

        return $namespace . '|' . $element;
    }

    private function canonicalizeSelectorText(string $text): string
    {
        return $this->complexesToString($this->parseSelectorList($text));
    }
}
