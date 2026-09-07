<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Style;

use function ltrim;
use function str_contains;
use function str_starts_with;
use function strtolower;

final readonly class CommentNodeHandler
{
    public function __construct(
        private Context $context,
        private Evaluator $evaluation,
        private Render $render,
    ) {}

    public function handle(CommentNode $node, TraversalContext $ctx): string
    {
        $prefix  = $this->render->indentPrefix($ctx->indent);
        $comment = str_contains($node->value, '#{')
            ? $this->evaluation->interpolateText($node->value, $ctx->env)
            : $node->value;
        $output  = '';

        if ($this->isSourceMapAnnotation($comment)) {
            return '';
        }

        if ($node->isPreserved) {
            $this->render->appendChunk($output, $this->formatComment($comment, true, $prefix), $node);

            return $output;
        }

        if ($this->context->options()->style === Style::EXPANDED) {
            $this->render->appendChunk($output, $this->formatComment($comment, false, $prefix), $node);

            return $output;
        }

        return '';
    }

    private function formatComment(string $comment, bool $preserved, string $prefix): string
    {
        $open = $preserved ? '/*!' : '/*';

        return $prefix . $open . $comment . '*/';
    }

    private function isSourceMapAnnotation(string $comment): bool
    {
        $text = ltrim($comment);

        foreach (['# sourceMappingURL=', '# sourceURL=', '# sourcemappingurl=', '# sourceurl='] as $needle) {
            if (str_starts_with(strtolower($text), $needle)) {
                return true;
            }
        }

        return false;
    }
}
