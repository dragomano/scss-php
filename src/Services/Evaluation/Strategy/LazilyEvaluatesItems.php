<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\AstNode;
use Closure;

use function array_slice;

trait LazilyEvaluatesItems
{
    /**
     * @param array<int, AstNode> $sourceItems
     * @param Closure(AstNode): AstNode $evaluateItem
     * @return list<AstNode>|null null if no items changed
     */
    private static function lazilyEvaluateItems(array $sourceItems, Closure $evaluateItem): ?array
    {
        $result  = null;
        $itemIdx = 0;

        foreach ($sourceItems as $item) {
            $evaluatedItem = $evaluateItem($item);

            if ($result !== null) {
                $result[] = $evaluatedItem;
            } elseif ($evaluatedItem !== $item) {
                $result   = $itemIdx > 0 ? array_slice($sourceItems, 0, $itemIdx) : [];
                $result[] = $evaluatedItem;
            }

            $itemIdx++;
        }

        return $result;
    }
}
