<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Rule;

use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;

use function array_pop;
use function implode;
use function str_contains;

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
        $ruleCtx->selector          = $this->selector->applyExtendsToSelector($selector);
        $ruleCtx->omitOwnRuleOutput = $this->selector->hasBogusTopLevelCombinatorSequence($ruleCtx->selector);

        if ($ruleCtx->omitOwnRuleOutput) {
            $this->context->logWarning(
                implode(', ', [
                    "The selector \"$selector\" uses multiple consecutive combinators",
                    'which is deprecated and will be an error in a future release.',
                ]),
                $node->line,
            );
        }

        if ($ruleCtx->selector === '') {
            $outputState = $this->render->outputState();

            array_pop($outputState->deferral->atRootStack);
            array_pop($outputState->deferral->bubblingStack);

            return '';
        }

        $scope->setVariableLocal('__parent_selector', new StringNode($ruleCtx->parentSelector));

        return null;
    }
}
