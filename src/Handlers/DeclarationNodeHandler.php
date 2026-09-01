<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Text;

use function array_key_last;
use function array_map;
use function implode;
use function in_array;
use function is_array;
use function str_contains;
use function str_ends_with;
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

        if (
            $ctx->env->getCurrentScope()->isInsideCssFunctionBody()
            && strtolower($property) === 'result'
        ) {
            if (str_contains($node->property, '#{')) {
                $evaluatedValue = $this->evaluation->evaluateDeclarationValue(
                    $node->value,
                    $property,
                    $ctx->env,
                );

                $value = $this->evaluation->format($evaluatedValue, $ctx->env);

                return $prefix . $property . ': ' . $value . ';';
            }

            $value = $this->formatRawCssValue($node->value);

            if (str_contains($value, '#{')) {
                $value = $this->text->interpolateText($value, $ctx->env);
            }

            return $prefix . $property . ': ' . $value . ';';
        }

        if ($ctx->plainCss && $this->containsSassKeyword($node->value)) {
            return $prefix . $property . ': ' . $this->formatRawCssValue($node->value)
                . ($node->important ? ' !important' : '') . ';';
        }

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

        if (str_starts_with($property, '--') && $node->value instanceof StringNode) {
            $value = $node->value->value;

            if (str_contains($value, '#{')) {
                $value = $this->text->interpolateText($value, $ctx->env);
            }

            $semicolon = str_ends_with($value, ';') ? '' : ';';

            return $prefix . $property . ':' . $value . $important . $semicolon;
        }

        if ($valueOrigin !== null) {
            $this->render->addPendingValueMapping(
                strlen($prefix . $property . ': '),
                $valueOrigin->line,
                $valueOrigin->column,
                $node,
            );
        }

        if (
            $important === ''
            && (
                $val === ''
                || ($val === '()' && $evaluatedValue instanceof ListNode)
            )
        ) {
            return '';
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

    private function containsSassKeyword(AstNode $node): bool
    {
        if ($node instanceof BooleanNode || $node instanceof NullNode) {
            return true;
        }

        if ($node instanceof ListNode) {
            foreach ($node->items as $item) {
                if ($this->containsSassKeyword($item)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof FunctionNode) {
            foreach ($node->arguments as $argument) {
                if ($this->containsSassKeyword($argument)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof MapNode) {
            foreach ($node->pairs as $pair) {
                if ($this->containsSassKeyword($pair->key) || $this->containsSassKeyword($pair->value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function formatRawCssValue(AstNode $node): string
    {
        return match (true) {
            $node instanceof StringNode,
            $node instanceof NumberNode,
            $node instanceof BooleanNode           => (string) $node,
            $node instanceof NullNode              => 'null',
            $node instanceof ColorNode             => $node->value,
            $node instanceof ListNode              => $this->formatRawListNode($node),
            $node instanceof FunctionNode          => $this->formatRawFunctionNode($node),
            $node instanceof MapNode               => $this->formatRawMapNode($node),
            $node instanceof VariableReferenceNode => '$' . $node->name,
            $node instanceof NamedArgumentNode     => '$' . $node->name . ': ' . $this->formatRawCssValue($node->value),
            default                                => '',
        };
    }

    private function formatRawListNode(ListNode $node): string
    {
        $items = array_map(
            $this->formatRawCssValue(...),
            $node->items,
        );

        $separator = $node->separator === 'comma' ? ', ' : ' ';
        $open      = $node->bracketed ? '[' : '';
        $close     = $node->bracketed ? ']' : '';

        return $open . implode($separator, $items) . $close;
    }

    private function formatRawFunctionNode(FunctionNode $node): string
    {
        $args = array_map(
            $this->formatRawCssValue(...),
            $node->arguments,
        );

        return $node->name . '(' . implode(', ', $args) . ')';
    }

    private function formatRawMapNode(MapNode $node): string
    {
        $pairs = array_map(
            fn(MapPair $pair): string => $this->formatRawCssValue($pair->key) . ': ' . $this->formatRawCssValue($pair->value),
            $node->pairs,
        );

        return '(' . implode(', ', $pairs) . ')';
    }
}
