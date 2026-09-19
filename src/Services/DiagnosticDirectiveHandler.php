<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerRuntime;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\TraversalContext;

final readonly class DiagnosticDirectiveHandler implements DiagnosticDirectiveHandlerInterface
{
    public function __construct(private CompilerRuntime $runtime) {}

    public function handle(DiagnosticType $type, AstNode $message, Environment $env, ?AstNode $statement = null): void
    {
        $this->runtime->diagnostic()->handleDirective(
            $type,
            $message,
            new TraversalContext($env, 0),
            $statement,
        );
    }
}
