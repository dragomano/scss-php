<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class MixinRefNode extends AstNode
{
    public function __construct(public readonly string $name) {}
}
