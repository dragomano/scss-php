<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\UseNode;
use Closure;

use function max;
use function strtolower;
use function trim;

final readonly class ModuleDirectiveParser
{
    public function __construct(
        private TokenStream $stream,
        private ModuleDirectiveContextInterface $context,
    ) {}

    public function parseUseDirective(): UseNode
    {
        $this->stream->skipWhitespace();

        $path = $this->context->parseString();

        $namespace     = null;
        $configuration = [];

        $this->stream->skipWhitespace();

        if (StreamUtils::consumeKeyword($this->stream, 'as')) {
            if ($this->stream->consume(TokenType::STAR)) {
                $namespace = '*';
            } else {
                $namespace = $this->context->consumeIdentifier();
            }
        }

        $this->stream->skipWhitespace();

        if (StreamUtils::consumeKeyword($this->stream, 'with')) {
            $configuration = $this->parseUseConfiguration();
        }

        StreamUtils::consumeSemicolonFromStream($this->stream);

        return new UseNode($path, $namespace, $configuration);
    }

    public function parseImportDirective(): ImportNode
    {
        $this->stream->skipWhitespace();

        $imports = [];

        while (! $this->stream->isEof() && ! $this->stream->is(TokenType::SEMICOLON)) {
            $entry = $this->parseImportEntry();

            if ($entry !== '') {
                $imports[] = $entry;
            }

            if (! StreamUtils::consumeCommaSeparator($this->stream)) {
                break;
            }
        }

        StreamUtils::consumeSemicolonFromStream($this->stream);

        return new ImportNode($imports);
    }

    public function parseForwardDirective(): ForwardNode
    {
        $this->stream->skipWhitespace();

        $path = $this->context->parseString();

        $prefix        = null;
        $visibility    = null;
        $members       = [];
        $configuration = [];

        while (! $this->stream->isEof() && ! $this->stream->is(TokenType::SEMICOLON)) {
            $this->stream->skipWhitespace();

            if (! $this->stream->is(TokenType::IDENTIFIER)) {
                break;
            }

            $keyword = strtolower($this->stream->current()->value);

            if ($keyword === 'as') {
                StreamUtils::consumeKeyword($this->stream, $keyword, true);

                $prefix = $this->context->consumeIdentifier();

                $this->stream->skipWhitespace();
                $this->stream->consume(TokenType::STAR);
            } elseif ($keyword === 'hide' || $keyword === 'show') {
                $visibility = $keyword;

                StreamUtils::consumeKeyword($this->stream, $keyword, true);

                while (! $this->stream->isEof() && ! $this->stream->is(TokenType::SEMICOLON)) {
                    $isVariable = $this->stream->consume(TokenType::DOLLAR) !== null;
                    $name       = $this->context->consumeIdentifier();

                    if ($name !== '') {
                        $members[] = $isVariable ? '$' . $name : $name;
                    }

                    if (! StreamUtils::consumeCommaSeparator($this->stream)) {
                        break;
                    }
                }
            } elseif ($keyword === 'with') {
                StreamUtils::consumeKeyword($this->stream, $keyword, true);

                $configuration = $this->parseForwardConfiguration();
            } else {
                break;
            }
        }

        StreamUtils::consumeSemicolonFromStream($this->stream);

        return new ForwardNode($path, $prefix, $visibility, $members, $configuration);
    }

    /**
     * @return array<string, AstNode>
     */
    private function parseUseConfiguration(): array
    {
        return $this->parseModuleConfiguration();
    }

    private function parseImportEntry(): string
    {
        $entry        = '';
        $parenDepth   = 0;
        $bracketDepth = 0;

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if (
                $parenDepth === 0
                && $bracketDepth === 0
                && ($token->type === TokenType::COMMA || $token->type === TokenType::SEMICOLON)
            ) {
                break;
            }

            if ($token->type === TokenType::LPAREN) {
                $parenDepth++;
            } elseif ($token->type === TokenType::RPAREN) {
                $parenDepth = max(0, $parenDepth - 1);
            } elseif ($token->type === TokenType::LBRACKET) {
                $bracketDepth++;
            } elseif ($token->type === TokenType::RBRACKET) {
                $bracketDepth = max(0, $bracketDepth - 1);
            }

            if ($token->type === TokenType::WHITESPACE) {
                $entry .= ' ';
            } elseif ($token->type === TokenType::STRING) {
                $entry .= '"' . $token->value . '"';
            } else {
                $entry .= StreamUtils::tokenToRawString($token->type, $token->value);
            }

            $this->stream->advance();
        }

        return trim($entry);
    }

    /**
     * @return array<string, array{value: AstNode, default: bool}>
     */
    private function parseForwardConfiguration(): array
    {
        return $this->parseModuleConfigurationWithDefaultModifier();
    }

    /**
     * @return array<string, AstNode>
     */
    private function parseModuleConfiguration(): array
    {
        return $this->parseModuleConfigurationEntries(
            static fn(AstNode $value): AstNode => $value,
        );
    }

    /**
     * @return array<string, array{value: AstNode, default: bool}>
     */
    private function parseModuleConfigurationWithDefaultModifier(): array
    {
        return $this->parseModuleConfigurationEntries(
            function (AstNode $value): array {
                $modifiers = $this->context->parseValueModifiers();

                return [
                    'value'   => $value,
                    'default' => $modifiers['default'],
                ];
            },
        );
    }

    /**
     * @template TEntry of AstNode|array{value: AstNode, default: bool}
     *
     * @param Closure(AstNode): TEntry $buildEntry
     *
     * @return array<string, TEntry>
     */
    private function parseModuleConfigurationEntries(Closure $buildEntry): array
    {
        $configuration = [];

        if (! $this->stream->consume(TokenType::LPAREN)) {
            return $configuration;
        }

        while (! $this->stream->isEof()) {
            $this->stream->skipWhitespace();

            if ($this->stream->consume(TokenType::RPAREN)) {
                break;
            }

            if (! $this->stream->consume(TokenType::DOLLAR)) {
                break;
            }

            $name = $this->context->consumeIdentifier();

            $this->stream->skipWhitespace();

            if (! $this->stream->consume(TokenType::COLON)) {
                break;
            }

            $value = $this->context
                ->parseValueUntil([TokenType::COMMA, TokenType::RPAREN]) ?? new ListNode([], 'comma');

            $configuration[$name] = $buildEntry($value);

            $this->stream->skipWhitespace();

            if ($this->stream->consume(TokenType::COMMA)) {
                continue;
            }

            if ($this->stream->consume(TokenType::RPAREN)) {
                break;
            }
        }

        return $configuration;
    }
}
