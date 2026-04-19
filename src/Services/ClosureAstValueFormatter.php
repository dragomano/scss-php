<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;
use Closure;

final readonly class ClosureAstValueFormatter implements AstValueFormatterInterface
{
    /** @param Closure(AstNode, Environment): string $format */
    public function __construct(private Closure $format) {}

    public function format(AstNode $node, Environment $env): string
    {
        return ($this->format)($node, $env);
    }
}
