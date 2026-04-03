<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\StringNode;

use function strtolower;
use function trim;

final class AstValueInspector
{
    public static function isNoneKeyword(AstNode $node): bool
    {
        return $node instanceof StringNode
            && strtolower(trim($node->value)) === 'none';
    }

    public static function isQuotedString(?AstNode $node): bool
    {
        return $node instanceof StringNode && $node->quoted;
    }
}
