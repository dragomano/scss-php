<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Exceptions\SassErrorException;
use Psr\Log\LoggerInterface;

final readonly class DiagnosticService
{
    public function __construct(
        private CompilerContext $ctx,
        private CompilerOptions $options,
        private LoggerInterface $logger,
    ) {}

    public function log(DiagnosticType $type, string $message, ?int $line = null, ?int $column = null): void
    {
        $sourceFile = $this->ctx->currentSourceFile;
        $directive  = $type->directive();

        if ($this->options->verboseLogging) {
            $logMessage = $message;

            $context = [
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

        match ($type) {
            DiagnosticType::DEBUG   => $this->logger->debug($logMessage, $context),
            DiagnosticType::WARNING => $this->logger->warning($logMessage, $context),
            DiagnosticType::ERROR   => $this->logger->error($logMessage, $context),
        };
    }

    public function warning(string $message, ?int $line = null): void
    {
        $this->log(DiagnosticType::WARNING, $message, $line);
    }

    public function error(string $message, ?int $line = null, ?int $column = null): never
    {
        $this->log(DiagnosticType::ERROR, $message, $line, $column);

        throw new SassErrorException($message, $this->ctx->currentSourceFile, $line, $column);
    }
}
