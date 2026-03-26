<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Style;

use function str_contains;

final readonly class CommentNodeHandler
{
    public function __construct(
        private Context $context,
        private Evaluator $evaluation,
        private Render $render
    ) {}

    public function handle(CommentNode $node, TraversalContext $ctx): string
    {
        $prefix  = $this->render->indentPrefix($ctx->indent);
        $comment = str_contains($node->value, '#{')
            ? $this->evaluation->interpolateText($node->value, $ctx->env)
            : $node->value;

        if ($node->isPreserved) {
            return $prefix . '/*! ' . $comment . ' */';
        }

        if ($this->context->options()->style === Style::EXPANDED) {
            return $prefix . '/* ' . $comment . ' */';
        }

        return '';
    }
}
