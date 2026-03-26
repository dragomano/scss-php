<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\TraversalContext;
use LogicException;

final class CompilerDispatcher implements NodeDispatcherInterface
{
    private ?Visitor $visitor = null;

    public function setVisitor(Visitor $visitor): void
    {
        $this->visitor = $visitor;
    }

    public function compile(Visitable $node, Environment $env, int $indent = 0): string
    {
        return $this->compileWithContext($node, new TraversalContext($env, $indent));
    }

    public function compileWithContext(Visitable $node, TraversalContext $ctx): string
    {
        return $node->accept($this->visitor(), $ctx);
    }

    private function visitor(): Visitor
    {
        return $this->visitor ?? throw new LogicException('Compiler visitor is not initialized.');
    }
}
