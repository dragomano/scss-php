<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerRuntime;
use Bugo\SCSS\Nodes\AstNode;

final readonly class RuntimeCalculationArgumentNormalizer implements CalculationArgumentNormalizerInterface
{
    public function __construct(private CompilerRuntime $runtime) {}

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function normalize(string $name, array $arguments): array
    {
        return $this->runtime->evaluation()->normalizeCalculationArguments($name, $arguments);
    }
}
