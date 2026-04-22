<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;

interface ModuleDirectiveContextInterface
{
    public function parseString(): string;

    public function consumeIdentifier(): string;

    /**
     * @param array<int, TokenType> $stopTokens
     */
    public function parseValueUntil(array $stopTokens): ?AstNode;

    /**
     * @return array{default: bool, global: bool, important: bool}
     */
    public function parseValueModifiers(): array;
}
