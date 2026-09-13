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
final class WhileNode extends StatementNode
{
    /**
     * @param NodeList $body
     */
    public function __construct(
        public string $condition,
        public array $body = [],
        public int $line = 1,
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitWhile($this, $ctx);
    }
}
