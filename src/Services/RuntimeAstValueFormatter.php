<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerRuntime;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;

final readonly class RuntimeAstValueFormatter implements AstValueFormatterInterface
{
    public function __construct(private CompilerRuntime $runtime) {}

    public function format(AstNode $node, Environment $env): string
    {
        return $this->runtime->evaluation()->format($node, $env);
    }
}
