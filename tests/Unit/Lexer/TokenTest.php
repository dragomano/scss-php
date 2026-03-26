<?php

declare(strict_types=1);

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\TokenType;

describe('Token', function () {
    it('stores type, value, line and column', function () {
        $token = new Token(TokenType::IDENTIFIER, 'foo', 3, 7);

        expect($token->type)->toBe(TokenType::IDENTIFIER)
            ->and($token->value)->toBe('foo')
            ->and($token->line)->toBe(3)
            ->and($token->column)->toBe(7);
    });

    it('works with different token types', function () {
        $token = new Token(TokenType::COLON, ':', 1, 1);

        expect($token->type)->toBe(TokenType::COLON)
            ->and($token->value)->toBe(':');
    });

    it('stores EOF token', function () {
        $token = new Token(TokenType::EOF, '', 10, 1);

        expect($token->type)->toBe(TokenType::EOF)
            ->and($token->value)->toBe('')
            ->and($token->line)->toBe(10);
    });

    it('stores number token with unit', function () {
        $token = new Token(TokenType::NUMBER, '42px', 2, 5);

        expect($token->type)->toBe(TokenType::NUMBER)
            ->and($token->value)->toBe('42px');
    });

    it('stores string token with quoted content', function () {
        $token = new Token(TokenType::STRING, 'hello world', 1, 1);

        expect($token->type)->toBe(TokenType::STRING)
            ->and($token->value)->toBe('hello world');
    });
});
