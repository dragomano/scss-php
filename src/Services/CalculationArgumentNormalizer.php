<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;

final readonly class CalculationArgumentNormalizer implements CalculationArgumentNormalizerInterface
{
    public function __construct(private Evaluator $evaluator) {}

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function normalize(string $name, array $arguments): array
    {
        return $this->evaluator->normalizeCalculationArguments($name, $arguments);
    }
}
