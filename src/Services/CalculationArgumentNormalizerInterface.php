<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;

interface CalculationArgumentNormalizerInterface
{
    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function normalize(string $name, array $arguments): array;
}
