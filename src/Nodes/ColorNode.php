<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class ColorNode extends AstNode
{
    public function __construct(public string $value) {}
}
