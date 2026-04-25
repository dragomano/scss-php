<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Nodes\AstNode;

interface InlineValueParserInterface
{
    public function parseInlineValue(string $expression): AstNode;
}
