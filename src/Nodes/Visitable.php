<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

interface Visitable
{
    public function accept(Visitor $visitor, TraversalContext $ctx): string;
}
