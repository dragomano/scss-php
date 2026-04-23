<?php

declare(strict_types=1);

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Parser\FunctionCallParser;
use Bugo\SCSS\Parser\FunctionCallParsingContextInterface;
use Bugo\SCSS\Parser\InlineValueParserInterface;
use Bugo\SCSS\Parser\StreamUtils;

function functionCallToken(
    TokenType $type,
    string $value = '',
    int $line = 1,
    int $column = 1,
): Token {
    return new Token($type, $value, $line, $column);
}

function createFunctionCallParser(array $tokens, array $overrides = []): array
{
    $stream = new TokenStream($tokens);

    $parseInlineValue = $overrides['parseInlineValue'] ?? static fn(string $expression): AstNode => new StringNode($expression);

    $parseSingleValue = $overrides['parseSingleValue'] ?? function () use ($stream): ?AstNode {
        $token = $stream->current();

        if ($token->type === TokenType::IDENTIFIER) {
            $stream->advance();

            return new StringNode($token->value, false, $token->line, $token->column);
        }

        if ($token->type === TokenType::STRING) {
            $stream->advance();

            return new StringNode($token->value, true, $token->line, $token->column);
        }

        if ($token->type === TokenType::NUMBER) {
            $stream->advance();

            $value = str_contains($token->value, '.') ? (float) $token->value : (int) $token->value;

            return new NumberNode($value);
        }

        if ($token->type === TokenType::DOLLAR) {
            $stream->advance();

            if (! $stream->is(TokenType::IDENTIFIER)) {
                return null;
            }

            $name = $stream->current()->value;
            $stream->advance();

            return new VariableReferenceNode($name);
        }

        return null;
    };

    $parseValueUntil = $overrides['parseValueUntil'] ?? function (array $stopTypes) use ($stream): ?AstNode {
        $buffer = '';

        while (! $stream->isEof()) {
            $token = $stream->current();

            if (in_array($token->type, $stopTypes, true)) {
                break;
            }

            $buffer .= $token->type === TokenType::WHITESPACE
                ? ' '
                : StreamUtils::tokenToRawString($token->type, $token->value);

            $stream->advance();
        }

        $buffer = trim($buffer);

        return $buffer === '' ? null : new StringNode($buffer);
    };

    $parseVariableReference = $overrides['parseVariableReference'] ?? function () use ($stream): VariableReferenceNode {
        $stream->consume(TokenType::DOLLAR);

        $name = StreamUtils::consumeIdentifier($stream);

        return new VariableReferenceNode($name);
    };

    $parseCommaSeparatedValue = $overrides['parseCommaSeparatedValue'] ?? function () use ($stream): ?AstNode {
        $buffer = '';

        while (! $stream->isEof()) {
            $token = $stream->current();

            if (in_array($token->type, [TokenType::COMMA, TokenType::RPAREN], true)) {
                break;
            }

            $buffer .= $token->type === TokenType::WHITESPACE
                ? ' '
                : StreamUtils::tokenToRawString($token->type, $token->value);

            $stream->advance();
        }

        $buffer = trim($buffer);

        if ($buffer === '') {
            return null;
        }

        if (is_numeric($buffer)) {
            return new NumberNode(str_contains($buffer, '.') ? (float) $buffer : (int) $buffer);
        }

        return new StringNode($buffer);
    };

    $inlineValueParser = new class ($parseInlineValue) implements InlineValueParserInterface {
        public function __construct(private readonly Closure $parseInlineValue) {}

        public function parseInlineValue(string $expression): AstNode
        {
            return ($this->parseInlineValue)($expression);
        }
    };

    $parsingContext = new class (
        $parseSingleValue,
        $parseValueUntil,
        $parseVariableReference,
        $parseCommaSeparatedValue,
    ) implements FunctionCallParsingContextInterface {
        public function __construct(
            private readonly Closure $parseSingleValue,
            private readonly Closure $parseValueUntil,
            private readonly Closure $parseVariableReference,
            private readonly Closure $parseCommaSeparatedValue,
        ) {}

        public function parseSingleValue(): ?AstNode
        {
            return ($this->parseSingleValue)();
        }

        public function parseValueUntil(array $stopTokens): ?AstNode
        {
            return ($this->parseValueUntil)($stopTokens);
        }

        public function parseVariableReference(): VariableReferenceNode
        {
            return ($this->parseVariableReference)();
        }

        public function parseCommaSeparatedValue(): ?AstNode
        {
            return ($this->parseCommaSeparatedValue)();
        }
    };

    return [new FunctionCallParser($stream, $inlineValueParser, $parsingContext), $stream];
}

describe('FunctionCallParser', function () {
    it('parses module variables from qualified identifiers', function () {
        [$parser] = createFunctionCallParser([
            functionCallToken(TokenType::IDENTIFIER, 'theme.$color'),
            functionCallToken(TokenType::EOF),
        ]);

        $node = $parser->parseIdentifierOrFunction();

        expect($node)->toBeInstanceOf(VariableReferenceNode::class)
            ->and($node->name)->toBe('theme.color');
    });

    it('parses nested url contents and reparses non-plain urls', function () {
        [$parser] = createFunctionCallParser([
            functionCallToken(TokenType::LPAREN, '('),
            functionCallToken(TokenType::LPAREN, '('),
            functionCallToken(TokenType::IDENTIFIER, 'asset'),
            functionCallToken(TokenType::RPAREN, ')'),
            functionCallToken(TokenType::HASH, 'hash'),
            functionCallToken(TokenType::RPAREN, ')'),
            functionCallToken(TokenType::EOF),
        ]);

        $node = $parser->parseUrlFunctionFromName();

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->name)->toBe('url')
            ->and($node->arguments)->toHaveCount(1)
            ->and($node->arguments[0])->toBeInstanceOf(StringNode::class)
            ->and($node->arguments[0]->value)->toBe('(asset)#hash');
    });

    it('falls back to plain function arguments for non-variable colon syntax', function () {
        [$parser] = createFunctionCallParser([
            functionCallToken(TokenType::LPAREN, '('),
            functionCallToken(TokenType::IDENTIFIER, 'width'),
            functionCallToken(TokenType::COLON, ':'),
            functionCallToken(TokenType::WHITESPACE, ' '),
            functionCallToken(TokenType::IDENTIFIER, 'large'),
            functionCallToken(TokenType::RPAREN, ')'),
            functionCallToken(TokenType::EOF),
        ]);

        $node = $parser->parseFunctionFromName('scale');

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->arguments)->toHaveCount(1)
            ->and($node->arguments[0])->toBeInstanceOf(StringNode::class)
            ->and($node->arguments[0]->value)->toBe('width: large');
    });

    it('escapes backslashes when quoting strings for reparsing', function () {
        [$parser] = createFunctionCallParser([
            functionCallToken(TokenType::LPAREN, '('),
            functionCallToken(TokenType::STRING, 'a\\b'),
            functionCallToken(TokenType::RPAREN, ')'),
            functionCallToken(TokenType::EOF),
        ]);

        $node = $parser->parseUrlFunctionFromName();

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->arguments)->toHaveCount(1)
            ->and($node->arguments[0])->toBeInstanceOf(StringNode::class)
            ->and($node->arguments[0]->value)->toBe('"a\\\\b"');
    });

    it('restores stream position when inline if condition is missing', function () {
        [$parser, $stream] = createFunctionCallParser([
            functionCallToken(TokenType::LPAREN, '('),
            functionCallToken(TokenType::RPAREN, ')'),
            functionCallToken(TokenType::EOF),
        ], [
            'parseValueUntil' => static fn(array $stopTypes): ?AstNode => null,
        ]);

        $result = $parser->parseFunctionFromName('if');

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->arguments)->toBe([])
            ->and($stream->current()->type)->toBe(TokenType::EOF);
    });

    it('rejects empty and interpolation-only unquoted urls', function () {
        [$parser] = createFunctionCallParser([
            functionCallToken(TokenType::LPAREN, '('),
            functionCallToken(TokenType::RPAREN, ')'),
            functionCallToken(TokenType::EOF),
        ]);

        $emptyUrl = $parser->parseUrlFunctionFromName();

        [$parserWithInterpolation] = createFunctionCallParser([
            functionCallToken(TokenType::LPAREN, '('),
            functionCallToken(TokenType::HASH),
            functionCallToken(TokenType::LBRACE, '{'),
            functionCallToken(TokenType::IDENTIFIER, 'asset'),
            functionCallToken(TokenType::RBRACE, '}'),
            functionCallToken(TokenType::RPAREN, ')'),
            functionCallToken(TokenType::EOF),
        ]);

        $interpolatedUrl = $parserWithInterpolation->parseUrlFunctionFromName();

        expect($emptyUrl)->toBeInstanceOf(FunctionNode::class)
            ->and($emptyUrl->arguments)->toBe([])
            ->and($interpolatedUrl)->toBeInstanceOf(FunctionNode::class)
            ->and($interpolatedUrl->arguments)->toHaveCount(1)
            ->and($interpolatedUrl->arguments[0])->toBeInstanceOf(StringNode::class)
            ->and($interpolatedUrl->arguments[0]->value)->toBe('#{asset}');
    });

    it('converts variable references and lists back to strings', function () {
        $stream = null;
        $call   = 0;

        [$parser, $stream] = createFunctionCallParser([
            functionCallToken(TokenType::LPAREN, '('),
            functionCallToken(TokenType::IDENTIFIER, 'left'),
            functionCallToken(TokenType::ASSIGN, '='),
            functionCallToken(TokenType::IDENTIFIER, 'right'),
            functionCallToken(TokenType::RPAREN, ')'),
            functionCallToken(TokenType::EOF),
        ], [
            'parseSingleValue' => function () use (&$stream, &$call): ?AstNode {
                $call++;
                $stream?->advance();

                return match ($call) {
                    1 => new ListNode([
                        new VariableReferenceNode('theme.color'),
                        new StringNode('solid'),
                    ]),
                    2 => new VariableReferenceNode('fallback'),
                    default => null,
                };
            },
        ]);

        $node = $parser->parseFunctionFromName('legacy');

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->arguments)->toHaveCount(1)
            ->and($node->arguments[0])->toBeInstanceOf(StringNode::class)
            ->and($node->arguments[0]->value)->toBe('$theme.color solid=$fallback');
    });

    it('returns an empty string for unsupported node types', function () {
        $stream = null;
        $call   = 0;

        [$parser, $stream] = createFunctionCallParser([
            functionCallToken(TokenType::LPAREN, '('),
            functionCallToken(TokenType::IDENTIFIER, 'left'),
            functionCallToken(TokenType::ASSIGN, '='),
            functionCallToken(TokenType::IDENTIFIER, 'right'),
            functionCallToken(TokenType::RPAREN, ')'),
            functionCallToken(TokenType::EOF),
        ], [
            'parseSingleValue' => function () use (&$stream, &$call): ?AstNode {
                $call++;
                $stream?->advance();

                return match ($call) {
                    1 => new class extends AstNode {},
                    2 => new StringNode('fallback'),
                    default => null,
                };
            },
        ]);

        $node = $parser->parseFunctionFromName('legacy');

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->arguments)->toHaveCount(1)
            ->and($node->arguments[0])->toBeInstanceOf(StringNode::class)
            ->and($node->arguments[0]->value)->toBe('=fallback');
    });

    it('breaks out of the function argument loop after the iteration guard is hit', function () {
        [$parser, $stream] = createFunctionCallParser([
            functionCallToken(TokenType::LPAREN, '('),
            functionCallToken(TokenType::IDENTIFIER, 'stuck'),
            functionCallToken(TokenType::EOF),
        ], [
            'parseSingleValue' => static fn(): ?AstNode => new StringNode('stuck'),
            'parseCommaSeparatedValue' => static fn(): ?AstNode => null,
        ]);

        $node = $parser->parseFunctionFromName('loop');

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->arguments)->toBe([])
            ->and($stream->current()->type)->toBe(TokenType::IDENTIFIER);
    });

    it('stops parsing function arguments when no parser can consume the current token', function () {
        [$parser, $stream] = createFunctionCallParser([
            functionCallToken(TokenType::LPAREN, '('),
            functionCallToken(TokenType::AT, '@'),
            functionCallToken(TokenType::EOF),
        ], [
            'parseCommaSeparatedValue' => static fn(): ?AstNode => null,
        ]);

        $node = $parser->parseFunctionFromName('broken');

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->arguments)->toBe([])
            ->and($stream->current()->type)->toBe(TokenType::AT);
    });
});
