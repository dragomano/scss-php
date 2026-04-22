<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color;

use Bugo\SCSS\Lexer\Tokenizer;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Parser\ValueParser;

final readonly class ColorAstParser
{
    public function parse(string $value): FunctionNode|ColorNode|null
    {
        $tokenizer = new Tokenizer();
        $stream    = new TokenStream($tokenizer->tokenize($value));
        $parser    = new ValueParser($stream, new ColorInlineValueParser());
        $parsed    = $parser->parseValue();

        if ($parsed instanceof FunctionNode || $parsed instanceof ColorNode) {
            return $parsed;
        }

        return null;
    }
}
