<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class IncludeNode extends StatementNode
{
    /**
     * @param array<int, AstNode> $arguments
     * @param array<int, AstNode> $contentBlock
     * @param array<int, ArgumentNode> $contentArguments
     */
    public function __construct(
        public ?string $namespace,
        public string $name,
        public array $arguments = [],
        public array $contentBlock = [],
        public array $contentArguments = []
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitInclude($this, $ctx);
    }
}
