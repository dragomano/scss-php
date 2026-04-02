<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Text;

use function str_contains;
use function strlen;

final readonly class DeclarationNodeHandler
{
    public function __construct(
        private Evaluator $evaluation,
        private Render $render,
        private Text $text
    ) {}

    public function handle(DeclarationNode $node, TraversalContext $ctx): string
    {
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
            $ctx->env
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
                $node
            );
        }

        return $prefix . $property . ': ' . $val . $important . ';';
    }
}
