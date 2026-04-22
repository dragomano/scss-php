<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionDeclarationNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MixinNode;
use Bugo\SCSS\Nodes\ReturnNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Utils\NameHelper;

use function count;
use function str_starts_with;
use function strpos;
use function substr;
use function trim;

final readonly class CallableDirectiveParser
{
    public function __construct(
        private TokenStream $stream,
        private CallableDirectiveParsingContextInterface $parsingContext,
        private CallableDirectiveValueContextInterface $valueContext,
    ) {}

    public function parseIncludeDirective(): IncludeNode
    {
        $this->stream->skipWhitespace();

        $identifier = StreamUtils::parseQualifiedIdentifier($this->stream);

        $namespace = null;
        $mixin     = $identifier;

        if (NameHelper::hasNamespace($identifier)) {
            $parts = NameHelper::splitQualifiedName($identifier);

            if ($parts['member'] !== null) {
                $namespace = $parts['namespace'];
                $mixin     = $parts['member'];
            }
        }

        $arguments = [];

        $this->stream->skipWhitespace();

        if ($this->stream->is(TokenType::LPAREN)) {
            $arguments = $this->valueContext->parseArgumentList();
        }

        $contentBlock     = [];
        $contentArguments = [];

        $this->stream->skipWhitespace();

        if (StreamUtils::consumeKeyword($this->stream, 'using', true)) {
            $contentArguments = $this->parseParameterList();

            $this->stream->skipWhitespace();
        }

        if ($this->stream->consume(TokenType::LBRACE)) {
            $this->parsingContext->incrementBlockDepth();

            $contentBlock = $this->parsingContext->parseStatementsInsideBlock();

            $this->parsingContext->decrementBlockDepth();
            $this->stream->consume(TokenType::RBRACE);
        } else {
            StreamUtils::consumeSemicolonFromStream($this->stream);
        }

        return new IncludeNode($namespace, $mixin, $arguments, $contentBlock, $contentArguments);
    }

    public function parseMixinDirective(int $line = 1): AstNode
    {
        $this->stream->skipWhitespace();

        $name      = $this->parsingContext->consumeIdentifier();
        $arguments = $this->parseParameterList();
        $body      = $this->parsingContext->parseBlock();

        return new MixinNode($name, $arguments, $body, $line);
    }

    public function parseFunctionDirective(int $line = 1, int $column = 1): AstNode
    {
        $this->stream->skipWhitespace();

        $name = '';

        if ($this->stream->is(TokenType::IDENTIFIER)) {
            $name = $this->parsingContext->consumeIdentifier();
        } elseif ($this->stream->is(TokenType::CSS_VARIABLE)) {
            $rawName = $this->stream->current()->value;

            $parenPosition = strpos($rawName, '(');

            $name = $parenPosition === false ? $rawName : substr($rawName, 0, $parenPosition);

            $this->stream->advance();
        }

        if (str_starts_with($name, '--')) {
            $selector = '@function ' . $name;

            if ($this->stream->consume(TokenType::RPAREN)) {
                $selector .= '()';
            } elseif ($this->stream->consume(TokenType::LPAREN)) {
                $signature = '(';
                $depth     = 1;

                while (! $this->stream->isEof() && $depth > 0) {
                    $token = $this->stream->current();

                    if ($token->type === TokenType::LPAREN) {
                        $depth++;
                    } elseif ($token->type === TokenType::RPAREN) {
                        $depth--;
                    }

                    $signature .= $token->type === TokenType::WHITESPACE
                        ? ' '
                        : StreamUtils::tokenToRawString($token->type, $token->value);

                    $this->stream->advance();
                }

                $selector .= $signature;
            }

            $this->stream->skipWhitespace();

            return $this->parsingContext->parseRuleFromSelector($selector, $line, $column);
        }

        $arguments = $this->parseParameterList();
        $body      = $this->parsingContext->parseBlock();

        return new FunctionDeclarationNode($name, $arguments, $body, $line, $column);
    }

    public function parseReturnDirective(): AstNode
    {
        $this->stream->skipWhitespace();

        $value = $this->valueContext->parseValue();

        StreamUtils::consumeSemicolonFromStream($this->stream);

        return new ReturnNode($value);
    }

    /**
     * @return array<int, ArgumentNode>
     */
    private function parseParameterList(): array
    {
        $arguments = [];

        $this->stream->skipWhitespace();

        if (! $this->stream->consume(TokenType::LPAREN)) {
            return $arguments;
        }

        while (! $this->stream->match(TokenType::RPAREN, TokenType::EOF)) {
            $this->stream->skipWhitespace();

            if ($this->stream->is(TokenType::RPAREN)) {
                break;
            }

            if ($this->stream->consume(TokenType::DOLLAR)) {
                $varName = $this->parsingContext->consumeIdentifier();

                $this->stream->skipWhitespace();

                $defaultValue = null;

                if ($this->stream->consume(TokenType::COLON)) {
                    $this->stream->skipWhitespace();

                    $defaultValue = $this->parseParameterDefaultValue();
                }

                $rest = StreamUtils::consumeEllipsis($this->stream);

                $arguments[] = new ArgumentNode($varName, $defaultValue, $rest);

                if ($rest) {
                    break;
                }
            } else {
                $argument = $this->parsingContext->consumeIdentifier();

                if ($argument !== '') {
                    $arguments[] = new ArgumentNode($argument);
                } else {
                    break;
                }
            }

            StreamUtils::consumeCommaSeparator($this->stream);
        }

        $this->stream->consume(TokenType::RPAREN);

        return $arguments;
    }

    private function parseParameterDefaultValue(): ?AstNode
    {
        $savedPos = $this->stream->getPosition();

        $defaultValue = $this->valueContext->parseValueUntil([TokenType::COMMA, TokenType::RPAREN]);

        if (! ($defaultValue instanceof ListNode) || count($defaultValue->items) !== 0) {
            return $defaultValue;
        }

        $this->stream->setPosition($savedPos);

        $defaultValueStr = '';

        while (! $this->stream->match(TokenType::COMMA, TokenType::RPAREN, TokenType::EOF)) {
            $defaultValueStr .= $this->stream->current()->value;

            $this->stream->advance();
        }

        if ($defaultValueStr === '') {
            return null;
        }

        return new StringNode(trim($defaultValueStr));
    }
}
