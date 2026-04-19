<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;
use Closure;

final readonly class ClosureEachLoopBinder implements EachLoopBinderInterface
{
    /**
     * @param Closure(AstNode): array<int, AstNode> $items
     * @param Closure(array<int, string>, AstNode, Environment): void $assign
     */
    public function __construct(
        private Closure $items,
        private Closure $assign,
    ) {}

    public function items(AstNode $iterableValue): array
    {
        return ($this->items)($iterableValue);
    }

    public function assign(array $variables, AstNode $item, Environment $env): void
    {
        ($this->assign)($variables, $item, $env);
    }
}
