<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Exceptions\InvalidLoopBoundaryException;
use Bugo\SCSS\Exceptions\SassThrowable;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StatementNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Runtime\DeferredAtRuleChunk;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Style;
use Bugo\SCSS\Utils\MediaQuery;
use Bugo\SCSS\Utils\SelectorHelper;
use Bugo\SCSS\Utils\SelectorTokenizer;
use Bugo\SCSS\Utils\StringHelper;

use function array_fill_keys;
use function array_pop;
use function array_unique;
use function array_values;
use function count;
use function ctype_alpha;
use function ctype_digit;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function ltrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

final readonly class Selector
{
    private SelectorRuleOptimizer $optimizer;

    public function __construct(
        private CompilerContext $ctx,
        private CompilerOptions $options,
        private Render $render,
        private Text $text,
        private SelectorTokenizer $tokenizer,
        private NodeDispatcherInterface $dispatcher,
        private ExtendsResolver $extends,
        private ModuleVariableAssignerInterface $moduleVariableAssigner,
        private CssArgumentEvaluator $cssArgumentEvaluator,
        private AstValueEvaluatorInterface $valueEvaluator,
        private AstValueFormatterInterface $valueFormatter,
        private ParserInterface $parser,
    ) {
        $this->optimizer = new SelectorRuleOptimizer();
    }

    public function resolveDirectivePrelude(string $prelude, Environment $env): string
    {
        return $this->text->resolveDirectivePrelude($prelude, $env);
    }

    public function normalizeMediaQueryPrelude(string $prelude): string
    {
        return $this->text->normalizeMediaQueryPrelude($prelude);
    }

    public function evaluateMediaFeatureOperands(string $prelude, Environment $env): string
    {
        return $this->text->evaluateMediaFeatureOperands($prelude, $env);
    }

    public function stripAllComments(string $text): string
    {
        return $this->text->stripAllComments($text);
    }

    public function stripLeadingComments(string $text): string
    {
        return $this->text->stripLeadingComments($text);
    }

    public function stripCommentsExceptTrailing(string $text): string
    {
        return $this->text->stripCommentsExceptTrailing($text);
    }

    public function collapseWhitespaceInPrelude(string $prelude): string
    {
        return $this->text->collapseWhitespaceInPrelude($prelude);
    }

    public function normalizeCssImportQuery(string $import, Environment $env): string
    {
        return $this->text->normalizeCssImportQuery($import, $env);
    }

    public function canonicalizeSelectorEscapes(string $selector): string
    {
        return $this->tokenizer->canonicalizeSelectorEscapes($selector);
    }

    public function normalizeSelectorAttributes(string $selector): string
    {
        return $this->tokenizer->normalizeSelectorAttributes($selector);
    }

    public function normalizePseudoArguments(string $selector): string
    {
        return $this->tokenizer->normalizePseudoArguments($selector);
    }

    /**
     * @param list<int> $protectedIndices
     */
    public function normalizeNthArguments(string $selector, array $protectedIndices = []): string
    {
        return $this->tokenizer->normalizeNthArguments($selector, $protectedIndices);
    }

    /**
     * @return list<int>
     */
    public function findFullyInterpolatedNthIndices(string $selector): array
    {
        return $this->tokenizer->findFullyInterpolatedNthIndices($selector);
    }

    public function normalizeAdjacentSelectorCompounds(string $selector): string
    {
        return $this->tokenizer->normalizeAdjacentSelectorCompounds($selector);
    }

    /**
     * @return list<AtRuleContextEntry>
     */
    public function getCurrentAtRuleStack(Environment $env): array
    {
        if (! $env->getCurrentScope()->hasVariable('__at_rule_stack')) {
            return [];
        }

        $stack = $env->getCurrentScope()->getVariable('__at_rule_stack');

        if (! is_array($stack)) {
            return [];
        }

        /** @var list<AtRuleContextEntry|array<string, mixed>> $stack */
        $normalized = [];

        foreach ($stack as $entry) {
            $normalizedEntry = $this->normalizeAtRuleStackEntry($entry);

            if ($normalizedEntry === null) {
                continue;
            }

            $normalized[] = $normalizedEntry;
        }

        return $normalized;
    }

    public function combineMediaQueryPreludes(string $outer, string $inner): string
    {
        $outerParts = $this->splitTopLevelSelectorList($outer);
        $innerParts = $this->splitTopLevelSelectorList($inner);

        if ($outerParts === []) {
            return $inner;
        }

        if ($innerParts === []) {
            return $outer;
        }

        $combined = [];

        foreach ($outerParts as $outerPart) {
            foreach ($innerParts as $innerPart) {
                $combined[] = $this->mergeMediaPreludeParts(trim($outerPart), trim($innerPart));
            }
        }

        return $this->implodeUniqueSelectorList($combined);
    }

    /**
     * @return string|null merged prelude; empty string when the intersection is empty
     * (the rule is removed); null when the intersection can't be represented
     * (the rule stays nested)
     */
    public function mergeMediaQueryPreludes(string $outer, string $inner): ?string
    {
        $outerQueries = MediaQuery::parseList($outer);
        $innerQueries = MediaQuery::parseList($inner);

        if ($outerQueries === null || $innerQueries === null) {
            return $this->combineMediaQueryPreludes($outer, $inner);
        }

        $merged = MediaQuery::mergeLists($outerQueries, $innerQueries);

        if ($merged === null) {
            return null;
        }

        if ($merged === []) {
            return '';
        }

        return MediaQuery::serializeList($merged);
    }

    public function isBubblingAtRuleNode(AstNode $node): bool
    {
        if ($node instanceof SupportsNode) {
            return true;
        }

        if ($node instanceof DirectiveNode && $this->isBubblingDirective($node)) {
            return true;
        }

        if ($node instanceof RuleNode && $this->isBubblingRuleNode($node)) {
            return true;
        }

        return false;
    }

    public function normalizeBubblingNodeForSelector(StatementNode $node, string $selector): StatementNode
    {
        if ($selector === '') {
            return $node;
        }

        $attachParentSelector = $this->shouldAttachParentSelectorToBubbledBody($node);

        if ($node instanceof SupportsNode) {
            return new SupportsNode(
                $node->condition,
                $this->normalizeBubblingChildren($node->body, $selector),
            );
        }

        if ($node instanceof DirectiveNode && $node->hasBlock) {
            if ($this->isBubblingDirective($node) && ! $attachParentSelector) {
                return $node;
            }

            return new DirectiveNode(
                $node->name,
                $node->prelude,
                $this->normalizeBubblingChildren($node->body, $selector),
                true,
            );
        }

        if ($node instanceof RuleNode && $this->isBubblingRuleNode($node)) {
            return new DirectiveNode(
                'font-face',
                '',
                $node->children,
                true,
            );
        }

        return $node;
    }

    /**
     * @return array<int, string>
     */
    public function drainDeferredAtRuleEscapes(): array
    {
        $outputState     = $this->ctx->outputState;
        $deferredEscapes = array_pop($outputState->deferral->atRuleStack) ?? [];
        $outsideChunks   = [];

        foreach ($deferredEscapes as $deferredEscape) {
            $levels = $deferredEscape->levels;
            $chunk  = $deferredEscape->chunk;

            if ($chunk === '') {
                continue;
            }

            if ($levels <= 1) {
                $outsideChunks[] = $chunk;

                continue;
            }

            $parentAtRuleIndex = count($outputState->deferral->atRuleStack) - 1;

            if ($parentAtRuleIndex >= 0) {
                $outputState->deferral->atRuleStack[$parentAtRuleIndex][] = new DeferredAtRuleChunk(
                    $levels - 1,
                    $chunk,
                );
            } else {
                $outsideChunks[] = $chunk;
            }
        }

        return $outsideChunks;
    }

    public function resolveSupportsCondition(string $condition, Environment $env): string
    {
        return $this->text->resolveSupportsCondition($condition, $env);
    }

    /**
     * @return array{chunk: string, escapeLevels: int}
     */
    public function compileAtRootBody(AtRootNode $node, Environment $env): array
    {
        $chunks          = [];
        $rootCtx         = new TraversalContext($env, 0);
        $parentSelector  = $this->getCurrentParentSelector($env);
        $currentStack    = $this->getCurrentAtRuleStack($env);
        $normalizedRules = $this->normalizeAtRootQueryRules($node->queryRules);
        $stack           = $this->filterAtRootStackByQuery($currentStack, $node->queryMode, $normalizedRules);
        $escapeLevels    = count($currentStack);
        $keepRuleContext = $this->shouldKeepAtRootRuleContext($node->queryMode, $normalizedRules);

        $env->enterScope();
        $env->getCurrentScope()->setVariableLocal(
            '__at_root_context',
            $this->ctx->valueFactory->createBooleanNode(true),
        );

        if (! $keepRuleContext) {
            $env->getCurrentScope()->setVariableLocal(
                '__at_root_without_rule',
                $this->ctx->valueFactory->createBooleanNode(true),
            );
        }

        try {
            foreach ($node->body as $child) {
                $compiled = $this->compileAtRootChild(
                    $child,
                    $parentSelector,
                    $keepRuleContext,
                    $stack,
                    $rootCtx,
                );

                if ($compiled !== '') {
                    $chunks[] = $compiled;
                }
            }
        } finally {
            $env->exitScope();
        }

        return [
            'chunk'        => implode("\n", $chunks),
            'escapeLevels' => $escapeLevels,
        ];
    }

    public function collectExtends(AstNode $node, Environment $env): void
    {
        $this->extends->collectExtends($node, $env);
    }

    public function finalizeCollectedExtends(): void
    {
        $this->extends->finalizeCollectedExtends();
    }

    public function getCurrentParentSelector(Environment $env): ?string
    {
        if (! $env->getCurrentScope()->hasVariable('__parent_selector')) {
            return null;
        }

        $parentSelectorNode = $env->getCurrentScope()->getVariable('__parent_selector');

        if (! ($parentSelectorNode instanceof StringNode)) {
            return null;
        }

        return trim($parentSelectorNode->value);
    }

    /**
     * @return array<int, string>
     */
    public function splitTopLevelSelectorList(string $selector, bool $trim = true): array
    {
        return $this->tokenizer->splitAtTopLevel($selector, [','], handleQuotes: true, trim: $trim);
    }

    /**
     * @return array{property: string, value: ?string}|null
     */
    public function parseNestedPropertyBlockSelector(string $selector): ?array
    {
        $parsed = $this->text->parseColonSeparatedPair($selector);

        if ($parsed === null) {
            return null;
        }

        $name  = $parsed['name'];
        $value = $parsed['value'];

        if (! $this->isValidNestedPropertyName($name)) {
            return null;
        }

        if ($value === '') {
            return ['property' => $name, 'value' => null];
        }

        $colonPosition = strpos($selector, ':');

        if (
            $colonPosition !== false
            && $colonPosition + 1 < strlen($selector)
            && $selector[$colonPosition + 1] !== ' '
        ) {
            return null;
        }

        return ['property' => $name, 'value' => $value];
    }

    /**
     * @param array<int, AstNode> $children
     */
    public function compileNestedPropertyBlockChildren(
        array $children,
        Environment $env,
        int $indent,
        string $baseProperty,
        ?string $baseValue = null,
    ): string {
        $outputState    = $this->ctx->outputState;
        $activeProperty = $outputState->nestedPropertyName;

        if ($activeProperty === null) {
            return $this->renderNestedPropertyBlock($children, $env, $indent, $baseProperty, $baseValue);
        }

        $outputState->nestedPropertyName = null;

        try {
            return $this->renderNestedPropertyBlock(
                $children,
                $env,
                $indent,
                $activeProperty . '-' . $baseProperty,
                $baseValue,
            );
        } finally {
            $outputState->nestedPropertyName = $activeProperty;
        }
    }

    public function resolveNestedSelector(string $selector, string $parentSelector): string
    {
        return SelectorHelper::resolveNested($selector, $parentSelector);
    }

    public function combineNestedSelectorWithParent(string $selector, string $parentSelector): string
    {
        $selectorParts = $this->splitTopLevelSelectorList($selector, trim: false);
        $parentParts   = $this->splitTopLevelSelectorList($parentSelector, trim: false);

        if ($selectorParts === [] || $parentParts === []) {
            return $selector;
        }

        $combined = [];
        $breaks   = [];

        foreach ($parentParts as $pi => $parentPart) {
            $parentHasBreak = str_starts_with($parentPart, "\n");
            $trimmedParent  = ltrim($parentPart);

            foreach ($selectorParts as $cj => $selectorPart) {
                $childHasBreak   = str_starts_with($selectorPart, "\n");
                $trimmedSelector = ltrim($selectorPart);

                $needsBreak = ($parentHasBreak && $pi > 0) || ($childHasBreak && $cj > 0);

                $combined[] = $trimmedParent . ' ' . $trimmedSelector;
                $breaks[]   = $needsBreak;
            }
        }

        $result = '';

        foreach ($combined as $i => $part) {
            if ($i > 0) {
                $result .= $breaks[$i] ? ",\n" : ', ';
            }

            $result .= $part;
        }

        return $result;
    }

    public function applyExtendsToSelector(string $selector): string
    {
        if (! $this->extends->hasCollectedExtends() && ! str_contains($selector, '%')) {
            return $selector;
        }

        return $this->extends->applyExtendsToSelector($selector);
    }

    public function normalizeSelectorList(string $selector): string
    {
        $parts = $this->splitTopLevelSelectorList($selector, trim: false);

        if ($parts === []) {
            return $selector;
        }

        $keepIndices = [];

        foreach ($parts as $i => $part) {
            if (trim($part) !== '') {
                $keepIndices[] = $i;
            }
        }

        if ($keepIndices === []) {
            return $selector;
        }

        $result = StringHelper::trimPreservingEscapeTerminator($parts[$keepIndices[0]]);
        $count  = count($keepIndices);

        for ($idx = 1; $idx < $count; $idx++) {
            $prev       = $keepIndices[$idx - 1];
            $curr       = $keepIndices[$idx];
            $hasNewline = false;

            for ($j = $prev + 1; $j < $curr; $j++) {
                if (str_contains($parts[$j], "\n")) {
                    $hasNewline = true;

                    break;
                }
            }

            if (! $hasNewline && str_ends_with($parts[$prev], "\n")) {
                $hasNewline = true;
            }

            if (! $hasNewline) {
                $currPart    = $parts[$curr];
                $currTrimmed = ltrim($currPart);
                $leadingLen  = strlen($currPart) - strlen($currTrimmed);
                $leading     = substr($currPart, 0, $leadingLen);

                if (str_contains($leading, "\n")) {
                    $hasNewline = true;
                }
            }

            $result .= $hasNewline ? ",\n" : ', ';
            $result .= StringHelper::trimPreservingEscapeTerminator($parts[$curr]);
        }

        return $result;
    }

    public function optimizeRuleBlock(string $ruleBlock): string
    {
        return $this->optimizer->optimizeRuleBlock($ruleBlock, $this->options->style === Style::COMPRESSED);
    }

    public function optimizeAdjacentSiblingRuleBlocks(string $block): string
    {
        return $this->optimizer->optimizeAdjacentSiblingRuleBlocks($block);
    }

    public function hasBogusTopLevelCombinatorSequence(string $selector): bool
    {
        return $this->tokenizer->hasBogusTopLevelCombinatorSequence($selector);
    }

    public function hasAdjacentCompoundSelectors(string $selector): bool
    {
        return $this->tokenizer->hasAdjacentCompoundSelectors($selector);
    }

    public function hasBogusSelectorPseudoCombinator(string $selector): bool
    {
        return $this->tokenizer->hasBogusSelectorPseudoCombinator($selector);
    }

    private function isValidNestedPropertyName(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        $length = strlen($name);
        $index  = 0;

        while ($index < $length && $name[$index] === '-') {
            $index++;
        }

        if ($index === $length) {
            return false;
        }

        $first = $name[$index];

        if (! ctype_alpha($first) && $first !== '_') {
            return false;
        }

        for ($i = $index + 1; $i < $length; $i++) {
            $char = $name[$i];

            if (! ctype_alpha($char) && ! ctype_digit($char) && $char !== '_' && $char !== '-') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, AstNode> $children
     */
    private function renderNestedPropertyBlock(
        array $children,
        Environment $env,
        int $indent,
        string $baseProperty,
        ?string $baseValue,
    ): string {
        $output           = '';
        $prefix           = $this->render->indentPrefix($indent);
        $hasOutput        = false;
        $lastRenderedLine = null;

        $s = $env->getCurrentScope();
        if (! $s->hasVariable('__parent_selector')) {
            $s->setVariableLocal('__parent_selector', new StringNode(''));
        }

        $s->setVariableLocal('__flow_control_declaration_guard', true);

        if ($baseValue !== null) {
            $evaluatedBaseValue = $this->evaluateNestedPropertyBaseValue($baseValue, $env);

            $this->render->appendChunk($output, $prefix . $baseProperty . ': ' . $evaluatedBaseValue . ';');

            $hasOutput = true;
        }

        foreach ($children as $child) {
            if ($child instanceof VariableDeclarationNode) {
                $env->getCurrentScope()->setVariable(
                    $child->name,
                    $this->valueEvaluator->evaluate($child->value, $env),
                    $child->global,
                    $child->default,
                );

                continue;
            }

            if ($child instanceof ModuleVarDeclarationNode) {
                $this->moduleVariableAssigner->assign($child, $env);

                continue;
            }

            if ($child instanceof DeclarationNode) {
                $property       = $this->text->interpolateText($child->property, $env);
                $fullProperty   = $baseProperty . '-' . $property;
                $evaluatedValue = $this->valueEvaluator->evaluate($child->value, $env);

                if ($evaluatedValue instanceof NullNode) {
                    continue;
                }

                if ($this->options->style === Style::COMPRESSED && ! str_starts_with($fullProperty, '--')) {
                    $evaluatedValue = $this->cssArgumentEvaluator->compressNamedColorsForOutput($evaluatedValue);
                }

                $value     = $this->valueFormatter->format($evaluatedValue, $env);
                $value     = $this->text->interpolateText($value, $env);
                $important = $child->important ? ' !important' : '';
                $line      = $prefix . $fullProperty . ': ' . $value . $important . ';';

                if ($hasOutput) {
                    $this->render->appendChunk($output, "\n");
                }

                $this->render->appendChunk($output, $line, $child);

                $hasOutput         = true;
                $lastRenderedLine = $child->line;

                continue;
            }

            if ($child instanceof ForNode) {
                $fromNode = $this->valueEvaluator->evaluate($child->from, $env);

                if (! $fromNode instanceof NumberNode) {
                    $formatted = $this->valueFormatter->format($fromNode, $env);

                    if (! is_numeric($formatted)) {
                        throw new InvalidLoopBoundaryException($formatted);
                    }

                    $fromNode = new NumberNode((float) $formatted);
                }

                $toNode = $this->valueEvaluator->evaluate($child->to, $env);

                if (! $toNode instanceof NumberNode) {
                    $formatted = $this->valueFormatter->format($toNode, $env);

                    if (! is_numeric($formatted)) {
                        throw new InvalidLoopBoundaryException($formatted);
                    }

                    $toNode = new NumberNode((float) $formatted);
                }

                $unit = $fromNode->unit;
                $from = (int) $fromNode->value;
                $to   = (int) $toNode->value;
                $step = $from <= $to ? 1 : -1;

                if (! $child->inclusive) {
                    $to -= $step;
                }

                $env->enterScope();

                try {
                    for ($i = $from; $step > 0 ? $i <= $to : $i >= $to; $i += $step) {
                        $env->getCurrentScope()->setVariable($child->variable, new NumberNode($i, $unit));

                        $chunk = $this->compileNestedPropertyBlockChildren(
                            $child->body,
                            $env,
                            $indent,
                            $baseProperty,
                        );

                        if ($chunk !== '') {
                            if ($hasOutput) {
                                $this->render->appendChunk($output, "\n");
                            }

                            $this->render->appendChunk($output, $chunk, $child);

                            $hasOutput = true;
                        }
                    }
                } finally {
                    $env->exitScope();
                }

                continue;
            }

            if ($child instanceof RuleNode) {
                $childSelector  = $this->text->interpolateText($child->selector, $env);
                $nestedProperty = $this->parseNestedPropertyBlockSelector($childSelector);

                if ($nestedProperty === null) {
                    continue;
                }

                $chunk = $this->compileNestedPropertyBlockChildren(
                    $child->children,
                    $env,
                    $indent,
                    $baseProperty . '-' . $nestedProperty['property'],
                    $nestedProperty['value'],
                );
            } elseif ($child instanceof Visitable) {
                $chunk = $this->compileNestedPropertyBlockChild($child, $env, $indent, $baseProperty);
            } else {
                continue;
            }

            if ($chunk === '') {
                continue;
            }

            if ($child instanceof CommentNode && $lastRenderedLine !== null && $child->line === $lastRenderedLine) {
                $this->render->appendChunk($output, ' ' . ltrim($chunk), $child);

                $lastRenderedLine = $child->line;

                continue;
            }

            if ($hasOutput) {
                $this->render->appendChunk($output, "\n");
            }

            $this->render->appendChunk($output, $chunk, $child);

            $hasOutput = true;
        }

        return $output;
    }

    private function compileNestedPropertyBlockChild(
        Visitable $child,
        Environment $env,
        int $indent,
        string $baseProperty,
    ): string {
        $outputState      = $this->ctx->outputState;
        $previousProperty = $outputState->nestedPropertyName;

        $outputState->nestedPropertyName = $baseProperty;

        try {
            return $this->render->trimAndAdjustState(
                $this->dispatcher->compileWithContext($child, new TraversalContext($env, $indent)),
            );
        } finally {
            $outputState->nestedPropertyName = $previousProperty;
        }
    }

    private function normalizeAtRuleText(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $lastIndex = strlen($value) - 1;
        $first     = $value[0];
        $last      = $value[$lastIndex];

        // Explicit comparisons are faster than in_array array allocation + linear search
        if (
            ($first === ' ' || $first === "\t" || $first === "\n" || $first === "\r" || $first === "\0" || $first === "\x0B")
            || ($last === ' ' || $last === "\t" || $last === "\n" || $last === "\r" || $last === "\0" || $last === "\x0B")
        ) {
            return trim($value);
        }

        return $value;
    }

    /**
     * @param list<AtRuleContextEntry> $stack
     * @param array<int, string> $normalizedRules
     * @return list<AtRuleContextEntry>
     */
    private function filterAtRootStackByQuery(array $stack, ?string $queryMode, array $normalizedRules): array
    {
        if ($queryMode === null || $normalizedRules === []) {
            return $stack;
        }

        if (in_array('all', $normalizedRules, true)) {
            return $queryMode === 'with' ? $stack : [];
        }

        $filtered = [];

        foreach ($stack as $entry) {
            $matchesRule = $this->matchesAtRootQueryRule($entry, $normalizedRules);

            if (($queryMode === 'with') === $matchesRule) {
                $filtered[] = $entry;
            }
        }

        return $filtered;
    }

    /**
     * @param array<int, string> $values
     */
    private function implodeUniqueSelectorList(array $values): string
    {
        return implode(', ', $this->uniqueValues($values));
    }

    /**
     * @param array<int, string> $queryRules
     * @return array<int, string>
     */
    private function normalizeAtRootQueryRules(array $queryRules): array
    {
        $normalizedRules = [];

        foreach ($queryRules as $rule) {
            $rule = strtolower(trim($rule));

            if ($rule !== '') {
                $normalizedRules[] = $rule;
            }
        }

        return $this->uniqueValues($normalizedRules);
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function uniqueValues(array $values): array
    {
        return array_values(array_unique($values));
    }

    /**
     * @param array<int, string> $rules
     */
    private function matchesAtRootQueryRule(AtRuleContextEntry $entry, array $rules): bool
    {
        if (in_array('rule', $rules, true)) {
            return false;
        }

        if ($entry->type === 'supports') {
            return in_array('supports', $rules, true);
        }

        $name = $entry->name ?? '';

        if ($name !== '' && in_array($name, $rules, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, string> $normalizedRules
     */
    private function shouldKeepAtRootRuleContext(?string $queryMode, array $normalizedRules): bool
    {
        if ($queryMode === null || $normalizedRules === []) {
            return false;
        }

        $rulesSet = array_fill_keys($normalizedRules, true);

        if ($queryMode === 'with') {
            return isset($rulesSet['rule']) || isset($rulesSet['all']);
        }

        if ($queryMode === 'without') {
            return ! isset($rulesSet['rule']) && ! isset($rulesSet['all']);
        }

        return false;
    }

    private function normalizeAtRootChild(AstNode $child, ?string $parentSelector, bool $keepRuleContext): AstNode
    {
        if (! $keepRuleContext || $parentSelector === null || $parentSelector === '') {
            return $child;
        }

        if ($child instanceof RuleNode) {
            $resolvedSelector = str_contains($child->selector, '&')
                ? SelectorHelper::resolveNested($child->selector, $parentSelector)
                : $this->combineNestedSelectorWithParent($child->selector, $parentSelector);

            return new RuleNode(
                $resolvedSelector,
                $child->children,
                $child->line,
                $child->column,
            );
        }

        if ($child instanceof AtRootNode) {
            return $child;
        }

        return new RuleNode($parentSelector, [$child]);
    }

    /**
     * @param list<AtRuleContextEntry> $stack
     */
    private function compileAtRootChild(
        AstNode $child,
        ?string $parentSelector,
        bool $keepRuleContext,
        array $stack,
        TraversalContext $rootCtx,
    ): string {
        $rootChild        = $this->normalizeAtRootChild($child, $parentSelector, $keepRuleContext);
        $wrappedRootChild = $this->wrapNodeWithAtRuleStack($rootChild, $stack);

        return $this->render->trimTrailingNewlines(
            $this->dispatcher->compileWithContext($wrappedRootChild, $rootCtx),
        );
    }

    /**
     * @param list<AtRuleContextEntry> $stack
     * @return Visitable&AstNode
     */
    private function wrapNodeWithAtRuleStack(AstNode $node, array $stack): Visitable
    {
        /** @var Visitable&AstNode $wrapped */
        $wrapped = $node;

        for ($index = count($stack) - 1; $index >= 0; $index--) {
            $entry = $stack[$index];

            if ($entry->type === 'supports') {
                $wrapped = new SupportsNode($entry->condition ?? '', [$wrapped]);

                continue;
            }

            $wrapped = new DirectiveNode(
                $entry->name ?? '',
                $entry->prelude ?? '',
                [$wrapped],
                true,
            );
        }

        return $wrapped;
    }

    private function isBubblingDirective(DirectiveNode $node): bool
    {
        return $node->hasBlock;
    }

    private function isBubblingRuleNode(RuleNode $node): bool
    {
        return $node->selector === '@font-face';
    }

    /**
     * @param array<int, AstNode> $children
     *
     * @return list<AstNode>
     */
    private function normalizeBubblingChildren(array $children, string $selector): array
    {
        $result = [];
        $group  = [];

        foreach ($children as $child) {
            if (
                $child instanceof RuleNode
                || $child instanceof DirectiveNode
                || $child instanceof SupportsNode
                || $child instanceof AtRootNode
            ) {
                if ($group !== []) {
                    $result[] = new RuleNode($selector, $group);

                    $group = [];
                }

                $result[] = $this->normalizeBubblingChild($child, $selector);

                continue;
            }

            $group[] = $child;
        }

        if ($group !== []) {
            $result[] = new RuleNode($selector, $group);
        }

        return $result;
    }

    private function normalizeBubblingChild(AstNode $child, string $selector): AstNode
    {
        if ($child instanceof RuleNode) {
            $resolvedSelector = str_contains($child->selector, '&')
                ? SelectorHelper::resolveNested($child->selector, $selector)
                : $this->combineNestedSelectorWithParent($child->selector, $selector);

            return new RuleNode(
                $resolvedSelector,
                $child->children,
                $child->line,
                $child->column,
            );
        }

        if ($child instanceof DirectiveNode || $child instanceof SupportsNode) {
            return $this->normalizeBubblingNodeForSelector($child, $selector);
        }

        return $child;
    }

    private function shouldAttachParentSelectorToBubbledBody(AstNode $node): bool
    {
        if ($node instanceof SupportsNode) {
            return true;
        }

        if (! ($node instanceof DirectiveNode)) {
            return false;
        }

        $name = strtolower($node->name);

        return $name !== 'font-face'
            && $name !== 'keyframes'
            && ! str_ends_with($name, '-keyframes');
    }

    private function mergeMediaPreludeParts(string $outerPart, string $innerPart): string
    {
        if ($outerPart === '' || strtolower($outerPart) === 'all') {
            return $innerPart;
        }

        if ($innerPart === '' || strtolower($innerPart) === 'all') {
            return $outerPart;
        }

        return $outerPart . ' and ' . $innerPart;
    }

    private function normalizeAtRuleStackEntry(mixed $entry): ?AtRuleContextEntry
    {
        if ($entry instanceof AtRuleContextEntry) {
            return $entry;
        }

        if (! is_array($entry) || ! isset($entry['type']) || ! is_string($entry['type'])) {
            return null;
        }

        $type = match ($entry['type']) {
            'directive',
            'supports' => $entry['type'],
            default    => strtolower($this->normalizeAtRuleText($entry['type'])),
        };

        if ($type === 'directive') {
            if (! isset($entry['name']) || ! is_string($entry['name'])) {
                return null;
            }

            $name = match ($entry['name']) {
                'media' => 'media',
                default => strtolower($this->normalizeAtRuleText($entry['name'])),
            };

            $prelude = isset($entry['prelude']) && is_string($entry['prelude'])
                ? $this->normalizeAtRuleText($entry['prelude'])
                : '';

            return AtRuleContextEntry::directive($name, $prelude);
        }

        if ($type !== 'supports' || ! isset($entry['condition']) || ! is_string($entry['condition'])) {
            return null;
        }

        return AtRuleContextEntry::supports($this->normalizeAtRuleText($entry['condition']));
    }

    private function evaluateNestedPropertyBaseValue(string $value, Environment $env): string
    {
        try {
            $evaluated = $this->valueEvaluator->evaluate($this->parser->parseInlineExpression($value), $env);
        } catch (SassThrowable) {
            return $value;
        }

        if ($evaluated instanceof NullNode) {
            return '';
        }

        $formatted = $this->valueFormatter->format($evaluated, $env);

        if (str_contains($formatted, '#{')) {
            $formatted = $this->text->interpolateText($formatted, $env);
        }

        return $formatted;
    }
}
