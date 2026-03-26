<?php

declare(strict_types=1);

namespace Bugo\SCSS\Lexer;

use Bugo\SCSS\Exceptions\UnexpectedTokenException;

use function count;
use function in_array;
use function min;

final class TokenStream
{
    protected int $position = 0;

    protected int $length;

    /**
     * @param array<int, Token> $tokens
     */
    public function __construct(protected array $tokens)
    {
        $this->length = count($this->tokens);

        $this->tokens[$this->length] = $this->tokens[$this->length - 1];
    }

    public function current(): Token
    {
        return $this->tokens[$this->position];
    }

    public function peek(int $ahead = 1): Token
    {
        $pos = $this->position + $ahead;

        return $this->tokens[min($pos, $this->length)];
    }

    public function is(TokenType $type): bool
    {
        return $this->tokens[$this->position]->type === $type;
    }

    public function match(TokenType ...$types): bool
    {
        $currentType = $this->tokens[$this->position]->type;

        return in_array($currentType, $types, true);
    }

    public function consume(TokenType $type): ?Token
    {
        $result = $this->fetchAndAdvance($type);

        return $result === false ? null : $result;
    }

    public function expect(TokenType $type): Token
    {
        $result = $this->fetchAndAdvance($type);

        if ($result === false) {
            $token = $this->tokens[$this->position];

            throw new UnexpectedTokenException($type->value, $token->type->value, $token->line);
        }

        return $result;
    }

    public function advance(int $count = 1): void
    {
        $this->position += $count;

        if ($this->position > $this->length) {
            $this->position = $this->length;
        }
    }

    public function skipWhitespace(): void
    {
        $position = $this->position;

        while ($position < $this->length) {
            $tokenType = $this->tokens[$position]->type;

            if ($tokenType !== TokenType::WHITESPACE && $tokenType !== TokenType::COMMENT_SILENT) {
                break;
            }

            $position++;
        }

        $this->position = $position;
    }

    public function isEof(): bool
    {
        return $this->position >= $this->length - 1;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    private function fetchAndAdvance(TokenType $type): Token|false
    {
        $token = $this->tokens[$this->position];

        if ($token->type !== $type) {
            return false;
        }

        if ($this->position < $this->length) {
            $this->position++;
        }

        return $token;
    }
}
