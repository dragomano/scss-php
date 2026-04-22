<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;

interface FunctionCallParsingContextInterface
{
    public function parseSingleValue(): ?AstNode;

    /**
     * @param array<int, TokenType> $stopTokens
     */
    public function parseValueUntil(array $stopTokens): ?AstNode;

    public function parseVariableReference(): VariableReferenceNode;

    public function parseCommaSeparatedValue(): ?AstNode;
}
