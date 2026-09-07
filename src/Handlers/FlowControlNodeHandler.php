<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Exceptions\InvalidLoopBoundaryException;
use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\EachNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\LoopIterator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Utils\UnitConverter;

use function is_numeric;
use function str_ends_with;

final readonly class FlowControlNodeHandler
{
    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Evaluator $evaluation,
        private Render $render,
        private LoopIterator $loopIterator,
        private DeferredChunkManager $chunks,
        private Selector $selector,
    ) {}

    public function handleIf(IfNode $node, TraversalContext $ctx): string
    {
        $output = '';
        $first  = true;
        $branch = null;

        if ($this->evaluation->evaluateFunctionCondition($node->condition, $ctx->env)) {
            $branch = $node->body;
        } else {
            foreach ($node->elseIfBranches as $elseIfBranch) {
                $condition = $elseIfBranch->condition;
                $body      = $elseIfBranch->body;

                if ($this->evaluation->evaluateFunctionCondition($condition, $ctx->env)) {
                    $branch = $body;

                    break;
                }
            }

            $branch ??= $node->elseBody;
        }

        $this->compileBody($branch, $ctx, $output, $first);

        return $output;
    }

    public function handleEach(EachNode $node, TraversalContext $ctx): string
    {
        $output = '';
        $first  = true;

        $iterableValue = $this->evaluation->evaluateValue($node->list, $ctx->env);

        /** @var array<int, AstNode> $items */
        $items = $this->evaluation->eachIterableItems($iterableValue);

        $ctx->env->enterScope();

        try {
            $ctx->env->getCurrentScope()->markAsFlowControlScope();

            $bodyCtx = new TraversalContext($ctx->env, $ctx->indent);

            foreach ($items as $item) {
                $this->evaluation->assignEachVariables($node->variables, $item, $ctx->env);

                $this->compileBody($node->body, $bodyCtx, $output, $first);
            }
        } finally {
            $ctx->env->exitScope();
        }

        return $output;
    }

    public function handleFor(ForNode $node, TraversalContext $ctx): string
    {
        $output   = '';
        $first    = true;
        $fromNode = $this->toLoopNumber($node->from, $ctx->env);
        $toNode   = $this->toLoopNumber($node->to, $ctx->env);
        $unit     = $fromNode->unit;
        $from     = (int) $fromNode->value;
        $to       = (int) UnitConverter::convert((float) $toNode->value, $toNode->unit, $unit);

        $ctx->env->enterScope();

        try {
            $ctx->env->getCurrentScope()->markAsFlowControlScope();

            $bodyCtx = new TraversalContext($ctx->env, $ctx->indent);

            $this->loopIterator->forLoop(
                $from,
                $to,
                $node->inclusive,
                function (int $i) use ($node, $unit, $ctx, $bodyCtx, &$output, &$first) {
                    $ctx->env->getCurrentScope()->setVariable($node->variable, new NumberNode($i, $unit));

                    $this->compileBody($node->body, $bodyCtx, $output, $first);

                    return true;
                },
            );
        } finally {
            $ctx->env->exitScope();
        }

        return $output;
    }

    public function handleWhile(WhileNode $node, TraversalContext $ctx): string
    {
        $output  = '';
        $first   = true;
        $bodyCtx = new TraversalContext($ctx->env, $ctx->indent);

        $this->loopIterator->whileLoop(
            fn(): bool => $this->evaluation->evaluateFunctionCondition($node->condition, $ctx->env),
            function () use ($node, $bodyCtx, &$output, &$first): void {
                $this->compileBody($node->body, $bodyCtx, $output, $first);
            },
        );

        return $output;
    }

    /**
     * @param array<int, AstNode> $body
     */
    private function compileBody(array $body, TraversalContext $ctx, string &$output, bool &$first): void
    {
        $scope            = $ctx->env->getCurrentScope();
        $hadGuard         = $scope->hasVariable('__flow_control_declaration_guard');
        $previousGuardSet = $hadGuard && $scope->getVariable('__flow_control_declaration_guard') === true;

        $scope->setVariableLocal('__flow_control_declaration_guard', true);

        try {
            foreach ($body as $child) {
                if ($this->evaluation->applyVariableDeclaration($child, $ctx->env)) {
                    continue;
                }

                if ($child instanceof ExtendNode) {
                    continue;
                }

                if ($child instanceof RuleNode) {
                    $parentSelector = $ctx->env->getCurrentScope()->getStringVariable('__parent_selector');

                    if ($parentSelector !== null && $parentSelector->value !== '') {
                        $childSelector   = $this->chunks->resolveRuleSelector($child, $ctx);
                        $isPropertyBlock = $this->selector->parseNestedPropertyBlockSelector($childSelector) !== null;

                        if (! $isPropertyBlock) {
                            $dummyOutput = '';

                            $this->chunks->appendIncludedRuleChunk($dummyOutput, $first, $child, $ctx, false);

                            continue;
                        }
                    }
                }

                /** @var Visitable $child */
                $compiled = $this->dispatcher->compileWithContext($child, $ctx);

                if ($compiled === '') {
                    continue;
                }

                if (! $first && ! str_ends_with($output, "\n")) {
                    $this->render->appendChunk($output, "\n");
                }

                $output .= $compiled;
                $first   = false;
            }
        } finally {
            $scope->setVariableLocal('__flow_control_declaration_guard', $hadGuard ? $previousGuardSet : false);
        }
    }

    private function toLoopNumber(AstNode $node, Environment $env): NumberNode
    {
        $resolved = $this->evaluation->evaluateValue($node, $env);

        if ($resolved instanceof NumberNode) {
            return $resolved;
        }

        $formatted = $this->render->format($resolved, $env);

        if (! is_numeric($formatted)) {
            throw new InvalidLoopBoundaryException($formatted);
        }

        return new NumberNode((float) $formatted);
    }
}
