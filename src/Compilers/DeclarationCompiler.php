<?php

declare(strict_types=1);

namespace DartSass\Compilers;

use Closure;
use DartSass\Parsers\Nodes\AstNode;
use DartSass\Parsers\Nodes\CommentNode;
use DartSass\Parsers\Nodes\NumberNode;
use DartSass\Parsers\Nodes\OperationNode;
use DartSass\Utils\PositionTracker;
use DartSass\Utils\ResultFormatterInterface;
use DartSass\Utils\StringFormatter;

use function current;
use function key;
use function max;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr;

readonly class DeclarationCompiler
{
    public function __construct(
        private ResultFormatterInterface $resultFormatter,
        private PositionTracker $positionTracker
    ) {}

    public function compile(
        array $declarations,
        string $parentSelector,
        int $nestingLevel,
        Closure $compileAst,
        Closure $expression,
        Closure $interpolate,
        bool $sourceMapEnabled = false,
        ?Closure $addMapping = null
    ): string {
        $css = '';

        foreach ($declarations as $declaration) {
            if ($declaration instanceof AstNode) {
                if ($declaration instanceof CommentNode) {
                    $indent  = str_repeat('  ', $nestingLevel);
                    $comment = $declaration->value;

                    if (str_starts_with($comment, '/*')) {
                        $content = substr($comment, 2, -2);
                        $content = $interpolate($content);

                        $commentCss = StringFormatter::concatMultiple([$indent, '/*' . $content . '*/', "\n"]);

                        $this->positionTracker->updatePosition($commentCss);

                        $css .= $commentCss;
                    }
                } else {
                    $css .= $compileAst([$declaration], $parentSelector, $nestingLevel);
                }
            } else {
                $indent   = str_repeat('  ', $nestingLevel);
                $property = key($declaration);
                $value    = current($declaration);

                $generatedPosition = $this->positionTracker->getCurrentPosition();

                if ($this->shouldPreserveSlashOperation($value)) {
                    $formattedValue = $this->formatPreservedSlashOperation($value, $expression);
                } else {
                    $evaluatedValue = $expression($value);
                    if ($evaluatedValue === null || $evaluatedValue === '') {
                        continue;
                    }

                    $formattedValue = $this->resultFormatter->format($evaluatedValue);
                }

                if ($value->important ?? false) {
                    $formattedValue .= ' !important';
                }

                $declarationCss = StringFormatter::concatMultiple([$indent, $property, ': ', $formattedValue, ";\n"]);

                $css .= $declarationCss;

                $this->positionTracker->updatePosition($declarationCss);

                if ($sourceMapEnabled && $addMapping !== null) {
                    $generatedPosition = [
                        'line'   => $generatedPosition['line'] - 1,
                        'column' => $generatedPosition['column'] + strlen($indent),
                    ];

                    $originalPosition = [
                        'line'   => max(0, ($value->line ?? 1) - 1),
                        'column' => max(0, ($value->column ?? 1) - 1),
                    ];

                    $addMapping([
                        'generated'   => $generatedPosition,
                        'original'    => $originalPosition,
                        'sourceIndex' => 0,
                    ]);
                }
            }
        }

        return $css;
    }

    private function shouldPreserveSlashOperation(mixed $value): bool
    {
        if (! $value instanceof OperationNode || $value->operator !== '/') {
            return false;
        }

        if (! $value->left instanceof NumberNode || ! $value->right instanceof NumberNode) {
            return false;
        }

        return ($value->left->unit === null) !== ($value->right->unit === null);
    }

    private function formatPreservedSlashOperation(OperationNode $value, Closure $expression): string
    {
        return StringFormatter::concatMultiple([
            $this->resultFormatter->format($expression($value->left)),
            ' / ',
            $this->resultFormatter->format($expression($value->right)),
        ]);
    }
}
