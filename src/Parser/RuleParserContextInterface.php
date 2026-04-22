<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Nodes\RuleNode;

interface RuleParserContextInterface
{
    public function isInsideBraces(): bool;

    public function parseRuleFromSelector(string $selector, int $line = 1, int $column = 1): RuleNode;
}
