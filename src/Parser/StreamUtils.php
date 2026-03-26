<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Closure;

use function in_array;
use function max;
use function strtolower;
use function trim;

final class StreamUtils
{
    public static function consumeIdentifier(TokenStream $stream): string
    {
        if (! $stream->is(TokenType::IDENTIFIER)) {
            return '';
        }

        $token = $stream->current();

        $stream->advance();

        return $token->value;
    }

    public static function consumeEllipsis(TokenStream $stream): bool
    {
        $savedPos = $stream->getPosition();

        $stream->skipWhitespace();

        if (
            $stream->is(TokenType::DOT)
            && $stream->peek()->type === TokenType::DOT
            && $stream->peek(2)->type === TokenType::DOT
        ) {
            $stream->advance(3);

            return true;
        }

        $stream->setPosition($savedPos);

        return false;
    }

    public static function tokenToRawString(TokenType $type, string $value): string
    {
        if ($type === TokenType::HASH) {
            return '#' . $value;
        }

        return $value;
    }

    public static function updateNestingDepth(Token $token, int &$parenDepth, int &$bracketDepth): void
    {
        if ($token->type === TokenType::LPAREN) {
            $parenDepth++;

            return;
        }

        if ($token->type === TokenType::RPAREN) {
            $parenDepth = max(0, $parenDepth - 1);

            return;
        }

        if ($token->type === TokenType::LBRACKET) {
            $bracketDepth++;

            return;
        }

        if ($token->type === TokenType::RBRACKET) {
            $bracketDepth = max(0, $bracketDepth - 1);
        }
    }

    public static function appendTokenToBuffer(
        string &$buffer,
        Token $token,
        bool $quoteStringToken = false
    ): void {
        if ($token->type === TokenType::WHITESPACE) {
            $buffer .= ' ';

            return;
        }

        if ($quoteStringToken && $token->type === TokenType::STRING) {
            $buffer .= '"' . $token->value . '"';

            return;
        }

        $buffer .= self::tokenToRawString($token->type, $token->value);
    }

    public static function consumeInterpolationFragment(
        TokenStream $stream,
        string &$buffer,
        int &$interpolationDepth,
        Token $token
    ): bool {
        if ($token->type === TokenType::HASH && $stream->peek()->type === TokenType::LBRACE) {
            $buffer .= '#{';

            $interpolationDepth++;

            $stream->advance(2);

            return true;
        }

        if ($interpolationDepth > 0 && $token->type === TokenType::RBRACE) {
            $buffer .= '}';

            $interpolationDepth--;

            $stream->advance();

            return true;
        }

        return false;
    }

    public static function readRawUntil(TokenStream $stream, Closure $shouldStop): string
    {
        $result       = '';
        $parenDepth   = 0;
        $bracketDepth = 0;

        while (! $stream->isEof()) {
            $token = $stream->current();

            if ($token->type === TokenType::LPAREN) {
                $parenDepth++;
            } elseif ($token->type === TokenType::RPAREN) {
                $parenDepth--;
            } elseif ($token->type === TokenType::LBRACKET) {
                $bracketDepth++;
            } elseif ($token->type === TokenType::RBRACKET) {
                $bracketDepth--;
            }

            if ($parenDepth === 0 && $bracketDepth === 0 && $shouldStop($token)) {
                break;
            }

            $result .= $token->type === TokenType::WHITESPACE
                ? ' '
                : self::tokenToRawString($token->type, $token->value);

            $stream->advance();
        }

        return trim($result);
    }

    public static function readRawUntilToken(TokenStream $stream, TokenType $stopToken): string
    {
        return self::readRawUntil($stream, fn(Token $token): bool => $token->type === $stopToken);
    }

    /**
     * @param array<int, string> $keywords
     */
    public static function readRawUntilIdentifier(TokenStream $stream, array $keywords): string
    {
        return self::readRawUntil(
            $stream,
            fn(Token $token): bool => $token->type === TokenType::IDENTIFIER
                && in_array($token->value, $keywords, true)
        );
    }

    public static function consumeKeyword(TokenStream $stream, string $keyword, bool $caseInsensitive = false): bool
    {
        if (! $stream->is(TokenType::IDENTIFIER)) {
            return false;
        }

        $value = $stream->current()->value;

        if (($caseInsensitive ? strtolower($value) : $value) !== $keyword) {
            return false;
        }

        $stream->advance();
        $stream->skipWhitespace();

        return true;
    }

    public static function consumeCommaSeparator(TokenStream $stream): bool
    {
        $stream->skipWhitespace();

        if (! $stream->consume(TokenType::COMMA)) {
            return false;
        }

        $stream->skipWhitespace();

        return true;
    }

    public static function consumeSemicolonFromStream(TokenStream $stream): void
    {
        $stream->skipWhitespace();
        $stream->consume(TokenType::SEMICOLON);
    }

    public static function parseQualifiedIdentifier(TokenStream $stream): string
    {
        $buffer = '';

        while ($stream->match(TokenType::IDENTIFIER, TokenType::DOT)) {
            $buffer .= $stream->current()->value;

            $stream->advance();
        }

        return $buffer;
    }

    public static function parseStringToken(TokenStream $stream): string
    {
        if ($stream->is(TokenType::STRING)) {
            $token = $stream->current();

            $stream->advance();

            return $token->value;
        }

        return self::consumeIdentifier($stream);
    }
}
