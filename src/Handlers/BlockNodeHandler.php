<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Builtins\FunctionRegistry;
use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\Handlers\Block\MixinHandler;
use Bugo\SCSS\Handlers\Rule\ChildrenCompilationStep;
use Bugo\SCSS\Handlers\Rule\CompilationStepInterface;
use Bugo\SCSS\Handlers\Rule\NestedPropertyCheckStep;
use Bugo\SCSS\Handlers\Rule\OutputFormattingStep;
use Bugo\SCSS\Handlers\Rule\RuleCompilationContext;
use Bugo\SCSS\Handlers\Rule\SelectorResolutionStep;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Module;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Style;

use function implode;
use function trim;

final readonly class BlockNodeHandler
{
    private DeferredChunkManager $chunks;

    private MixinHandler $mixin;

    /** @var list<CompilationStepInterface> */
    private array $compilationSteps;

    private OutputFormattingStep $outputFormattingStep;

    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Context $context,
        private Evaluator $evaluation,
        FunctionRegistry $registry,
        private Module $module,
        private Render $render,
        private Selector $selector,
    ) {
        $this->chunks = new DeferredChunkManager(
            $this->dispatcher,
            $this->context,
            $this->evaluation,
            $this->render,
            $this->selector,
        );

        $this->mixin = new MixinHandler(
            $this->dispatcher,
            $this->evaluation,
            $registry,
            $this->module,
            $this->render,
            $this->selector,
            $this->chunks,
        );

        $this->compilationSteps = [
            new SelectorResolutionStep($this->evaluation, $this->selector, $this->render),
            new NestedPropertyCheckStep($this->chunks, $this->render),
            new ChildrenCompilationStep($this->dispatcher, $this->evaluation, $this->render, $this->chunks),
        ];

        $this->outputFormattingStep = new OutputFormattingStep($this->render, $this->selector, $this->chunks);
    }

    public function handleInclude(IncludeNode $node, TraversalContext $ctx): string
    {
        return $this->mixin->handleInclude($node, $ctx);
    }

    public function handleRule(RuleNode $node, TraversalContext $ctx): string
    {
        $ctx->env->enterScope();

        $outputState = $this->render->outputState();

        $outputState->deferral->atRootStack[]   = [];
        $outputState->deferral->bubblingStack[] = [];

        $ruleCtx = new RuleCompilationContext(
            node: $node,
            outerCtx: $ctx,
            prefix: $this->render->indentPrefix($ctx->indent),
            childCtx: new TraversalContext($ctx->env, $ctx->indent + 1),
        );

        try {
            foreach ($this->compilationSteps as $step) {
                $result = $step->execute($ruleCtx);

                if ($result !== null) {
                    return $result;
                }
            }

            return $this->outputFormattingStep->execute($ruleCtx);
        } finally {
            $ctx->env->exitScope();
        }
    }

    public function handleSupports(SupportsNode $node, TraversalContext $ctx): string
    {
        $output    = '';
        $prefix    = $this->render->indentPrefix($ctx->indent);
        $condition = $this->selector->resolveSupportsCondition($node->condition, $ctx->env);

        $this->render->appendChunk($output, $prefix . '@supports ' . $condition . ' {');

        $parentAtRuleStack = $this->selector->getCurrentAtRuleStack($ctx->env);

        $currentAtRuleStack   = $parentAtRuleStack;
        $currentAtRuleStack[] = [
            'type'      => 'supports',
            'condition' => trim($condition),
        ];

        $outputState = $this->render->outputState();
        $outputState->deferral->atRuleStack[] = [];

        $ctx->env->enterScope();
        $ctx->env->getCurrentScope()->setVariableLocal('__at_rule_stack', $currentAtRuleStack);

        $childCtx = new TraversalContext($ctx->env, $ctx->indent + 1);

        $hasContent = false;
        $bodyChunks = [];

        $collectMappings = $this->render->collectSourceMappings();

        try {
            foreach ($node->body as $child) {
                if ($child instanceof VariableDeclarationNode) {
                    $ctx->env->getCurrentScope()->setVariable(
                        $child->name,
                        $this->evaluation->evaluateValue($child->value, $ctx->env),
                        $child->global,
                        $child->default,
                    );

                    continue;
                }

                if ($child instanceof ModuleVarDeclarationNode) {
                    $this->module->assignModuleVariable($child, $ctx->env);

                    continue;
                }

                if ($collectMappings) {
                    $this->render->appendChunk($output, "\n");
                }

                /** @var Visitable $child */
                $compiled = $this->render->trimAndAdjustState(
                    $this->dispatcher->compileWithContext($child, $childCtx),
                );

                if ($compiled === '') {
                    continue;
                }

                if ($collectMappings) {
                    $output .= $compiled;
                } else {
                    $bodyChunks[] = $compiled;
                }

                $hasContent = true;
            }
        } finally {
            $ctx->env->exitScope();
        }

        $outsideChunks = $this->selector->drainDeferredAtRuleEscapes();

        if ($hasContent) {
            if (! $collectMappings) {
                $output .= "\n" . implode("\n", $bodyChunks);
            }

            $output = $this->render->trimTrailingNewlines($output);

            $this->render->appendChunk($output, "\n" . $prefix . '}');
        } else {
            if ($outsideChunks !== []) {
                $separator = $this->render->outputSeparator();
                $outside   = implode($separator, $outsideChunks);

                if ($this->context->options()->style === Style::EXPANDED) {
                    return $outside . "\n";
                }

                return $outside;
            }

            $this->render->appendChunk($output, '}');
        }

        if ($outsideChunks !== []) {
            $separator = $this->render->outputSeparator();

            $output .= $separator . implode($separator, $outsideChunks);
        }

        if ($this->context->options()->style === Style::EXPANDED) {
            if ($this->render->collectSourceMappings()) {
                $dummy = '';

                $this->render->appendChunk($dummy, "\n");
            }

            return $output . "\n";
        }

        return $output;
    }
}
