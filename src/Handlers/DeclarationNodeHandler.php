<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Text;

use function array_key_last;
use function in_array;
use function is_array;
use function str_contains;
use function strlen;
use function strtolower;

final readonly class DeclarationNodeHandler
{
    public function __construct(
        private Evaluator $evaluation,
        private Render $render,
        private Text $text,
    ) {}

    public function handle(DeclarationNode $node, TraversalContext $ctx): string
    {
        if ($this->shouldRejectBareDeclarationInCurrentContext($ctx)) {
            throw new SassErrorException('Expected identifier.', sourceLine: $node->line, sourceColumn: $node->column);
        }

        $prefix   = $this->render->indentPrefix($ctx->indent);
        $property = str_contains($node->property, '#{')
            ? $this->text->interpolateText($node->property, $ctx->env)
            : $node->property;

        $evaluatedValue = $this->evaluation->evaluateDeclarationValue($node->value, $property, $ctx->env);

        $valueOrigin = null;
        if ($this->render->collectSourceMappings()
            && $node->value instanceof VariableReferenceNode
            && $evaluatedValue instanceof StringNode
            && $evaluatedValue->line > 0
        ) {
            $valueOrigin = $evaluatedValue;
        }

        if (
            $evaluatedValue instanceof ListNode
            && ! (
                $this->evaluation->shouldUseCompactSlashSpacing($property)
                && $this->evaluation->containsSlashToken($evaluatedValue)
            )
        ) {
            $strictArithmetic = $this->evaluation->evaluateArithmeticList($evaluatedValue, false, $ctx->env);

            if ($strictArithmetic instanceof AstNode) {
                $evaluatedValue = $strictArithmetic;
            }
        }

        if ($this->evaluation->isSassNullValue($evaluatedValue)) {
            return '';
        }

        if ($this->evaluation->shouldCompressNamedColorForProperty($property)) {
            $evaluatedValue = $this->evaluation->compressNamedColorsForOutput($evaluatedValue);
        }

        $reparsedValue = $this->evaluation->tryEvaluateFormattedDeclarationExpression(
            $property,
            $evaluatedValue,
            $ctx->env,
        );

        if ($reparsedValue instanceof AstNode) {
            $evaluatedValue = $reparsedValue;
        }

        $val = $this->evaluation->format($evaluatedValue, $ctx->env);
        $val = $this->evaluation->normalizeDeclarationSlashSpacing($property, $val);

        if (str_contains($val, '#{')) {
            $val = $this->text->interpolateText($val, $ctx->env);
        }

        $important = $node->important ? ' !important' : '';

        if ($valueOrigin !== null) {
            $this->render->addPendingValueMapping(
                strlen($prefix . $property . ': '),
                $valueOrigin->line,
                $valueOrigin->column,
                $node,
            );
        }

        return $prefix . $property . ': ' . $val . $important . ';';
    }

    private function shouldRejectBareDeclarationInCurrentContext(TraversalContext $ctx): bool
    {
        $scope = $ctx->env->getCurrentScope();

        if (! $scope->hasVariable('__flow_control_declaration_guard')) {
            return false;
        }

        if ($scope->getVariable('__flow_control_declaration_guard') !== true) {
            return false;
        }

        if ($scope->hasVariable('__parent_selector')) {
            return false;
        }

        if (! $scope->hasVariable('__at_rule_stack')) {
            return true;
        }

        $stack = $scope->getVariable('__at_rule_stack');

        if (! is_array($stack) || $stack === []) {
            return true;
        }

        $entry = $stack[array_key_last($stack)] ?? null;

        if (! $entry instanceof AtRuleContextEntry || $entry->type !== 'directive') {
            return true;
        }

        return ! in_array(strtolower($entry->name ?? ''), ['font-face', 'page', 'property', 'counter-style'], true);
    }
}
