<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Render;

final readonly class RootNodeHandler
{
    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Render $render,
    ) {}

    public function handle(RootNode $node, TraversalContext $ctx): string
    {
        $output = '';

        foreach ($node->children as $child) {
            $savedPosition = null;

            if ($output !== '' && $this->render->collectSourceMappings()) {
                $savedPosition = $this->render->savePosition();

                $dummy = '';

                $this->render->appendChunk($dummy, "\n\n");
            }

            /** @var Visitable $child */
            $compiled = $this->dispatcher->compileWithContext($child, $ctx);

            if ($compiled !== '') {
                if ($output !== '') {
                    $output .= "\n";
                }

                $output .= $compiled;
            } elseif ($savedPosition !== null) {
                $this->render->restorePosition($savedPosition);
            }
        }

        return $output;
    }
}
