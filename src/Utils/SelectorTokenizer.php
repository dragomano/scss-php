<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use function array_fill_keys;
use function array_merge;
use function array_splice;
use function array_unique;
use function array_values;
use function count;
use function ctype_alnum;
use function implode;
use function in_array;
use function str_starts_with;
use function strlen;
use function trim;

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
                $tokens[] = '*';

                $index++;

                continue;
            }

            if ($this->isIdentifierChar($char)) {
                $tokens[] = $this->readIdentifier($compound, $index);

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

            return implode('', $orderedTokens);
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

        if (
            $leftType !== ''
            && $leftType !== '*'
            && $rightType !== ''
            && $rightType !== '*'
            && $leftType !== $rightType
        ) {
            return null;
        }

        $leftId  = $this->extractIdToken($leftTokens);
        $rightId = $this->extractIdToken($rightTokens);

        if ($leftId !== '' && $rightId !== '' && $leftId !== $rightId) {
            return null;
        }

        $resolvedType = $leftType !== '' && $leftType !== '*' ? $leftType : $rightType;

        $result = [];
        if ($resolvedType !== '' && $resolvedType !== '*') {
            $result[] = $resolvedType;
        }

        $mergedNonTypeTokens = [];

        foreach ($leftTokens as $token) {
            if (
                in_array($token, ['', '*', $leftType], true)
                || in_array($token, $mergedNonTypeTokens, true)
            ) {
                continue;
            }

            $mergedNonTypeTokens[] = $token;
        }

        foreach ($rightTokens as $token) {
            if (
                in_array($token, ['', '*', $rightType], true)
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

        if ($requiredType !== '' && $requiredType !== '*') {
            if ($candidateType === '' || $candidateType === '*' || $candidateType !== $requiredType) {
                return false;
            }
        }

        /** @var array<string, true> $candidateTokenSet */
        $candidateTokenSet = array_fill_keys($candidateTokens, true);

        foreach ($requiredTokens as $requiredToken) {
            if (in_array($requiredToken, ['', '*', $requiredType], true)) {
                continue;
            }

            if (! isset($candidateTokenSet[$requiredToken])) {
                return false;
            }
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
     * @param array<int, string> $tokens
     * @return array<int, string>
     */
    public function orderTokens(array $tokens): array
    {
        $ids                  = [];
        $classesAndAttributes = [];
        $pseudoClasses        = [];
        $pseudoElements       = [];
        $other                = [];

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
                if (str_starts_with($token, '::')) {
                    $pseudoElements[] = $token;
                } else {
                    $pseudoClasses[] = $token;
                }

                continue;
            }

            $other[] = $token;
        }

        return array_merge($ids, $classesAndAttributes, $pseudoClasses, $pseudoElements, $other);
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
     * @param array<int, string> $splitChars
     * @return array<int, string>
     */
    public function splitAtTopLevel(string $selector, array $splitChars, bool $handleQuotes = false): array
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
                $trimmed = trim($buffer);

                if ($trimmed !== '') {
                    $result[] = $trimmed;
                }

                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);

        if ($trimmed !== '') {
            $result[] = $trimmed;
        }

        return $result;
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

        return $token;
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
        $hasPseudo    = false;
        $hasClassLike = false;

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if ($token[0] === ':') {
                $hasPseudo = true;

                continue;
            }

            if ($token[0] === '.' || $token[0] === '#' || $token[0] === '%' || $token[0] === '[') {
                $hasClassLike = true;
            }
        }

        return $hasPseudo && $hasClassLike;
    }
}
