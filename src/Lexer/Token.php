<?php

declare(strict_types=1);

namespace Bugo\SCSS\Lexer;

final class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $line,
        public int $column
    ) {}
}
