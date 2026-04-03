<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class CommentNode extends StatementNode
{
    public function __construct(
        public string $value,
        public bool $isPreserved = false,
        public int $line = 1,
        public int $column = 1,
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitComment($this, $ctx);
    }
}
