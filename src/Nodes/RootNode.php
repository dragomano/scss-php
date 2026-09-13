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
final class RootNode extends StatementNode
{
    /**
     * @param NodeList $children
     */
    public function __construct(public array $children = []) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitRoot($this, $ctx);
    }
}
