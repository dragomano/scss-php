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
use Bugo\SCSS\Utils\SelectorHelper;
use Bugo\SCSS\Utils\SelectorTokenizer;

use function array_map;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function str_contains;
use function str_replace;
use function strlen;
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

    public function __construct(
        private readonly SelectorTokenizer $tokenizer = new SelectorTokenizer()
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
     */
    private function append(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('selector.append'),
                1,
                true
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'append', $positional);

        $resultParts = $this->splitSelectorList(
            $this->normalizeSelector($this->requireString($positional[0], 'selector.append'))
        );

        for ($i = 1; $i < count($positional); $i++) {
            $next      = $this->normalizeSelector($this->requireString($positional[$i], 'selector.append'));
            $nextParts = $this->splitSelectorList($next);
            $combined  = [];

            foreach ($resultParts as $resultPart) {
                foreach ($nextParts as $nextPart) {
                    if (str_contains($nextPart, '&')) {
                        $combined[] = $this->normalizeSelector(str_replace('&', $resultPart, $nextPart));

                        continue;
                    }

                    $combined[] = $this->normalizeSelector($resultPart . $nextPart);
                }
            }

            $resultParts = $combined;
        }

        return new StringNode(implode(', ', array_values(array_unique($resultParts))));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function extend(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 3) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('selector.extend'),
                3
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'extend', $positional);

        $selector = $this->normalizeSelector($this->requireString($positional[0], 'selector.extend'));
        $target   = $this->normalizeSelector($this->requireString($positional[1], 'selector.extend'));
        $source   = $this->normalizeSelector($this->requireString($positional[2], 'selector.extend'));

        foreach ($this->splitSelectorList($target) as $targetPart) {
            $targetPart = trim($targetPart);

            if ($targetPart === '') {
                continue;
            }

            if ($this->hasUnsupportedTopLevelCombinator($targetPart)) {
                throw new SassErrorException(
                    'Complex selectors may not be extended. Use a simple selector target in @extend.'
                );
            }

            $compounds = $this->splitSelectorCompounds($targetPart);

            if (count($compounds) > 1) {
                throw new SassErrorException(
                    'Complex selectors may not be extended. Use a simple selector target in @extend.'
                );
            }

            $tokens = $this->tokenizeSelectorCompound($targetPart);

            if (count($tokens) > 1) {
                throw new SassErrorException(
                    'Compound selectors may not be extended. Use separate @extend directives for each simple selector.'
                );
            }
        }

        $result = [];
        $parts  = $this->splitSelectorList($selector);

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $result[] = $part;

            foreach ($this->replaceExtendTargetInSelectorPart($part, $target, $source) as $extendedPart) {
                $result[] = $extendedPart;
            }
        }

        return new StringNode(implode(', ', array_values(array_unique($result))));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function isSuperselector(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 2) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('selector.is-superselector'),
                2
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'is-superselector', $positional);

        $super = $this->normalizeSelector($this->requireString($positional[0], 'selector.is-superselector'));
        $sub   = $this->normalizeSelector($this->requireString($positional[1], 'selector.is-superselector'));

        if ($super === $sub) {
            return $this->boolNode(true);
        }

        $superParts = $this->selectorParts($super);
        $subParts   = $this->selectorParts($sub);

        foreach ($superParts as $part) {
            if (! in_array($part, $subParts, true)) {
                return $this->boolNode(false);
            }
        }

        return $this->boolNode(true);
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
                true
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'nest', $positional);

        $resultParts = $this->splitSelectorList(
            $this->normalizeSelector($this->requireString($positional[0], 'selector.nest'))
        );

        for ($i = 1; $i < count($positional); $i++) {
            $next      = $this->normalizeSelector($this->requireString($positional[$i], 'selector.nest'));
            $nextParts = $this->splitSelectorList($next);
            $combined  = [];

            foreach ($resultParts as $resultPart) {
                foreach ($nextParts as $nextPart) {
                    if (str_contains($nextPart, '&')) {
                        $combined[] = $this->normalizeSelector(str_replace('&', $resultPart, $nextPart));

                        continue;
                    }

                    $combined[] = trim($resultPart . ' ' . $nextPart);
                }
            }

            $resultParts = $combined;
        }

        return new StringNode(implode(', ', array_values(array_unique($resultParts))));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function parse(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('selector.parse'),
                'a string selector argument'
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'parse', $positional);

        return new StringNode($this->normalizeSelector($this->requireString($positional[0], 'selector.parse')));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function replace(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 3) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('selector.replace'),
                3
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'replace', $positional);

        $selector    = $this->normalizeSelector($this->requireString($positional[0], 'selector.replace'));
        $original    = $this->normalizeSelector($this->requireString($positional[1], 'selector.replace'));
        $replacement = $this->normalizeSelector($this->requireString($positional[2], 'selector.replace'));
        $structured  = $this->replaceExtendTargetInStructuredSelectorPart($selector, $original, $replacement);

        if ($structured !== null && $structured !== []) {
            return new StringNode(implode(', ', array_values(array_unique($structured))));
        }

        return new StringNode($this->normalizeSelector(str_replace($original, $replacement, $selector)));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function simpleSelectors(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('selector.simple-selectors'),
                'a string selector argument'
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'simple-selectors', $positional);

        $selector = $this->normalizeSelector($this->requireString($positional[0], 'selector.simple-selectors'));
        $parts    = $this->selectorParts($selector);

        return new ListNode(array_map(fn(string $part): AstNode => new StringNode($part), $parts), 'comma');
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function unify(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 2) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('selector.unify'),
                2
            );
        }

        $this->warnAboutDeprecatedSelectorFunction($context, 'unify', $positional);

        $firstSelector  = $this->normalizeSelector($this->requireString($positional[0], 'selector.unify'));
        $secondSelector = $this->normalizeSelector($this->requireString($positional[1], 'selector.unify'));

        if (str_contains($secondSelector, '&')) {
            return new StringNode($this->normalizeSelector(str_replace('&', $firstSelector, $secondSelector)));
        }

        $result      = [];
        $firstParts  = $this->splitSelectorList($firstSelector);
        $secondParts = $this->splitSelectorList($secondSelector);

        foreach ($firstParts as $firstPart) {
            foreach ($secondParts as $secondPart) {
                foreach ($this->unifySelectorParts($firstPart, $secondPart) as $unifiedPart) {
                    $result[] = $unifiedPart;
                }
            }
        }

        if ($result === []) {
            return $this->nullNode();
        }

        return new StringNode(implode(', ', array_values(array_unique($result))));
    }

    /**
     * @return array<int, string>
     */
    private function splitSelectorList(string $selector): array
    {
        return SelectorHelper::splitList($selector);
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

        $targetTokens = $this->tokenizeSelectorCompound($target);

        if ($targetTokens === []) {
            return null;
        }

        $partCompounds   = $this->splitSelectorCompounds($part);
        $sourceCompounds = $this->splitSelectorCompounds($source);

        return $this->tokenizer->replaceExtendTargetInStructuredSelector(
            $partCompounds,
            $targetTokens,
            $sourceCompounds
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function warnAboutDeprecatedSelectorFunction(
        ?BuiltinCallContext $context,
        string $name,
        array $positional
    ): void {
        if (! $this->isGlobalBuiltinCall()) {
            return;
        }

        $this->warnAboutDeprecatedBuiltinFunctionWithSingleSuggestion(
            $context,
            $this->deprecatedSelectorSuggestion($name, $positional),
            'selector.' . $name
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function deprecatedSelectorSuggestion(string $name, array $positional): string
    {
        $arguments = $positional;

        if ($this->hasRawArguments()) {
            $arguments = $this->rawPositionalArguments();
        }

        return 'selector.' . $name . '(' . implode(', ', $this->describeBuiltinArguments($arguments)) . ')';
    }

    /**
     * @return array<int, string>
     */
    private function unifySelectorParts(string $first, string $second): array
    {
        if ($first === '' || $second === '') {
            return [];
        }

        if ($this->hasUnsupportedTopLevelCombinator($first) || $this->hasUnsupportedTopLevelCombinator($second)) {
            return [];
        }

        $firstCompounds  = $this->splitSelectorCompounds($first);
        $secondCompounds = $this->splitSelectorCompounds($second);

        if ($firstCompounds === [] || $secondCompounds === []) {
            return [];
        }

        $firstSubject   = $firstCompounds[count($firstCompounds) - 1];
        $secondSubject  = $secondCompounds[count($secondCompounds) - 1];
        $unifiedSubject = $this->tokenizer->unifyCompounds($firstSubject, $secondSubject);

        if ($unifiedSubject === null) {
            return [];
        }

        $firstPrefixes = [];
        for ($i = 0; $i < count($firstCompounds) - 1; $i++) {
            $firstPrefixes[] = $firstCompounds[$i];
        }

        $secondPrefixes = [];
        for ($i = 0; $i < count($secondCompounds) - 1; $i++) {
            $secondPrefixes[] = $secondCompounds[$i];
        }

        $firstPrefixes  = $this->pruneCoveredCompounds($firstPrefixes, $secondPrefixes);
        $secondPrefixes = $this->pruneCoveredCompounds($secondPrefixes, $firstPrefixes);
        $prefixVariants = $this->tokenizer->interleaveSequences($firstPrefixes, $secondPrefixes);

        $resolved = [];

        foreach ($prefixVariants as $prefixVariant) {
            $candidateCompounds = [];

            foreach ($prefixVariant as $compound) {
                $candidateCompounds[] = $compound;
            }

            $candidateCompounds[] = $unifiedSubject;
            $resolved[] = implode(' ', $candidateCompounds);
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param array<int, string> $candidates
     * @param array<int, string> $others
     * @return array<int, string>
     */
    private function pruneCoveredCompounds(array $candidates, array $others): array
    {
        $result = [];

        foreach ($candidates as $candidate) {
            $covered = false;

            foreach ($others as $other) {
                if ($this->tokenizer->doesCompoundSatisfy($other, $candidate)) {
                    $covered = true;

                    break;
                }
            }

            if (! $covered) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    private function hasUnsupportedTopLevelCombinator(string $selector): bool
    {
        return $this->tokenizer->hasUnsupportedTopLevelCombinator($selector);
    }

    private function requireString(AstNode $value, string $context): string
    {
        if (! ($value instanceof StringNode)) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext($context),
                'a string selector argument'
            );
        }

        return $value->value;
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
    private function selectorParts(string $selector): array
    {
        $parts = [];

        $compounds = $this->splitSelectorCompounds($selector);

        foreach ($compounds as $compound) {
            $tokens = $this->tokenizeSelectorCompound($compound);

            if ($tokens === []) {
                $parts[] = $compound;

                continue;
            }

            foreach ($tokens as $token) {
                $parts[] = $token;
            }
        }

        return $parts;
    }

    /**
     * @return array<int, string>
     */
    private function splitSelectorCompounds(string $selector): array
    {
        return $this->tokenizer->splitAtTopLevel($selector, [' ', '>', '+', '~']);
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeSelectorCompound(string $compound): array
    {
        return $this->tokenizer->tokenizeCompound($compound);
    }
}
