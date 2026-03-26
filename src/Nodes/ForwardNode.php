<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class ForwardNode extends StatementNode
{
    /**
     * @param array<int, string> $members
     * @param array<string, array{value: AstNode, default: bool}> $configuration
     */
    public function __construct(
        public string $path,
        public ?string $prefix = null,
        public ?string $visibility = null,
        public array $members = [],
        public array $configuration = []
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitForward($this, $ctx);
    }
}
