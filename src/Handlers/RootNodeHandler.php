<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Render;

use function array_splice;
use function count;
use function ltrim;
use function str_ends_with;

final readonly class RootNodeHandler
{
    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Render $render,
    ) {}

    public function handle(RootNode $node, TraversalContext $ctx): string
    {
        $output          = '';
        $outputState     = $this->render->outputState();
        $leadingComments = [];
        $inLeadingRun    = $outputState->hoistCssImports && $ctx->indent === 0;

        foreach ($node->children as $child) {
            if ($child instanceof CommentNode && $child->afterClosingBrace && $output !== '') {
                $trimmedOutput = $this->render->trimTrailingNewlines($output);

                if (str_ends_with($trimmedOutput, '}')) {
                    $compiled = $this->dispatcher->compileWithContext($child, $ctx);

                    if ($compiled !== '') {
                        $this->render->appendChunk($trimmedOutput, ' ' . ltrim($compiled), $child);

                        $output = $trimmedOutput;
                    }

                    continue;
                }
            }

            $savedPosition = null;

            if ($output !== '' && $this->render->collectSourceMappings()) {
                $savedPosition = $this->render->savePosition();

                $dummy = '';

                $this->render->appendChunk($dummy, "\n\n");
            }

            $importCount = count($outputState->cssImports);

            /** @var Visitable $child */
            $compiled = $this->dispatcher->compileWithContext($child, $ctx);

            if ($inLeadingRun) {
                if ($leadingComments !== [] && count($outputState->cssImports) > $importCount) {
                    array_splice($outputState->cssImports, $importCount, 0, $leadingComments);

                    $leadingComments = [];
                }

                if ($compiled !== '' && $child instanceof CommentNode) {
                    $leadingComments[] = $compiled;

                    continue;
                }

                if ($compiled !== '' && ! $child instanceof UseNode && ! $child instanceof ForwardNode) {
                    $inLeadingRun = false;
                }
            }

            if ($compiled === '') {
                if ($savedPosition !== null) {
                    $this->render->restorePosition($savedPosition);
                }

                continue;
            }

            $output = $this->appendLeadingComments($output, $leadingComments);

            $leadingComments = [];

            if ($output !== '') {
                $output .= "\n";
            }

            $output .= $compiled;
        }

        return $this->appendLeadingComments($output, $leadingComments);
    }

    /**
     * @param list<string> $comments
     */
    private function appendLeadingComments(string $output, array $comments): string
    {
        foreach ($comments as $comment) {
            if ($output !== '') {
                $output .= "\n";
            }

            $output .= $comment;
        }

        return $output;
    }
}
