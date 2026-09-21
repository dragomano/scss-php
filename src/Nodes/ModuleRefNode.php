<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\Scope;

final class ModuleRefNode extends AstNode
{
    public function __construct(
        public readonly ?Scope $scope = null,
        public readonly ?string $builtinName = null,
    ) {}
}
