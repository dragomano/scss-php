<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\UnexpectedTokenException;
use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;

function makeTokens(array $pairs): array
{
    $tokens = [];
    foreach ($pairs as [$type, $value]) {
        $tokens[] = new Token($type, $value, 1, 1);
    }

    return $tokens;
}

describe('TokenStream', function () {
    it('returns current token', function () {
        $tokens = makeTokens([[TokenType::IDENTIFIER, 'foo'], [TokenType::EOF, '']]);
        $stream = new TokenStream($tokens);

        expect($stream->current()->type)->toBe(TokenType::IDENTIFIER)
            ->and($stream->current()->value)->toBe('foo');
    });

    it('advances to next token', function () {
        $tokens = makeTokens([
            [TokenType::IDENTIFIER, 'foo'],
            [TokenType::COLON, ':'],
            [TokenType::EOF, ''],
        ]);
        $stream = new TokenStream($tokens);

        $stream->advance();

        expect($stream->current()->type)->toBe(TokenType::COLON);
    });

    it('clamps advance() to the end of the token array', function () {
        $tokens = makeTokens([
            [TokenType::IDENTIFIER, 'foo'],
            [TokenType::COLON, ':'],
            [TokenType::EOF, ''],
        ]);
        $stream = new TokenStream($tokens);

        $stream->advance(10);

        expect($stream->getPosition())->toBe(3)
            ->and($stream->current()->type)->toBe(TokenType::EOF);
    });

    it('peeks ahead without advancing', function () {
        $tokens = makeTokens([
            [TokenType::IDENTIFIER, 'foo'],
            [TokenType::COLON, ':'],
            [TokenType::EOF, ''],
        ]);
        $stream = new TokenStream($tokens);

        $peeked = $stream->peek();
        expect($peeked->type)->toBe(TokenType::COLON)
            ->and($stream->current()->type)->toBe(TokenType::IDENTIFIER);
    });

    it('is() returns true when type matches', function () {
        $tokens = makeTokens([[TokenType::SEMICOLON, ';'], [TokenType::EOF, '']]);
        $stream = new TokenStream($tokens);

        expect($stream->is(TokenType::SEMICOLON))->toBeTrue()
            ->and($stream->is(TokenType::COLON))->toBeFalse();
    });

    it('match() returns true when current type is in list', function () {
        $tokens = makeTokens([[TokenType::PLUS, '+'], [TokenType::EOF, '']]);
        $stream = new TokenStream($tokens);

        expect($stream->match(TokenType::PLUS, TokenType::MINUS))->toBeTrue()
            ->and($stream->match(TokenType::STAR, TokenType::SLASH))->toBeFalse();
    });

    it('consume() advances and returns token when type matches', function () {
        $tokens = makeTokens([[TokenType::IDENTIFIER, 'bar'], [TokenType::EOF, '']]);
        $stream = new TokenStream($tokens);

        $consumed = $stream->consume(TokenType::IDENTIFIER);
        expect($consumed)->not->toBeNull()
            ->and($consumed->value)->toBe('bar')
            ->and($stream->current()->type)->toBe(TokenType::EOF);
    });

    it('consume() returns null when type does not match', function () {
        $tokens = makeTokens([[TokenType::IDENTIFIER, 'bar'], [TokenType::EOF, '']]);
        $stream = new TokenStream($tokens);

        $result = $stream->consume(TokenType::COLON);
        expect($result)->toBeNull()
            ->and($stream->current()->type)->toBe(TokenType::IDENTIFIER);
    });

    it('expect() advances and returns token when type matches', function () {
        $tokens = makeTokens([[TokenType::LBRACE, '{'], [TokenType::EOF, '']]);
        $stream = new TokenStream($tokens);

        $token = $stream->expect(TokenType::LBRACE);
        expect($token->type)->toBe(TokenType::LBRACE);
    });

    it('expect() throws when type does not match', function () {
        $tokens = makeTokens([[TokenType::IDENTIFIER, 'foo'], [TokenType::EOF, '']]);
        $stream = new TokenStream($tokens);

        expect(fn() => $stream->expect(TokenType::COLON))
            ->toThrow(UnexpectedTokenException::class);
    });

    it('skipWhitespace() skips whitespace tokens', function () {
        $tokens = makeTokens([
            [TokenType::WHITESPACE, ' '],
            [TokenType::WHITESPACE, ' '],
            [TokenType::IDENTIFIER, 'foo'],
            [TokenType::EOF, ''],
        ]);
        $stream = new TokenStream($tokens);

        $stream->skipWhitespace();

        expect($stream->current()->type)->toBe(TokenType::IDENTIFIER);
    });

    it('getPosition() and setPosition() work', function () {
        $tokens = makeTokens([
            [TokenType::IDENTIFIER, 'a'],
            [TokenType::IDENTIFIER, 'b'],
            [TokenType::EOF, ''],
        ]);
        $stream = new TokenStream($tokens);

        $stream->advance();
        expect($stream->getPosition())->toBe(1);

        $stream->setPosition(0);
        expect($stream->current()->type)->toBe(TokenType::IDENTIFIER)
            ->and($stream->current()->value)->toBe('a');
    });

    it('isEof() returns true at last token', function () {
        $tokens = makeTokens([[TokenType::EOF, '']]);
        $stream = new TokenStream($tokens);

        expect($stream->isEof())->toBeTrue();
    });
});
