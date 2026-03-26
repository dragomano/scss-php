<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class ImportNode extends StatementNode
{
    /**
     * @param array<int, string> $imports
     */
    public function __construct(public array $imports) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitImport($this, $ctx);
    }
}
