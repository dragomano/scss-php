<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;

interface DiagnosticDirectiveHandlerInterface
{
    public function handle(string $kind, AstNode $message, Environment $env, ?AstNode $statement = null): void;
}
