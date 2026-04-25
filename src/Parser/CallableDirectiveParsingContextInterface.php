<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\RuleNode;

interface CallableDirectiveParsingContextInterface
{
    /**
     * @return array<int, AstNode>
     */
    public function parseBlock(): array;

    /**
     * @return array<int, AstNode>
     */
    public function parseStatementsInsideBlock(): array;

    public function consumeIdentifier(): string;

    public function parseRuleFromSelector(string $selector, int $line = 1, int $column = 1): RuleNode;

    public function incrementBlockDepth(): void;

    public function decrementBlockDepth(): void;
}
