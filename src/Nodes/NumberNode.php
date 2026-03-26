<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class NumberNode extends AstNode
{
    public function __construct(
        public float|int $value,
        public ?string $unit = null,
        public bool $isLiteral = true
    ) {}
}
