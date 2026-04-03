<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

use Bugo\SCSS\Builtins\FunctionRegistry;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Closure;

final readonly class BuiltinCallContext
{
    /**
     * @param Closure(string): void|null $logWarning
     * @param array<int, AstNode|NamedArgumentNode>|null $rawArguments
     */
    public function __construct(
        public ?Environment $environment = null,
        public ?FunctionRegistry $registry = null,
        public ?Closure $logWarning = null,
        public ?string $builtinDisplayName = null,
        public ?array $rawArguments = null,
        public ?int $callLine = null,
    ) {}

    public function warn(string $message): void
    {
        if ($this->logWarning !== null) {
            ($this->logWarning)($message);
        }
    }

    public function withBuiltinDisplayName(string $builtinDisplayName): self
    {
        return new self(
            $this->environment,
            $this->registry,
            $this->logWarning,
            $builtinDisplayName,
            $this->rawArguments,
            $this->callLine,
        );
    }
}
