<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Style;

use function array_slice;
use function count;
use function explode;
use function ltrim;
use function max;
use function min;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

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
            $this->render->appendChunk($output, $this->formatComment($comment, true, $prefix, $node->column), $node);

            return $output;
        }

        if ($this->context->options()->style === Style::EXPANDED) {
            $this->render->appendChunk($output, $this->formatComment($comment, false, $prefix, $node->column), $node);

            return $output;
        }

        return '';
    }

    private function formatComment(string $comment, bool $preserved, string $prefix, ?int $column): string
    {
        $open = $preserved ? '/*!' : '/*';
        $full = $open . $comment . '*/';

        $minimum = $this->minimumIndentation($full);

        if ($minimum === null) {
            return $prefix . $full;
        }

        if ($column !== null && $column > 0) {
            $minimum = min($minimum, $column - 1);
        }

        return $this->reindent($full, max($minimum, 0), $prefix);
    }

    private function minimumIndentation(string $text): ?int
    {
        if (! str_contains($text, "\n")) {
            return null;
        }

        $lines = explode("\n", $text);
        $min   = null;

        foreach (array_slice($lines, 1) as $line) {
            $stripped = ltrim($line, " \t");

            if ($stripped === '') {
                continue;
            }

            $col = strlen($line) - strlen($stripped);
            $min = $min === null ? $col : min($min, $col);
        }

        return $min ?? -1;
    }

    private function reindent(string $text, int $minimum, string $prefix): string
    {
        $lines  = explode("\n", $text);
        $out    = $prefix . $lines[0];
        $length = count($lines);

        for ($i = 1; $i < $length; $i++) {
            $line = rtrim($lines[$i], "\r");
            $lead = min(strlen($line) - strlen(ltrim($line, " \t")), $minimum);

            $out .= "\n" . $prefix . substr($line, min(max($lead, 0), $minimum));
        }

        return $out;
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
