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
        $output          = '';
        $first           = true;
        $endsWithNewline = false;

        foreach ($node->children as $child) {
            /** @var Visitable $child */
            $compiled = $this->dispatcher->compileWithContext($child, $ctx);

            if ($compiled !== '') {
                if (! $first && ! $endsWithNewline) {
                    $this->render->appendChunk($output, "\n");
                }

                $this->render->appendChunk($output, $compiled, $child);

                $compiledLength  = strlen($compiled);
                $endsWithNewline = $compiled[$compiledLength - 1] === "\n";
                $first           = false;
            }
        }

        return $output;
    }
}
