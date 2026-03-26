<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\StringNode;

use function array_slice;
use function count;

trait ColorChannelSplitterTrait
{
    /**
     * @param array<int, AstNode> $items
     * @return array{0: array<int, AstNode>, 1: ?AstNode}
     */
    private function splitChannelsAndAlpha(array $items, bool $allowFourthAsAlpha = true): array
    {
        $channels      = [];
        $alpha         = null;
        $separatorSeen = false;

        foreach ($items as $item) {
            if ($separatorSeen) {
                $alpha = $item;

                break;
            }

            if ($item instanceof StringNode && $item->value === '/') {
                $separatorSeen = true;

                continue;
            }

            $channels[] = $item;
        }

        if ($allowFourthAsAlpha && ! $separatorSeen && count($channels) > 3) {
            $alpha    = $channels[3];
            $channels = array_slice($channels, 0, 3);
        }

        return [$channels, $alpha];
    }
}
