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
use function str_contains;
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
        $this->stream->skipWhitespaceAndComments();

        $identifier = TokenStreamHelper::parseQualifiedIdentifier($this->stream);

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

        $this->stream->skipWhitespaceAndComments();

        if ($this->stream->is(TokenType::LPAREN)) {
            $arguments = $this->valueContext->parseArgumentList();
        }

        $contentBlock     = [];
        $contentArguments = [];

        $this->stream->skipWhitespaceAndComments();

        if (TokenStreamHelper::consumeKeyword($this->stream, 'using', true)) {
            $contentArguments = $this->parseParameterList();

            $this->stream->skipWhitespaceAndComments();
        }

        if ($this->stream->consume(TokenType::LBRACE)) {
            $this->parsingContext->incrementBlockDepth();

            $contentBlock = $this->parsingContext->parseStatementsInsideBlock();

            $this->parsingContext->decrementBlockDepth();
            $this->stream->consume(TokenType::RBRACE);
        } else {
            TokenStreamHelper::consumeSemicolonFromStream($this->stream);
        }

        return new IncludeNode($namespace, $mixin, $arguments, $contentBlock, $contentArguments);
    }

    public function parseMixinDirective(int $line = 1): AstNode
    {
        $this->stream->skipWhitespaceAndComments();

        $name      = $this->parsingContext->consumeIdentifier();
        $arguments = $this->parseParameterList();

        $this->stream->skipWhitespaceAndComments();

        $body = $this->parsingContext->parseBlock();

        return new MixinNode($name, $arguments, $body, $line);
    }

    public function parseFunctionDirective(int $line = 1, int $column = 1): AstNode
    {
        $this->stream->skipWhitespaceAndComments();

        $name          = '';
        $rawName       = '';
        $parenPosition = false;

        if ($this->stream->is(TokenType::IDENTIFIER)) {
            $name = $this->parsingContext->consumeIdentifier();
        } elseif ($this->stream->is(TokenType::CSS_VARIABLE)) {
            $rawName       = $this->stream->current()->value;
            $parenPosition = strpos($rawName, '(');
            $name          = $parenPosition === false ? $rawName : substr($rawName, 0, $parenPosition);

            $this->stream->advance();

            if (str_contains($name, '#{') && ! str_contains($name, '}')) {
                if ($this->stream->is(TokenType::RBRACE)) {
                    $name .= '}';

                    $this->stream->advance();
                }
            }
        }

        if (str_starts_with($name, '--')) {
            // Resolve interpolation in the name: --#{a} → --a
            while (str_contains($name, '#{')) {
                $start = strpos($name, '#{');

                if ($start === false) {
                    break;
                }

                $end = strpos($name, '}', $start + 2);

                if ($end === false) {
                    break;
                }

                $name = substr($name, 0, $start) . substr($name, $start + 2, $end - $start - 2) . substr($name, $end + 1);
            }

            $selector = '@function ' . $name;

            // The CSS_VARIABLE token may already contain '(' and part of the signature
            if ($parenPosition !== false) {
                $partialSignature = substr($rawName, $parenPosition);

                // Check if this contains Sass-style $ parameters — strip them (not valid CSS)
                if (str_contains($partialSignature, '$')) {
                    $depth = substr_count($partialSignature, '(') - substr_count($partialSignature, ')');

                    while (! $this->stream->isEof() && $depth > 0) {
                        $token = $this->stream->current();

                        if ($token->type === TokenType::LPAREN) {
                            $depth++;
                        } elseif ($token->type === TokenType::RPAREN) {
                            $depth--;
                        }

                        $this->stream->advance();
                    }

                    $selector .= '()';
                } else {
                    $signature = $partialSignature;
                    $depth     = substr_count($partialSignature, '(') - substr_count($partialSignature, ')');

                    while (! $this->stream->isEof() && $depth > 0) {
                        $token = $this->stream->current();

                        if ($token->type === TokenType::LPAREN) {
                            $depth++;
                        } elseif ($token->type === TokenType::RPAREN) {
                            $depth--;
                        }

                        $signature .= $token->type === TokenType::WHITESPACE
                            ? ' '
                            : TokenStreamHelper::tokenToRawString($token->type, $token->value);

                        $this->stream->advance();
                    }

                    $selector .= $signature;
                }
            } elseif ($this->stream->consume(TokenType::RPAREN)) {
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
                        : TokenStreamHelper::tokenToRawString($token->type, $token->value);

                    $this->stream->advance();
                }

                $selector .= $signature;
            }

            // Handle CSS function return type: returns <ident>
            $this->stream->skipWhitespaceAndComments();

            if (TokenStreamHelper::consumeKeyword($this->stream, 'returns', true)) {
                $selector .= ' returns';

                $this->stream->skipWhitespaceAndComments();

                $returnType = '';

                while (! $this->stream->isEof() && ! $this->stream->is(TokenType::LBRACE)) {
                    $token = $this->stream->current();

                    $returnType .= $token->type === TokenType::WHITESPACE
                        ? ' '
                        : TokenStreamHelper::tokenToRawString($token->type, $token->value);

                    $this->stream->advance();
                }

                if ($returnType !== '') {
                    $selector .= ' ' . trim($returnType);
                }
            }

            $this->stream->skipWhitespaceAndComments();

            return $this->parsingContext->parseRuleFromSelector($selector, $line, $column);
        }

        $arguments = $this->parseParameterList();

        $this->stream->skipWhitespaceAndComments();

        $body = $this->parsingContext->parseBlock();

        return new FunctionDeclarationNode($name, $arguments, $body, $line, $column);
    }

    public function parseReturnDirective(): AstNode
    {
        $this->stream->skipWhitespace();

        $value = $this->valueContext->parseValue();

        TokenStreamHelper::consumeSemicolonFromStream($this->stream);

        return new ReturnNode($value);
    }

    /**
     * @return array<int, ArgumentNode>
     */
    private function parseParameterList(): array
    {
        $arguments = [];

        $this->stream->skipWhitespaceAndComments();

        if (! $this->stream->consume(TokenType::LPAREN)) {
            return $arguments;
        }

        while (! $this->stream->match(TokenType::RPAREN, TokenType::EOF)) {
            $this->stream->skipWhitespaceAndComments();

            if ($this->stream->is(TokenType::RPAREN)) {
                break;
            }

            if ($this->stream->consume(TokenType::DOLLAR)) {
                $varName = $this->parsingContext->consumeIdentifier();

                $this->stream->skipWhitespaceAndComments();

                $defaultValue = null;

                if ($this->stream->consume(TokenType::COLON)) {
                    $this->stream->skipWhitespaceAndComments();

                    $defaultValue = $this->parseParameterDefaultValue();
                }

                $rest = TokenStreamHelper::consumeEllipsis($this->stream);

                $arguments[] = new ArgumentNode($varName, $defaultValue, $rest);

                if ($rest) {
                    TokenStreamHelper::consumeCommaSeparator($this->stream);

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

            TokenStreamHelper::consumeCommaSeparator($this->stream);
        }

        $this->stream->consume(TokenType::RPAREN);

        return $arguments;
    }

    private function parseParameterDefaultValue(): ?AstNode
    {
        $savedPos     = $this->stream->getPosition();
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
