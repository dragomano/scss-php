<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StatementNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Runtime\DeferredAtRuleChunk;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Style;
use Bugo\SCSS\Utils\SelectorHelper;
use Bugo\SCSS\Utils\SelectorTokenizer;

use function array_map;
use function array_unique;
use function array_values;
use function count;
use function ctype_alpha;
use function ctype_digit;
use function implode;
use function in_array;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
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
    ) {
        $this->optimizer = new SelectorRuleOptimizer();
    }

    public function resolveDirectivePrelude(string $prelude, Environment $env): string
    {
        return $this->text->resolveDirectivePrelude($prelude, $env);
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
            $outerPart = trim($outerPart);

            foreach ($innerParts as $innerPart) {
                $innerPart = trim($innerPart);

                $combined[] = $outerPart . ' and ' . $innerPart;
            }
        }

        return $this->implodeUniqueSelectorList($combined);
    }

    public function isBubblingAtRuleNode(AstNode $node): bool
    {
        if ($node instanceof SupportsNode) {
            return true;
        }

        return $node instanceof DirectiveNode && $this->isBubblingDirective($node);
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
                array_map(
                    fn(AstNode $child): AstNode => $this->normalizeBubblingChild($child, $selector, $attachParentSelector),
                    $node->body,
                ),
            );
        }

        if ($node instanceof DirectiveNode && $node->hasBlock) {
            return new DirectiveNode(
                $node->name,
                $node->prelude,
                array_map(
                    fn(AstNode $child): AstNode => $this->normalizeBubblingChild(
                        $child,
                        $selector,
                        $attachParentSelector,
                    ),
                    $node->body,
                ),
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
        $stack           = $this->filterAtRootStackByQuery($currentStack, $node->queryMode, $node->queryRules);
        $escapeLevels    = count($currentStack);
        $keepRuleContext = $this->shouldKeepAtRootRuleContext($node->queryMode, $node->queryRules);

        foreach ($node->body as $child) {
            $compiled = $this->compileAtRootChild(
                $child,
                $parentSelector,
                $keepRuleContext,
                $stack,
                $rootCtx,
                $env,
            );

            if ($compiled !== '') {
                $chunks[] = $compiled;
            }
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
    public function splitTopLevelSelectorList(string $selector): array
    {
        return $this->tokenizer->splitAtTopLevel($selector, [','], handleQuotes: true);
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
        $output    = '';
        $prefix    = $this->render->indentPrefix($indent);
        $hasOutput = false;

        if ($baseValue !== null) {
            $this->render->appendChunk($output, $prefix . $baseProperty . ': ' . $baseValue . ';');
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

                $hasOutput = true;

                continue;
            }

            if (! $child instanceof RuleNode) {
                continue;
            }

            $childSelector  = $this->text->interpolateText($child->selector, $env);
            $nestedProperty = $this->parseNestedPropertyBlockSelector($childSelector);

            if ($nestedProperty === null) {
                continue;
            }

            $nestedBase = $baseProperty . '-' . $nestedProperty['property'];

            $chunk = $this->compileNestedPropertyBlockChildren(
                $child->children,
                $env,
                $indent,
                $nestedBase,
                $nestedProperty['value'],
            );

            if ($chunk === '') {
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

    public function resolveNestedSelector(string $selector, string $parentSelector): string
    {
        return SelectorHelper::resolveNested($selector, $parentSelector);
    }

    public function combineNestedSelectorWithParent(string $selector, string $parentSelector): string
    {
        $selectorParts = $this->splitTopLevelSelectorList($selector);
        $parentParts   = $this->splitTopLevelSelectorList($parentSelector);

        if ($selectorParts === [] || $parentParts === []) {
            return $selector;
        }

        $combined = [];

        foreach ($parentParts as $parentPart) {
            $trimmedParent = trim($parentPart);

            foreach ($selectorParts as $selectorPart) {
                $trimmedSelector = trim($selectorPart);
                $combined[] = $trimmedParent . ' ' . $trimmedSelector;
            }
        }

        return $this->implodeUniqueSelectorList($combined);
    }

    public function applyExtendsToSelector(string $selector): string
    {
        if (! $this->extends->hasCollectedExtends() && ! str_contains($selector, '%')) {
            return $selector;
        }

        return $this->extends->applyExtendsToSelector($selector);
    }

    public function optimizeRuleBlock(string $ruleBlock): string
    {
        return $this->optimizer->optimizeRuleBlock($ruleBlock);
    }

    public function optimizeAdjacentSiblingRuleBlocks(string $block): string
    {
        return $this->optimizer->optimizeAdjacentSiblingRuleBlocks($block);
    }

    public function hasBogusTopLevelCombinatorSequence(string $selector): bool
    {
        return $this->tokenizer->hasBogusTopLevelCombinatorSequence($selector);
    }

    private function isValidNestedPropertyName(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        $length = strlen($name);
        $index  = 0;

        if ($name[0] === '-') {
            if ($length === 1) {
                return false;
            }

            $index = 1;
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
     * @param array<int, string> $queryRules
     * @return list<AtRuleContextEntry>
     */
    private function filterAtRootStackByQuery(array $stack, ?string $queryMode, array $queryRules): array
    {
        if ($queryMode === null || $queryRules === []) {
            return $stack;
        }

        $normalizedRules = $this->normalizeAtRootQueryRules($queryRules);

        if ($normalizedRules === []) {
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
     * @param array<int, string> $queryRules
     */
    private function shouldKeepAtRootRuleContext(?string $queryMode, array $queryRules): bool
    {
        if ($queryMode === null || $queryRules === []) {
            return false;
        }

        $rulesSet = array_fill_keys($this->normalizeAtRootQueryRules($queryRules), true);

        if ($rulesSet === []) {
            return false;
        }

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

        if ($child instanceof RuleNode || $child instanceof AtRootNode) {
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
        Environment $env,
    ): string {
        $rootChild        = $this->normalizeAtRootChild($child, $parentSelector, $keepRuleContext);
        $wrappedRootChild = $this->wrapNodeWithAtRuleStack($rootChild, $stack);

        $env->enterScope();
        $env->getCurrentScope()->setVariableLocal(
            '__at_root_context',
            $this->ctx->valueFactory->createBooleanNode(true),
        );

        $compiled = $this->render->trimTrailingNewlines(
            $this->dispatcher->compileWithContext($wrappedRootChild, $rootCtx),
        );

        $env->exitScope();

        return $compiled;
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
        return match (strtolower($node->name)) {
            'container',
            'media',
            'keyframes',
            '-webkit-keyframes',
            '-moz-keyframes',
            '-o-keyframes' => true,
            default        => false,
        };
    }

    private function normalizeBubblingChild(AstNode $child, string $selector, bool $attachParentSelector): AstNode
    {
        if ($child instanceof RuleNode) {
            if (! $attachParentSelector) {
                return $child;
            }

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

        if (
            $child instanceof AtRootNode
            || $child instanceof DirectiveNode
            || $child instanceof SupportsNode
        ) {
            return $child;
        }

        return new RuleNode($selector, [$child]);
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

        return $name === 'container' || $name === 'media';
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
}
