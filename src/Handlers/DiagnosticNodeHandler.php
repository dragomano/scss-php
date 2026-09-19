<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\DebugNode;
use Bugo\SCSS\Nodes\ErrorNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\WarnNode;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\DiagnosticService;
use Bugo\SCSS\Services\DiagnosticType;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Style;

use function get_object_vars;
use function is_int;

final readonly class DiagnosticNodeHandler
{
    public function __construct(
        private Context $context,
        private Evaluator $evaluation,
        private Render $render,
        private DiagnosticService $diagnostics,
    ) {}

    public function handleDebug(DebugNode $node, TraversalContext $ctx): string
    {
        [$message, $line, $column] = $this->buildPayload(DiagnosticType::DEBUG, $node->message, $ctx, $node);

        $this->diagnostics->log(DiagnosticType::DEBUG, $message, $line, $column);

        return '';
    }

    public function handleWarn(WarnNode $node, TraversalContext $ctx): string
    {
        [$message, $line, $column] = $this->buildPayload(DiagnosticType::WARNING, $node->message, $ctx, $node);

        $this->diagnostics->log(DiagnosticType::WARNING, $message, $line, $column);

        return '';
    }

    public function handleError(ErrorNode $node, TraversalContext $ctx): never
    {
        [$message, $line, $column] = $this->buildPayload(DiagnosticType::ERROR, $node->message, $ctx, $node);

        $this->diagnostics->error($message, $line, $column);
    }

    public function handleDirective(
        DiagnosticType $type,
        AstNode $messageNode,
        TraversalContext $ctx,
        ?AstNode $origin = null,
    ): void {
        [$message, $line, $column] = $this->buildPayload($type, $messageNode, $ctx, $origin);

        if ($type === DiagnosticType::ERROR) {
            $this->diagnostics->error($message, $line, $column);
        }

        $this->diagnostics->log($type, $message, $line, $column);
    }

    /**
     * @return array{0: string, 1: int|null, 2: int|null}
     */
    private function buildPayload(
        DiagnosticType $type,
        AstNode $messageNode,
        TraversalContext $ctx,
        ?AstNode $origin = null,
    ): array {
        $evaluated = $this->evaluateMessage($messageNode, $ctx);

        if ($type === DiagnosticType::DEBUG && $this->context->options()->style === Style::COMPRESSED) {
            $evaluated = $this->evaluation->compressNamedColorsForOutput($evaluated);
        }

        $formatted = $this->render->format($evaluated, $ctx->env);
        $message   = $this->extractMessage($type, $evaluated, $formatted);

        [$line, $column] = $this->extractLocation($origin);

        return [$message, $line, $column];
    }

    private function evaluateMessage(AstNode $messageNode, TraversalContext $ctx): AstNode
    {
        if ($messageNode instanceof ListNode && $this->evaluation->containsSlashToken($messageNode, true)) {
            return $this->evaluation->evaluateValueWithoutSlashArithmetic($messageNode, $ctx->env);
        }

        $evaluated = $this->evaluation->evaluateValue($messageNode, $ctx->env);

        if ($evaluated instanceof ListNode) {
            $strict = $this->evaluation->evaluateArithmeticList($evaluated, false, $ctx->env);

            if ($strict instanceof AstNode) {
                return $strict;
            }
        }

        return $evaluated;
    }

    private function extractMessage(DiagnosticType $type, AstNode $evaluated, string $formatted): string
    {
        if ($evaluated instanceof NullNode) {
            return $type === DiagnosticType::WARNING ? '' : 'null';
        }

        if ($evaluated instanceof BooleanNode) {
            return (string) $evaluated;
        }

        return $evaluated instanceof StringNode ? $evaluated->value : $formatted;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function extractLocation(?AstNode $origin): array
    {
        if ($origin === null) {
            return [null, null];
        }

        $data = get_object_vars($origin);

        return [
            isset($data['line']) && is_int($data['line']) ? $data['line'] : null,
            isset($data['column']) && is_int($data['column']) ? $data['column'] : null,
        ];
    }
}
