<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

/**
 * @phpstan-import-type NodeMap from AstNode
 *
 * @psalm-import-type NodeMap from AstNode
 */
final class UseNode extends StatementNode
{
    /**
     * @param NodeMap $configuration
     */
    public function __construct(
        public string $path,
        public ?string $namespace = null,
        public array $configuration = [],
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitUse($this, $ctx);
    }
}
