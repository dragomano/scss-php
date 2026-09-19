<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Psr\Log\LoggerInterface;

final readonly class Context
{
    public function __construct(
        private CompilerContext $ctx,
        private CompilerOptions $options,
        private LoggerInterface $logger,
        private ?DiagnosticService $diagnostics = null,
    ) {}

    public function options(): CompilerOptions
    {
        return $this->options;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function currentSourceFile(): string
    {
        return $this->ctx->currentSourceFile;
    }

    public function logWarning(string $message, ?int $line = null): void
    {
        $diagnostics = $this->diagnostics ?? new DiagnosticService($this->ctx, $this->options, $this->logger);

        $diagnostics->warning($message, $line);
    }
}
