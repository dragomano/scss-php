<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class ArgumentNode extends AstNode
{
    public function __construct(
        public string $name,
        public ?AstNode $defaultValue = null,
        public bool $rest = false,
    ) {}
}
