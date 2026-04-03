<?php

declare(strict_types=1);

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Parser\ModuleDirectiveParser;
use Bugo\SCSS\Parser\StreamUtils;

function moduleDirectiveToken(
    TokenType $type,
    string $value = '',
    int $line = 1,
    int $column = 1
): Token {
    return new Token($type, $value, $line, $column);
}

/**
 * @param array<int, Token> $tokens
 * @param array<string, mixed> $overrides
 */
function createModuleDirectiveParser(array $tokens, array $overrides = []): ModuleDirectiveParser
{
    $stream = new TokenStream($tokens);

    $parseString = $overrides['parseString'] ?? function () use ($stream): string {
        return StreamUtils::parseStringToken($stream);
    };
    $consumeIdentifier = $overrides['consumeIdentifier'] ?? function () use ($stream): string {
        return StreamUtils::consumeIdentifier($stream);
    };
    $parseValueUntil = $overrides['parseValueUntil'] ?? function (array $stopTokens) use ($stream): ?AstNode {
        $buffer = '';

        while (! $stream->isEof() && ! $stream->match(...$stopTokens)) {
            $buffer .= $stream->current()->type === TokenType::WHITESPACE
                ? ' '
                : StreamUtils::tokenToRawString($stream->current()->type, $stream->current()->value);

            $stream->advance();
        }

        $buffer = trim($buffer);

        return $buffer === '' ? null : new StringNode($buffer);
    };
    $parseValueModifiers = $overrides['parseValueModifiers'] ?? static fn(): array => [
        'global' => false,
        'default' => false,
        'important' => false,
    ];

    return new ModuleDirectiveParser(
        $stream,
        $parseString,
        $consumeIdentifier,
        $parseValueUntil,
        $parseValueModifiers
    );
}

describe('ModuleDirectiveParser', function () {
    it('continues parsing after show visibility clause and reaches with configuration', function () {
        $parser = createModuleDirectiveParser([
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::STRING, 'forwarded'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'show'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::DOLLAR, '$'),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'public'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'with'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::LPAREN, '('),
            moduleDirectiveToken(TokenType::DOLLAR, '$'),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'primary'),
            moduleDirectiveToken(TokenType::COLON, ':'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'blue'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::EXCLAMATION, '!'),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'default'),
            moduleDirectiveToken(TokenType::RPAREN, ')'),
            moduleDirectiveToken(TokenType::SEMICOLON, ';'),
            moduleDirectiveToken(TokenType::EOF),
        ], [
            'parseValueModifiers' => static fn(): array => [
                'global' => false,
                'default' => true,
                'important' => false,
            ],
        ]);

        $node = $parser->parseForwardDirective();

        expect($node)->toBeInstanceOf(ForwardNode::class)
            ->and($node->path)->toBe('forwarded')
            ->and($node->visibility)->toBe('show')
            ->and($node->members)->toBe(['$public'])
            ->and($node->configuration)->toHaveKey('primary')
            ->and($node->configuration['primary']['default'])->toBeTrue();
    });
});
