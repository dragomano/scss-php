<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class StringNode extends AstNode
{
    public function __construct(public string $value, public bool $quoted = false) {}
}
