<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class DeprecatedExpressionNode extends AstNode
{
    public function __construct(
        public AstNode $expression,
        public string $message,
        public int $line,
        public int $column = 0,
        public string $code = 'strict-unary',
    ) {}
}
