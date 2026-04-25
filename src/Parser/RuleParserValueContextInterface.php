<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Nodes\AstNode;

interface RuleParserValueContextInterface
{
    public function parseValue(): AstNode;

    /**
     * @return array{global: bool, default: bool, important: bool}
     */
    public function parseValueModifiers(): array;

    public function parseCustomPropertyValue(): string;
}
