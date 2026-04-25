<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Parser\InlineValueParserInterface;

final readonly class ColorInlineValueParser implements InlineValueParserInterface
{
    public function parseInlineValue(string $expression): AstNode
    {
        return new StringNode($expression);
    }
}
