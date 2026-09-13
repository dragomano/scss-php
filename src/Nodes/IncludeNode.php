<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

/**
 * @phpstan-import-type NodeList from AstNode
 *
 * @psalm-import-type NodeList from AstNode
 */
final class IncludeNode extends StatementNode
{
    /**
     * @param NodeList $arguments
     * @param NodeList $contentBlock
     * @param array<int, ArgumentNode> $contentArguments
     */
    public function __construct(
        public ?string $namespace,
        public string $name,
        public array $arguments = [],
        public array $contentBlock = [],
        public array $contentArguments = [],
        public bool $hasContent = false,
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitInclude($this, $ctx);
    }
}
