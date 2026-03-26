<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Exceptions\InvalidLoopBoundaryException;
use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\EachNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;

use function is_numeric;
use function str_ends_with;

final readonly class FlowControlNodeHandler
{
    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Evaluator $evaluation,
        private Render $render
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

            if ($branch === null) {
                $branch = $node->elseBody;
            }
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
        $output = '';
        $first  = true;
        $from   = (int) $this->toLoopNumber($node->from, $ctx->env);
        $to     = (int) $this->toLoopNumber($node->to, $ctx->env);

        if (! $node->inclusive) {
            $to += $from <= $to ? -1 : 1;
        }

        $step          = $from <= $to ? 1 : -1;
        $iterations    = 0;
        $maxIterations = 10000;

        $ctx->env->enterScope();

        try {
            $bodyCtx = new TraversalContext($ctx->env, $ctx->indent);

            for ($i = $from; $step > 0 ? $i <= $to : $i >= $to; $i += $step) {
                $iterations++;

                if ($iterations > $maxIterations) {
                    throw new MaxIterationsExceededException('@for');
                }

                $ctx->env->getCurrentScope()->setVariable($node->variable, new NumberNode($i));

                $this->compileBody($node->body, $bodyCtx, $output, $first);
            }
        } finally {
            $ctx->env->exitScope();
        }

        return $output;
    }

    public function handleWhile(WhileNode $node, TraversalContext $ctx): string
    {
        $output        = '';
        $first         = true;
        $iterations    = 0;
        $maxIterations = 10000;
        $bodyCtx       = new TraversalContext($ctx->env, $ctx->indent);

        while ($this->evaluation->evaluateFunctionCondition($node->condition, $ctx->env)) {
            $iterations++;

            if ($iterations > $maxIterations) {
                throw new MaxIterationsExceededException('@while');
            }

            $this->compileBody($node->body, $bodyCtx, $output, $first);
        }

        return $output;
    }

    /**
     * @param array<int, AstNode> $body
     */
    private function compileBody(array $body, TraversalContext $ctx, string &$output, bool &$first): void
    {
        foreach ($body as $child) {
            if ($this->evaluation->applyVariableDeclaration($child, $ctx->env)) {
                continue;
            }

            /** @var Visitable $child */
            $compiled = $this->dispatcher->compileWithContext($child, $ctx);

            if ($compiled === '') {
                continue;
            }

            if (! $first && ! str_ends_with($output, "\n")) {
                $this->render->appendChunk($output, "\n");
            }

            $this->render->appendChunk($output, $compiled, $child);

            $first = false;
        }
    }

    private function toLoopNumber(AstNode $node, Environment $env): float
    {
        $resolved = $this->evaluation->evaluateValue($node, $env);

        if ($resolved instanceof NumberNode) {
            return (float) $resolved->value;
        }

        $formatted = $this->render->format($resolved, $env);

        if (! is_numeric($formatted)) {
            throw new InvalidLoopBoundaryException($formatted);
        }

        return (float) $formatted;
    }
}
