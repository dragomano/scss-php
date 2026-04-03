<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Exceptions\SassErrorException;
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
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Style;

use function get_object_vars;

final readonly class DiagnosticNodeHandler
{
    public function __construct(
        private Context $context,
        private Evaluator $evaluation,
        private Render $render,
    ) {}

    public function handleDebug(DebugNode $node, TraversalContext $ctx): string
    {
        [$message, $line, $column] = $this->buildPayload('debug', $node->message, $ctx, $node);

        $this->log('debug', $message, $line, $column);

        return '';
    }

    public function handleWarn(WarnNode $node, TraversalContext $ctx): string
    {
        [$message, $line, $column] = $this->buildPayload('warn', $node->message, $ctx, $node);

        $this->log('warn', $message, $line, $column);

        return '';
    }

    public function handleError(ErrorNode $node, TraversalContext $ctx): never
    {
        [$message, $line, $column] = $this->buildPayload('error', $node->message, $ctx, $node);

        $this->logError($message, $line, $column);
    }

    public function handleDirective(
        string $directive,
        AstNode $messageNode,
        TraversalContext $ctx,
        ?AstNode $origin = null,
    ): void {
        [$message, $line, $column] = $this->buildPayload($directive, $messageNode, $ctx, $origin);

        if ($directive === 'error') {
            $this->logError($message, $line, $column);
        }

        $this->log($directive, $message, $line, $column);
    }

    /**
     * @return array{0: string, 1: int|null, 2: int|null}
     */
    private function buildPayload(
        string $directive,
        AstNode $messageNode,
        TraversalContext $ctx,
        ?AstNode $origin = null,
    ): array {
        $evaluated = $this->evaluateMessage($messageNode, $ctx);

        if ($directive === 'debug' && $this->context->options()->style === Style::COMPRESSED) {
            $evaluated = $this->evaluation->compressNamedColorsForOutput($evaluated);
        }

        $formatted = $this->render->format($evaluated, $ctx->env);
        $message   = $this->extractMessage($directive, $evaluated, $formatted);

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

    private function extractMessage(string $directive, AstNode $evaluated, string $formatted): string
    {
        if ($evaluated instanceof NullNode) {
            return $directive === 'warn' ? '' : 'null';
        }

        if ($evaluated instanceof BooleanNode) {
            return $evaluated->value ? 'true' : 'false';
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

    private function log(string $directive, string $message, ?int $line, ?int $column): void
    {
        $sourceFile = $this->context->currentSourceFile();

        if ($this->context->options()->verboseLogging) {
            $logMessage = $message;
            $context    = [
                'directive'  => $directive,
                'file'       => $sourceFile,
                'sourceFile' => $sourceFile,
                'line'       => $line,
                'column'     => $column,
            ];
        } else {
            $location   = $sourceFile . ($line !== null ? ':' . $line : '');
            $logMessage = $location ? "$location >>> $message" : $message;
            $context    = [];
        }

        match ($directive) {
            'debug' => $this->context->logger()->debug($logMessage, $context),
            'warn'  => $this->context->logger()->warning($logMessage, $context),
            default => $this->context->logger()->error($logMessage, $context),
        };
    }

    private function logError(string $message, ?int $line, ?int $column): never
    {
        $this->log('error', $message, $line, $column);

        throw new SassErrorException($message, $this->context->currentSourceFile(), $line, $column);
    }
}
