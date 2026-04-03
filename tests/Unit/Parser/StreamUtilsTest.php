<?php

declare(strict_types=1);

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\Tokenizer;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Parser\StreamUtils;

function makeStream(string $source): TokenStream
{
    return new TokenStream((new Tokenizer())->tokenize($source));
}

function makeToken(TokenType $type, string $value = ''): Token
{
    return new Token($type, $value, 1, 1);
}

describe('StreamUtils', function () {
    describe('consumeIdentifier()', function () {
        it('returns identifier value when stream is at IDENTIFIER token', function () {
            $stream = makeStream('hello');

            expect(StreamUtils::consumeIdentifier($stream))->toBe('hello');
        });

        it('returns empty string when stream is not at IDENTIFIER token', function () {
            $stream = makeStream('123');

            expect(StreamUtils::consumeIdentifier($stream))->toBe('');
        });

        it('advances stream past identifier', function () {
            $stream = makeStream('hello world');
            StreamUtils::consumeIdentifier($stream);

            $stream->skipWhitespace();
            expect(StreamUtils::consumeIdentifier($stream))->toBe('world');
        });
    });

    describe('consumeEllipsis()', function () {
        it('returns true and advances when stream has ...', function () {
            $stream = makeStream('...');

            expect(StreamUtils::consumeEllipsis($stream))->toBeTrue();
        });

        it('returns false when stream does not have ...', function () {
            $stream = makeStream('hello');

            expect(StreamUtils::consumeEllipsis($stream))->toBeFalse();
        });

        it('restores stream position when not found', function () {
            $stream = makeStream('hello');
            $pos = $stream->getPosition();
            StreamUtils::consumeEllipsis($stream);

            expect($stream->getPosition())->toBe($pos);
        });

        it('skips whitespace before ...', function () {
            $stream = makeStream('  ...');

            expect(StreamUtils::consumeEllipsis($stream))->toBeTrue();
        });
    });

    describe('tokenToRawString()', function () {
        it('prepends # for HASH token type', function () {
            expect(StreamUtils::tokenToRawString(TokenType::HASH, 'abc'))->toBe('#abc');
        });

        it('returns value as-is for non-HASH token types', function () {
            expect(StreamUtils::tokenToRawString(TokenType::IDENTIFIER, 'hello'))->toBe('hello')
                ->and(StreamUtils::tokenToRawString(TokenType::STRING, 'world'))->toBe('world');
        });
    });

    describe('updateNestingDepth()', function () {
        it('increments parenDepth on LPAREN', function () {
            $parenDepth = 0;
            $bracketDepth = 0;
            StreamUtils::updateNestingDepth(makeToken(TokenType::LPAREN), $parenDepth, $bracketDepth);

            expect($parenDepth)->toBe(1)
                ->and($bracketDepth)->toBe(0);
        });

        it('decrements parenDepth on RPAREN', function () {
            $parenDepth = 2;
            $bracketDepth = 0;
            StreamUtils::updateNestingDepth(makeToken(TokenType::RPAREN), $parenDepth, $bracketDepth);

            expect($parenDepth)->toBe(1);
        });

        it('does not go below 0 on RPAREN', function () {
            $parenDepth = 0;
            $bracketDepth = 0;
            StreamUtils::updateNestingDepth(makeToken(TokenType::RPAREN), $parenDepth, $bracketDepth);

            expect($parenDepth)->toBe(0);
        });

        it('increments bracketDepth on LBRACKET', function () {
            $parenDepth = 0;
            $bracketDepth = 0;
            StreamUtils::updateNestingDepth(makeToken(TokenType::LBRACKET), $parenDepth, $bracketDepth);

            expect($bracketDepth)->toBe(1)
                ->and($parenDepth)->toBe(0);
        });

        it('decrements bracketDepth on RBRACKET', function () {
            $parenDepth = 0;
            $bracketDepth = 1;
            StreamUtils::updateNestingDepth(makeToken(TokenType::RBRACKET), $parenDepth, $bracketDepth);

            expect($bracketDepth)->toBe(0);
        });

        it('does not modify depths for unrelated tokens', function () {
            $parenDepth = 2;
            $bracketDepth = 3;
            StreamUtils::updateNestingDepth(makeToken(TokenType::IDENTIFIER, 'foo'), $parenDepth, $bracketDepth);

            expect($parenDepth)->toBe(2)
                ->and($bracketDepth)->toBe(3);
        });
    });

    describe('appendTokenToBuffer()', function () {
        it('appends a space for WHITESPACE token', function () {
            $buffer = 'a';
            StreamUtils::appendTokenToBuffer($buffer, makeToken(TokenType::WHITESPACE));

            expect($buffer)->toBe('a ');
        });

        it('appends quoted string when quoteStringToken is true', function () {
            $buffer = '';
            StreamUtils::appendTokenToBuffer($buffer, makeToken(TokenType::STRING, 'hello'), true);

            expect($buffer)->toBe('"hello"');
        });

        it('appends unquoted string value when quoteStringToken is false', function () {
            $buffer = '';
            StreamUtils::appendTokenToBuffer($buffer, makeToken(TokenType::STRING, 'hello'));

            expect($buffer)->toBe('hello');
        });

        it('prepends # for HASH token', function () {
            $buffer = '';
            StreamUtils::appendTokenToBuffer($buffer, makeToken(TokenType::HASH, 'ff0000'));

            expect($buffer)->toBe('#ff0000');
        });

        it('appends identifier value as-is', function () {
            $buffer = 'test';
            StreamUtils::appendTokenToBuffer($buffer, makeToken(TokenType::IDENTIFIER, 'blue'));

            expect($buffer)->toBe('testblue');
        });
    });

    describe('consumeInterpolationFragment()', function () {
        it('detects opening #{ and increments depth', function () {
            $stream = makeStream('#{$x}');
            $buffer = '';
            $depth = 0;
            $token = $stream->current();

            $result = StreamUtils::consumeInterpolationFragment($stream, $buffer, $depth, $token);

            expect($result)->toBeTrue()
                ->and($buffer)->toBe('#{')
                ->and($depth)->toBe(1);
        });

        it('detects closing } when inside interpolation', function () {
            $stream = makeStream('}');
            $buffer = '#{$x';
            $depth = 1;
            $token = $stream->current();

            $result = StreamUtils::consumeInterpolationFragment($stream, $buffer, $depth, $token);

            expect($result)->toBeTrue()
                ->and($buffer)->toBe('#{$x}')
                ->and($depth)->toBe(0);
        });

        it('ignores } outside of interpolation context', function () {
            $stream = makeStream('}');
            $buffer = '';
            $depth = 0;
            $token = $stream->current();

            $result = StreamUtils::consumeInterpolationFragment($stream, $buffer, $depth, $token);

            expect($result)->toBeFalse()
                ->and($buffer)->toBe('');
        });
    });

    describe('readRawUntil()', function () {
        it('reads tokens until condition is met', function () {
            $stream = makeStream('hello world;');

            $result = StreamUtils::readRawUntil(
                $stream,
                fn(Token $t): bool => $t->type === TokenType::SEMICOLON,
            );

            expect($result)->toBe('hello world');
        });

        it('respects paren depth — does not stop inside parens', function () {
            $stream = makeStream('func(a; b); done;');

            $result = StreamUtils::readRawUntil(
                $stream,
                fn(Token $t): bool => $t->type === TokenType::SEMICOLON,
            );

            expect($result)->toBe('func(a; b)');
        });

        it('respects bracket depth and does not stop inside brackets', function () {
            $stream = makeStream('[a; b]; done;');

            $result = StreamUtils::readRawUntil(
                $stream,
                fn(Token $t): bool => $t->type === TokenType::SEMICOLON,
            );

            expect($result)->toBe('[a; b]');
        });

        it('decrements bracket depth when closing brackets are encountered', function () {
            $stream = makeStream('[a[b]c]; done;');

            $result = StreamUtils::readRawUntil(
                $stream,
                fn(Token $t): bool => $t->type === TokenType::SEMICOLON,
            );

            expect($result)->toBe('[a[b]c]');
        });

        it('trims surrounding whitespace from result', function () {
            $stream = makeStream('  hello  ;');

            $result = StreamUtils::readRawUntil(
                $stream,
                fn(Token $t): bool => $t->type === TokenType::SEMICOLON,
            );

            expect($result)->toBe('hello');
        });
    });

    describe('readRawUntilToken()', function () {
        it('reads until specified token type', function () {
            $stream = makeStream('color: red;');

            $result = StreamUtils::readRawUntilToken($stream, TokenType::COLON);

            expect($result)->toBe('color');
        });
    });

    describe('readRawUntilIdentifier()', function () {
        it('reads until one of the specified keywords', function () {
            $stream = makeStream('100px through 200px');

            $result = StreamUtils::readRawUntilIdentifier($stream, ['to', 'through']);

            expect($result)->toBe('100px');
        });
    });

    describe('consumeSemicolonFromStream()', function () {
        it('consumes semicolon from stream', function () {
            $stream = makeStream(';next');
            StreamUtils::consumeSemicolonFromStream($stream);

            expect($stream->current()->type)->toBe(TokenType::IDENTIFIER)
                ->and($stream->current()->value)->toBe('next');
        });

        it('skips whitespace before semicolon', function () {
            $stream = makeStream('  ;next');
            StreamUtils::consumeSemicolonFromStream($stream);

            expect($stream->current()->type)->toBe(TokenType::IDENTIFIER);
        });
    });

    describe('parseQualifiedIdentifier()', function () {
        it('parses simple identifier', function () {
            $stream = makeStream('color ');

            expect(StreamUtils::parseQualifiedIdentifier($stream))->toBe('color');
        });

        it('parses dotted module path', function () {
            $stream = makeStream('sass.color ');

            expect(StreamUtils::parseQualifiedIdentifier($stream))->toBe('sass.color');
        });

        it('returns empty string when not at identifier', function () {
            $stream = makeStream('123');

            expect(StreamUtils::parseQualifiedIdentifier($stream))->toBe('');
        });
    });

    describe('parseStringToken()', function () {
        it('returns string token value', function () {
            $stream = makeStream('"hello"');

            expect(StreamUtils::parseStringToken($stream))->toBe('hello');
        });

        it('falls back to identifier when not a string', function () {
            $stream = makeStream('world');

            expect(StreamUtils::parseStringToken($stream))->toBe('world');
        });
    });
});
