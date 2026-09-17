<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\CallableDefinition;

final class MixinRefNode extends AstNode
{
    public function __construct(
        public readonly string $name,
        public readonly ?CallableDefinition $lockedDefinition = null,
    ) {}
}
