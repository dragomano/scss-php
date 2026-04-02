<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Render;

use function strlen;

final readonly class RootNodeHandler
{
    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Render $render
    ) {}

    public function handle(RootNode $node, TraversalContext $ctx): string
    {
        $output        = '';
        $nextSeparator = '';

        foreach ($node->children as $child) {
            $savedPosition = null;

            if ($nextSeparator !== '' && $this->render->collectSourceMappings()) {
                $savedPosition = $this->render->savePosition();

                $dummy = '';

                $this->render->appendChunk($dummy, $nextSeparator);
            }

            /** @var Visitable $child */
            $compiled = $this->dispatcher->compileWithContext($child, $ctx);

            if ($compiled !== '') {
                $output .= $nextSeparator . $compiled;

                $nextSeparator = $compiled[strlen($compiled) - 1] === "\n" ? '' : "\n";
            } elseif ($savedPosition !== null) {
                $this->render->restorePosition($savedPosition);
            }
        }

        return $output;
    }
}
