<?php

declare(strict_types=1);

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MixinNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Parser\CallableDirectiveParser;
use Bugo\SCSS\Parser\CallableDirectiveParsingContextInterface;
use Bugo\SCSS\Parser\CallableDirectiveValueContextInterface;
use Bugo\SCSS\Parser\StreamUtils;

function callableDirectiveToken(
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
function createCallableDirectiveParser(array $tokens, array $overrides = []): CallableDirectiveParser
{
    $stream     = new TokenStream($tokens);
    $parseBlock = $overrides['parseBlock'] ?? static fn(): array => [];

    $parseStatementsInsideBlock = $overrides['parseStatementsInsideBlock'] ?? function () use ($stream): array {
        while (! $stream->isEof() && ! $stream->is(TokenType::RBRACE)) {
            $stream->advance();
        }

        return [];
    };

    $parseValue = $overrides['parseValue'] ?? static fn(): AstNode => new StringNode('value');

    $parseValueUntil = $overrides['parseValueUntil'] ?? function (array $stopTypes) use ($stream): ?AstNode {
        $buffer = '';

        while (! $stream->isEof() && ! $stream->match(...$stopTypes)) {
            $buffer .= $stream->current()->type === TokenType::WHITESPACE
                ? ' '
                : StreamUtils::tokenToRawString($stream->current()->type, $stream->current()->value);

            $stream->advance();
        }

        $buffer = trim($buffer);

        return $buffer === '' ? null : new StringNode($buffer);
    };

    $parseArgumentList = $overrides['parseArgumentList'] ?? static fn(): array => [];
    $consumeIdentifier = $overrides['consumeIdentifier'] ?? function () use ($stream): string {
        return StreamUtils::consumeIdentifier($stream);
    };

    $parseRuleFromSelector = $overrides['parseRuleFromSelector']
        ?? static fn(string $selector, int $line, int $column): RuleNode => new RuleNode($selector, [], $line, $column);

    $incrementBlockDepth = $overrides['incrementBlockDepth'] ?? static function (): void {};
    $decrementBlockDepth = $overrides['decrementBlockDepth'] ?? static function (): void {};

    $parsingContext = new class (
        $parseBlock,
        $parseStatementsInsideBlock,
        $consumeIdentifier,
        $parseRuleFromSelector,
        $incrementBlockDepth,
        $decrementBlockDepth,
    ) implements CallableDirectiveParsingContextInterface {
        public function __construct(
            private readonly Closure $parseBlock,
            private readonly Closure $parseStatementsInsideBlock,
            private readonly Closure $consumeIdentifier,
            private readonly Closure $parseRuleFromSelector,
            private readonly Closure $incrementBlockDepth,
            private readonly Closure $decrementBlockDepth,
        ) {}

        public function parseBlock(): array
        {
            return ($this->parseBlock)();
        }

        public function parseStatementsInsideBlock(): array
        {
            return ($this->parseStatementsInsideBlock)();
        }

        public function consumeIdentifier(): string
        {
            return ($this->consumeIdentifier)();
        }

        public function parseRuleFromSelector(string $selector, int $line = 1, int $column = 1): RuleNode
        {
            return ($this->parseRuleFromSelector)($selector, $line, $column);
        }

        public function incrementBlockDepth(): void
        {
            ($this->incrementBlockDepth)();
        }

        public function decrementBlockDepth(): void
        {
            ($this->decrementBlockDepth)();
        }
    };

    $valueContext = new class (
        $parseValue,
        $parseValueUntil,
        $parseArgumentList,
    ) implements CallableDirectiveValueContextInterface {
        public function __construct(
            private readonly Closure $parseValue,
            private readonly Closure $parseValueUntil,
            private readonly Closure $parseArgumentList,
        ) {}

        public function parseValue(): AstNode
        {
            return ($this->parseValue)();
        }

        public function parseValueUntil(array $stopTokens): ?AstNode
        {
            return ($this->parseValueUntil)($stopTokens);
        }

        public function parseArgumentList(): array
        {
            return ($this->parseArgumentList)();
        }
    };

    return new CallableDirectiveParser(
        $stream,
        $parsingContext,
        $valueContext,
    );
}

describe('CallableDirectiveParser', function () {
    it('parses custom-property-like function signatures as rules', function () {
        $parser = createCallableDirectiveParser([
            callableDirectiveToken(TokenType::WHITESPACE, ' '),
            callableDirectiveToken(TokenType::CSS_VARIABLE, '--token'),
            callableDirectiveToken(TokenType::LPAREN, '('),
            callableDirectiveToken(TokenType::DOLLAR, '$'),
            callableDirectiveToken(TokenType::IDENTIFIER, 'value'),
            callableDirectiveToken(TokenType::COMMA, ','),
            callableDirectiveToken(TokenType::WHITESPACE, ' '),
            callableDirectiveToken(TokenType::IDENTIFIER, 'calc'),
            callableDirectiveToken(TokenType::LPAREN, '('),
            callableDirectiveToken(TokenType::NUMBER, '1'),
            callableDirectiveToken(TokenType::WHITESPACE, ' '),
            callableDirectiveToken(TokenType::PLUS, '+'),
            callableDirectiveToken(TokenType::WHITESPACE, ' '),
            callableDirectiveToken(TokenType::NUMBER, '2'),
            callableDirectiveToken(TokenType::RPAREN, ')'),
            callableDirectiveToken(TokenType::RPAREN, ')'),
            callableDirectiveToken(TokenType::WHITESPACE, ' '),
            callableDirectiveToken(TokenType::LBRACE, '{'),
            callableDirectiveToken(TokenType::RBRACE, '}'),
            callableDirectiveToken(TokenType::EOF),
        ]);

        /* @var $node RuleNode */
        $node = $parser->parseFunctionDirective(3, 5);

        expect($node)->toBeInstanceOf(RuleNode::class)
            ->and($node->selector)->toBe('@function --token($value, calc(1 + 2))')
            ->and($node->line)->toBe(3)
            ->and($node->column)->toBe(5);
    });

    it('parses bare identifiers in include content parameter lists', function () {
        $parser = createCallableDirectiveParser([
            callableDirectiveToken(TokenType::WHITESPACE, ' '),
            callableDirectiveToken(TokenType::IDENTIFIER, 'media'),
            callableDirectiveToken(TokenType::WHITESPACE, ' '),
            callableDirectiveToken(TokenType::IDENTIFIER, 'using'),
            callableDirectiveToken(TokenType::WHITESPACE, ' '),
            callableDirectiveToken(TokenType::LPAREN, '('),
            callableDirectiveToken(TokenType::IDENTIFIER, 'type'),
            callableDirectiveToken(TokenType::RPAREN, ')'),
            callableDirectiveToken(TokenType::WHITESPACE, ' '),
            callableDirectiveToken(TokenType::LBRACE, '{'),
            callableDirectiveToken(TokenType::RBRACE, '}'),
            callableDirectiveToken(TokenType::EOF),
        ]);

        $node = $parser->parseIncludeDirective();

        expect($node)->toBeInstanceOf(IncludeNode::class)
            ->and($node->name)->toBe('media')
            ->and($node->contentArguments)->toHaveCount(1)
            ->and($node->contentArguments[0])->toBeInstanceOf(ArgumentNode::class)
            ->and($node->contentArguments[0]->name)->toBe('type');
    });

    it('falls back to raw strings for empty list default parameter values', function () {
        $parser = createCallableDirectiveParser([
            callableDirectiveToken(TokenType::IDENTIFIER, 'sample'),
            callableDirectiveToken(TokenType::LPAREN, '('),
            callableDirectiveToken(TokenType::DOLLAR, '$'),
            callableDirectiveToken(TokenType::IDENTIFIER, 'flag'),
            callableDirectiveToken(TokenType::COLON, ':'),
            callableDirectiveToken(TokenType::EXCLAMATION, '!'),
            callableDirectiveToken(TokenType::IDENTIFIER, 'optional'),
            callableDirectiveToken(TokenType::RPAREN, ')'),
            callableDirectiveToken(TokenType::LBRACE, '{'),
            callableDirectiveToken(TokenType::RBRACE, '}'),
            callableDirectiveToken(TokenType::EOF),
        ], [
            'parseValueUntil' => static fn(array $stopTypes): AstNode => new ListNode([], 'comma'),
        ]);

        /** @var MixinNode $node */
        $node = $parser->parseMixinDirective();

        expect($node)->toBeInstanceOf(MixinNode::class)
            ->and($node->arguments)->toHaveCount(1)
            ->and($node->arguments[0]->defaultValue)->toBeInstanceOf(StringNode::class)
            ->and($node->arguments[0]->defaultValue->value)->toBe('!optional');
    });

    it('returns null for empty fallback default parameter values', function () {
        $parser = createCallableDirectiveParser([
            callableDirectiveToken(TokenType::IDENTIFIER, 'sample'),
            callableDirectiveToken(TokenType::LPAREN, '('),
            callableDirectiveToken(TokenType::DOLLAR, '$'),
            callableDirectiveToken(TokenType::IDENTIFIER, 'flag'),
            callableDirectiveToken(TokenType::COLON, ':'),
            callableDirectiveToken(TokenType::RPAREN, ')'),
            callableDirectiveToken(TokenType::LBRACE, '{'),
            callableDirectiveToken(TokenType::RBRACE, '}'),
            callableDirectiveToken(TokenType::EOF),
        ], [
            'parseValueUntil' => static fn(array $stopTypes): AstNode => new ListNode([], 'comma'),
        ]);

        /** @var MixinNode $node */
        $node = $parser->parseMixinDirective();

        expect($node)->toBeInstanceOf(MixinNode::class)
            ->and($node->arguments)->toHaveCount(1)
            ->and($node->arguments[0]->defaultValue)->toBeNull();
    });

    it('stops parsing parameter lists when only whitespace remains before the closing parenthesis', function () {
        $parser = createCallableDirectiveParser([
            callableDirectiveToken(TokenType::IDENTIFIER, 'sample'),
            callableDirectiveToken(TokenType::LPAREN, '('),
            callableDirectiveToken(TokenType::WHITESPACE, ' '),
            callableDirectiveToken(TokenType::RPAREN, ')'),
            callableDirectiveToken(TokenType::LBRACE, '{'),
            callableDirectiveToken(TokenType::RBRACE, '}'),
            callableDirectiveToken(TokenType::EOF),
        ]);

        /* @var $node MixinNode */
        $node = $parser->parseMixinDirective();

        expect($node)->toBeInstanceOf(MixinNode::class)
            ->and($node->name)->toBe('sample')
            ->and($node->arguments)->toBe([]);
    });

    it('stops parsing parameter lists when a bare identifier cannot be read', function () {
        $parser = createCallableDirectiveParser([
            callableDirectiveToken(TokenType::IDENTIFIER, 'sample'),
            callableDirectiveToken(TokenType::LPAREN, '('),
            callableDirectiveToken(TokenType::COMMA, ','),
            callableDirectiveToken(TokenType::RPAREN, ')'),
            callableDirectiveToken(TokenType::LBRACE, '{'),
            callableDirectiveToken(TokenType::RBRACE, '}'),
            callableDirectiveToken(TokenType::EOF),
        ]);

        /* @var $node MixinNode */
        $node = $parser->parseMixinDirective();

        expect($node)->toBeInstanceOf(MixinNode::class)
            ->and($node->name)->toBe('sample')
            ->and($node->arguments)->toBe([]);
    });
});
