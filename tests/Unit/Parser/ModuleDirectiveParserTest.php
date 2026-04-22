<?php

declare(strict_types=1);

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\Parser\ModuleDirectiveContextInterface;
use Bugo\SCSS\Parser\ModuleDirectiveParser;
use Bugo\SCSS\Parser\StreamUtils;

function moduleDirectiveToken(
    TokenType $type,
    string $value = '',
    int $line = 1,
    int $column = 1,
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

    $context = new class (
        $parseString,
        $consumeIdentifier,
        $parseValueUntil,
        $parseValueModifiers,
    ) implements ModuleDirectiveContextInterface {
        public function __construct(
            private readonly Closure $parseString,
            private readonly Closure $consumeIdentifier,
            private readonly Closure $parseValueUntil,
            private readonly Closure $parseValueModifiers,
        ) {}

        public function parseString(): string
        {
            return ($this->parseString)();
        }

        public function consumeIdentifier(): string
        {
            return ($this->consumeIdentifier)();
        }

        public function parseValueUntil(array $stopTokens): ?AstNode
        {
            return ($this->parseValueUntil)($stopTokens);
        }

        public function parseValueModifiers(): array
        {
            return ($this->parseValueModifiers)();
        }
    };

    return new ModuleDirectiveParser($stream, $context);
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

    it('keeps an empty forward configuration when with is not followed by parentheses', function () {
        $parser = createModuleDirectiveParser([
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::STRING, 'forwarded'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'with'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::SEMICOLON, ';'),
            moduleDirectiveToken(TokenType::EOF),
        ]);

        $node = $parser->parseForwardDirective();

        expect($node)->toBeInstanceOf(ForwardNode::class)
            ->and($node->path)->toBe('forwarded')
            ->and($node->configuration)->toBe([]);
    });

    it('accepts an empty forward configuration list', function () {
        $parser = createModuleDirectiveParser([
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::STRING, 'forwarded'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'with'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::LPAREN, '('),
            moduleDirectiveToken(TokenType::RPAREN, ')'),
            moduleDirectiveToken(TokenType::SEMICOLON, ';'),
            moduleDirectiveToken(TokenType::EOF),
        ]);

        $node = $parser->parseForwardDirective();

        expect($node)->toBeInstanceOf(ForwardNode::class)
            ->and($node->path)->toBe('forwarded')
            ->and($node->configuration)->toBe([]);
    });

    it('stops parsing forward configuration when the variable sigil is missing', function () {
        $parser = createModuleDirectiveParser([
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::STRING, 'forwarded'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'with'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::LPAREN, '('),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'primary'),
            moduleDirectiveToken(TokenType::COLON, ':'),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'blue'),
            moduleDirectiveToken(TokenType::RPAREN, ')'),
            moduleDirectiveToken(TokenType::SEMICOLON, ';'),
            moduleDirectiveToken(TokenType::EOF),
        ]);

        $node = $parser->parseForwardDirective();

        expect($node)->toBeInstanceOf(ForwardNode::class)
            ->and($node->path)->toBe('forwarded')
            ->and($node->configuration)->toBe([]);
    });

    it('stops parsing forward configuration when the colon after a variable name is missing', function () {
        $parser = createModuleDirectiveParser([
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::STRING, 'forwarded'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'with'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::LPAREN, '('),
            moduleDirectiveToken(TokenType::DOLLAR, '$'),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'primary'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'blue'),
            moduleDirectiveToken(TokenType::RPAREN, ')'),
            moduleDirectiveToken(TokenType::SEMICOLON, ';'),
            moduleDirectiveToken(TokenType::EOF),
        ]);

        $node = $parser->parseForwardDirective();

        expect($node)->toBeInstanceOf(ForwardNode::class)
            ->and($node->path)->toBe('forwarded')
            ->and($node->configuration)->toBe([]);
    });

    it('stops parsing forward directive when a non-identifier token follows the path', function () {
        $parser = createModuleDirectiveParser([
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::STRING, 'forwarded'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::COLON, ':'),
            moduleDirectiveToken(TokenType::SEMICOLON, ';'),
            moduleDirectiveToken(TokenType::EOF),
        ]);

        $node = $parser->parseForwardDirective();

        expect($node)->toBeInstanceOf(ForwardNode::class)
            ->and($node->path)->toBe('forwarded')
            ->and($node->prefix)->toBeNull()
            ->and($node->visibility)->toBeNull()
            ->and($node->members)->toBe([])
            ->and($node->configuration)->toBe([]);
    });

    it('stops parsing forward directive when it encounters an unknown keyword', function () {
        $parser = createModuleDirectiveParser([
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::STRING, 'forwarded'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'bogus'),
            moduleDirectiveToken(TokenType::SEMICOLON, ';'),
            moduleDirectiveToken(TokenType::EOF),
        ]);

        $node = $parser->parseForwardDirective();

        expect($node)->toBeInstanceOf(ForwardNode::class)
            ->and($node->path)->toBe('forwarded')
            ->and($node->prefix)->toBeNull()
            ->and($node->visibility)->toBeNull()
            ->and($node->members)->toBe([])
            ->and($node->configuration)->toBe([]);
    });

    it('accepts an empty use configuration list', function () {
        $parser = createModuleDirectiveParser([
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::STRING, 'theme'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'with'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::LPAREN, '('),
            moduleDirectiveToken(TokenType::RPAREN, ')'),
            moduleDirectiveToken(TokenType::SEMICOLON, ';'),
            moduleDirectiveToken(TokenType::EOF),
        ]);

        $node = $parser->parseUseDirective();

        expect($node)->toBeInstanceOf(UseNode::class)
            ->and($node->path)->toBe('theme')
            ->and($node->configuration)->toBe([]);
    });

    it('stops parsing use configuration when the variable sigil is missing', function () {
        $parser = createModuleDirectiveParser([
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::STRING, 'theme'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'with'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::LPAREN, '('),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'primary'),
            moduleDirectiveToken(TokenType::COLON, ':'),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'blue'),
            moduleDirectiveToken(TokenType::RPAREN, ')'),
            moduleDirectiveToken(TokenType::SEMICOLON, ';'),
            moduleDirectiveToken(TokenType::EOF),
        ]);

        $node = $parser->parseUseDirective();

        expect($node)->toBeInstanceOf(UseNode::class)
            ->and($node->path)->toBe('theme')
            ->and($node->configuration)->toBe([]);
    });

    it('stops parsing use configuration when the colon after a variable name is missing', function () {
        $parser = createModuleDirectiveParser([
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::STRING, 'theme'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'with'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::LPAREN, '('),
            moduleDirectiveToken(TokenType::DOLLAR, '$'),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'primary'),
            moduleDirectiveToken(TokenType::WHITESPACE, ' '),
            moduleDirectiveToken(TokenType::IDENTIFIER, 'blue'),
            moduleDirectiveToken(TokenType::RPAREN, ')'),
            moduleDirectiveToken(TokenType::SEMICOLON, ';'),
            moduleDirectiveToken(TokenType::EOF),
        ]);

        $node = $parser->parseUseDirective();

        expect($node)->toBeInstanceOf(UseNode::class)
            ->and($node->path)->toBe('theme')
            ->and($node->configuration)->toBe([]);
    });
});
