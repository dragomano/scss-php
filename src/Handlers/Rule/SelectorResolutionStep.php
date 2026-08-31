<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Rule;

use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;

use function array_pop;
use function ctype_digit;
use function implode;
use function is_array;
use function str_contains;
use function str_ends_with;
use function strlen;
use function trim;

final readonly class SelectorResolutionStep implements CompilationStepInterface
{
    public function __construct(
        private Evaluator $evaluation,
        private Selector $selector,
        private Render $render,
        private Context $context,
    ) {}

    public function execute(RuleCompilationContext $ruleCtx): ?string
    {
        $node  = $ruleCtx->node;
        $env   = $ruleCtx->outerCtx->env;
        $scope = $env->getCurrentScope();

        $selector = str_contains($node->selector, '#{')
            ? $this->evaluation->interpolateText($node->selector, $env)
            : $node->selector;
        $selector = $this->selector->normalizeSelectorAttributes($selector);

        // Normalize scientific notation in keyframe selectors (13E+1% → 13e+1%)
        if ($this->isInsideKeyframes($scope)) {
            $selector = $this->normalizeScientificNotation($selector);
        }

        $scopeParentSelector = $scope->getStringVariable('__parent_selector')?->value;
        $atRootVar           = $scope->getAstVariable('__at_root_context');
        $isAtRootContext     = $atRootVar instanceof BooleanNode && $atRootVar->value;

        if (
            $isAtRootContext
            && $scopeParentSelector !== null
            && str_contains($selector, '&')
            && ! str_contains($scopeParentSelector, '%')
        ) {
            $selector = $this->selector->resolveNestedSelector($selector, $scopeParentSelector);
        }

        $ruleCtx->parentSelector    = $selector;
        $ruleCtx->selector          = $this->selector->normalizeSelectorList(
            $this->selector->applyExtendsToSelector($selector),
        );

        $trimmedSelector = trim($ruleCtx->selector);

        $ruleCtx->omitOwnRuleOutput = $this->selector->hasBogusTopLevelCombinatorSequence($ruleCtx->selector)
            || $this->selector->hasBogusSelectorPseudoCombinator($ruleCtx->selector)
            || str_ends_with($trimmedSelector, '>')
            || str_ends_with($trimmedSelector, '+')
            || str_ends_with($trimmedSelector, '~');

        if ($ruleCtx->omitOwnRuleOutput) {
            $this->context->logWarning(
                implode(', ', [
                    "The selector \"$selector\" uses multiple consecutive combinators",
                    'which is deprecated and will be an error in a future release.',
                ]),
                $node->line,
            );
        }

        if ($this->selector->hasAdjacentCompoundSelectors($ruleCtx->selector)) {
            $this->context->logWarning(
                "The selector \"{$ruleCtx->selector}\" uses adjacent compound selectors "
                . '(e.g. "[attr]a"). This is not valid CSS and will be an error in a future release. '
                . 'Add a combinator or whitespace between the compound selectors.',
                $node->line,
            );
        }

        if ($ruleCtx->selector === '') {
            $outputState = $this->render->outputState();

            array_pop($outputState->deferral->atRootStack);
            array_pop($outputState->deferral->bubblingStack);

            return '';
        }

        $parentSelectorValue = str_replace("\n", ' ', $ruleCtx->parentSelector);

        $scope->setVariableLocal('__parent_selector', new StringNode($parentSelectorValue));

        return null;
    }

    private function isInsideKeyframes(Scope $scope): bool
    {
        if (! $scope->hasVariable('__at_rule_stack')) {
            return false;
        }

        $atRuleStack = $scope->getVariable('__at_rule_stack');

        if (! is_array($atRuleStack)) {
            return false;
        }

        /** @var list<AtRuleContextEntry|array<string, mixed>> $atRuleStack */
        foreach ($atRuleStack as $entry) {
            if ($entry instanceof AtRuleContextEntry && $entry->name === 'keyframes') {
                return true;
            }
        }

        return false;
    }

    private function normalizeScientificNotation(string $selector): string
    {
        $length = strlen($selector);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if (($char === 'E' || $char === 'e') && $i > 0 && ctype_digit($selector[$i - 1])) {
                $j = $i + 1;

                if ($j < $length && ($selector[$j] === '+' || $selector[$j] === '-')) {
                    $j++;
                }

                if ($j < $length && ctype_digit($selector[$j])) {
                    $result .= 'e';

                    continue;
                }
            }

            $result .= $char;
        }

        return $result;
    }
}
