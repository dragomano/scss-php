<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;

interface EachLoopBinderInterface
{
    /**
     * @return array<int, AstNode>
     */
    public function items(AstNode $iterableValue): array;

    /**
     * @param array<int, string> $variables
     */
    public function assign(array $variables, AstNode $item, Environment $env): void;
}
