<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;
use Closure;

final readonly class ClosureDiagnosticDirectiveHandler implements DiagnosticDirectiveHandlerInterface
{
    /** @param Closure(string, AstNode, Environment, AstNode|null): void $handle */
    public function __construct(private Closure $handle) {}

    public function handle(string $kind, AstNode $message, Environment $env, ?AstNode $statement = null): void
    {
        ($this->handle)($kind, $message, $env, $statement);
    }
}
