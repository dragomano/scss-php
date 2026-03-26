<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class ReturnNode extends AstNode
{
    public function __construct(public AstNode $value) {}
}
