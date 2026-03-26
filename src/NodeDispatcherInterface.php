<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\TraversalContext;

interface NodeDispatcherInterface
{
    public function compile(Visitable $node, Environment $env, int $indent = 0): string;

    public function compileWithContext(Visitable $node, TraversalContext $ctx): string;
}
