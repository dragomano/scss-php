<?php

declare(strict_types=1);

use Bugo\SCSS\Lexer\Tokenizer;
use Bugo\SCSS\Lexer\TokenType;

describe('Tokenizer', function () {
    beforeEach(function () {
        $this->tokenizer = new Tokenizer();
    });

    it('tokenizes an empty string to EOF only', function () {
        $tokens = $this->tokenizer->tokenize('');

        expect($tokens)->toHaveCount(1)
            ->and($tokens[0]->type)->toBe(TokenType::EOF);
    });

    it('tokenizes identifier', function () {
        $tokens = $this->tokenizer->tokenize('foo');

        $types = array_column($tokens, 'type');
        expect($types[0])->toBe(TokenType::IDENTIFIER)
            ->and($tokens[0]->value)->toBe('foo');
    });

    it('tokenizes number with unit', function () {
        $tokens = $this->tokenizer->tokenize('42px');

        expect($tokens[0]->type)->toBe(TokenType::NUMBER)
            ->and($tokens[0]->value)->toBe('42px');
    });

    it('tokenizes float number', function () {
        $tokens = $this->tokenizer->tokenize('3.14');

        expect($tokens[0]->type)->toBe(TokenType::NUMBER)
            ->and($tokens[0]->value)->toBe('3.14');
    });

    it('tokenizes colon', function () {
        $tokens = $this->tokenizer->tokenize(':');

        expect($tokens[0]->type)->toBe(TokenType::COLON);
    });

    it('tokenizes double colon', function () {
        $tokens = $this->tokenizer->tokenize('::');

        expect($tokens[0]->type)->toBe(TokenType::DOUBLE_COLON)
            ->and($tokens[0]->value)->toBe('::');
    });

    it('tokenizes at-sign', function () {
        $tokens = $this->tokenizer->tokenize('@');

        expect($tokens[0]->type)->toBe(TokenType::AT);
    });

    it('tokenizes dollar sign', function () {
        $tokens = $this->tokenizer->tokenize('$');

        expect($tokens[0]->type)->toBe(TokenType::DOLLAR);
    });

    it('tokenizes braces', function () {
        $tokens = $this->tokenizer->tokenize('{}');

        expect($tokens[0]->type)->toBe(TokenType::LBRACE)
            ->and($tokens[1]->type)->toBe(TokenType::RBRACE);
    });

    it('tokenizes single-line comment', function () {
        $tokens = $this->tokenizer->tokenize('// this is a comment');

        $found = array_filter($tokens, fn($t) => $t->type === TokenType::COMMENT_SILENT);
        expect(count($found))->toBeGreaterThan(0);
    });

    it('tokenizes multi-line comment', function () {
        $tokens = $this->tokenizer->tokenize('/* comment */');

        $found = array_filter($tokens, fn($t) => $t->type === TokenType::COMMENT_LOUD);
        expect(count($found))->toBeGreaterThan(0);
    });

    it('tokenizes preserved comment', function () {
        $tokens = $this->tokenizer->tokenize('/*! preserved */');

        $found = array_filter($tokens, fn($t) => $t->type === TokenType::COMMENT_PRESERVED);
        expect(count($found))->toBeGreaterThan(0);
    });

    it('tokenizes quoted string', function () {
        $tokens = $this->tokenizer->tokenize('"hello"');

        expect($tokens[0]->type)->toBe(TokenType::STRING)
            ->and($tokens[0]->value)->toBe('hello');
    });

    it('tokenizes hex color', function () {
        $tokens = $this->tokenizer->tokenize('#ff0000');

        expect($tokens[0]->type)->toBe(TokenType::HASH)
            ->and($tokens[0]->value)->toBe('ff0000');
    });

    it('tokenizes whitespace', function () {
        $tokens = $this->tokenizer->tokenize('a b');

        expect($tokens[1]->type)->toBe(TokenType::WHITESPACE);
    });

    it('tokenizes CSS variable', function () {
        $tokens = $this->tokenizer->tokenize('--my-var');

        expect($tokens[0]->type)->toBe(TokenType::CSS_VARIABLE)
            ->and($tokens[0]->value)->toBe('--my-var');
    });

    it('tokenizes comparison operators', function () {
        $eqTokens = $this->tokenizer->tokenize('==');
        expect($eqTokens[0]->type)->toBe(TokenType::EQUALS);

        $neqTokens = $this->tokenizer->tokenize('!=');
        expect($neqTokens[0]->type)->toBe(TokenType::NOT_EQUALS);

        $lteTokens = $this->tokenizer->tokenize('<=');
        expect($lteTokens[0]->type)->toBe(TokenType::LESS_THAN_EQUALS);

        $gteTokens = $this->tokenizer->tokenize('>=');
        expect($gteTokens[0]->type)->toBe(TokenType::GREATER_THAN_EQUALS);
    });

    it('records line and column positions', function () {
        $tokens = $this->tokenizer->tokenize("foo\nbar");

        // 'bar' should be on line 2
        $barToken = array_values(array_filter($tokens, fn($t) => $t->value === 'bar'))[0] ?? null;
        expect($barToken)->not->toBeNull()
            ->and($barToken->line)->toBe(2);
    });
});
