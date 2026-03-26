<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class BooleanNode extends AstNode
{
    public function __construct(public readonly bool $value) {}
}
