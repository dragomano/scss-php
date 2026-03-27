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

    it('skips unknown characters and handles unclosed comments or strings with trailing escapes', function () {
        $unknown = $this->tokenizer->tokenize('`');
        $comment = $this->tokenizer->tokenize('/* unterminated');
        $string = $this->tokenizer->tokenize('"foo\\');

        expect($unknown)->toHaveCount(1)
            ->and($unknown[0]->type)->toBe(TokenType::EOF)
            ->and($comment[0]->type)->toBe(TokenType::COMMENT_LOUD)
            ->and($comment[0]->value)->toBe(' unterminated')
            ->and($string[0]->type)->toBe(TokenType::STRING)
            ->and($string[0]->value)->toBe('foo\\');
    });

    it('handles exponent edge cases css variables and optional operators', function () {
        $exponent = $this->tokenizer->tokenize('1e');
        $cssVar = $this->tokenizer->tokenize('--gap,');
        $single = $this->tokenizer->tokenize('>');
        $double = $this->tokenizer->tokenize('>=');
        $tilde = $this->tokenizer->tokenize('~');

        expect($exponent[0]->type)->toBe(TokenType::NUMBER)
            ->and($exponent[0]->value)->toBe('1e')
            ->and($cssVar[0]->type)->toBe(TokenType::CSS_VARIABLE)
            ->and($cssVar[0]->value)->toBe('--gap')
            ->and($cssVar[1]->type)->toBe(TokenType::COMMA)
            ->and($single[0]->type)->toBe(TokenType::GREATER_THAN)
            ->and($double[0]->type)->toBe(TokenType::GREATER_THAN_EQUALS)
            ->and($tilde[0]->type)->toBe(TokenType::TILDE);
    });

    it('supports tokenization without tracking positions', function () {
        $this->tokenizer->setTrackPositions(false);

        $tokens = $this->tokenizer->tokenize("a\nb");

        expect($tokens[0]->type)->toBe(TokenType::IDENTIFIER)
            ->and($tokens[0]->value)->toBe('a')
            ->and($tokens[2]->type)->toBe(TokenType::IDENTIFIER)
            ->and($tokens[2]->value)->toBe('b');
    });

    it('tokenizes escaped identifiers and strings through the public api', function () {
        $escapedBackslashIdentifier = $this->tokenizer->tokenize('\\');
        $escapedQuestionIdentifier = $this->tokenizer->tokenize('\\?');
        $hexEscapedIdentifier = $this->tokenizer->tokenize('\\41 ');
        $accentedIdentifier = $this->tokenizer->tokenize('\\E9 ');
        $euroIdentifier = $this->tokenizer->tokenize('\\20AC ');

        expect($escapedBackslashIdentifier[0]->type)->toBe(TokenType::IDENTIFIER)
            ->and($escapedBackslashIdentifier[0]->value)->toBe('\\')
            ->and($escapedQuestionIdentifier[0]->type)->toBe(TokenType::IDENTIFIER)
            ->and($escapedQuestionIdentifier[0]->value)->toBe('\\?')
            ->and($hexEscapedIdentifier[0]->type)->toBe(TokenType::IDENTIFIER)
            ->and($hexEscapedIdentifier[0]->value)->toBe('A')
            ->and($accentedIdentifier[0]->type)->toBe(TokenType::IDENTIFIER)
            ->and($accentedIdentifier[0]->value)->toBe("\xC3\xA9")
            ->and($euroIdentifier[0]->type)->toBe(TokenType::IDENTIFIER)
            ->and($euroIdentifier[0]->value)->toBe("\xE2\x82\xAC");
    });
});
