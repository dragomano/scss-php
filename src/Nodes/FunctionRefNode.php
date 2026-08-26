<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\CallableDefinition;
use Bugo\SCSS\Runtime\Scope;

final class FunctionRefNode extends AstNode
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $module = null,
        public readonly ?CallableDefinition $lockedDefinition = null,
        public readonly ?Scope $capturedScope = null,
    ) {}
}
