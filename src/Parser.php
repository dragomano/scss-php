<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\Tokenizer;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Parser\DirectiveParser;
use Bugo\SCSS\Parser\RuleParser;
use Bugo\SCSS\Parser\ValueParser;

use function trim;

final class Parser implements ParserInterface
{
    protected TokenStream $stream;

    protected int $blockDepth = 0;

    protected bool $trackSourceLocations = true;

    private DirectiveParser $directives;

    private RuleParser $rules;

    private ValueParser $values;

    public function __construct(protected Tokenizer $tokenizer = new Tokenizer())
    {
        $this->stream = new TokenStream([new Token(TokenType::EOF, '', 1, 1)]);

        $this->initSubParsers();
    }

    public function setTrackSourceLocations(bool $track): void
    {
        $this->trackSourceLocations = $track;

        $this->tokenizer->setTrackPositions($track);
        $this->rules->setTrackSourceLocations($track);
    }

    public function parse(string $source): RootNode
    {
        $tokens = $this->tokenizer->tokenize($source);

        $this->stream = new TokenStream($tokens);

        $this->blockDepth = 0;

        $this->initSubParsers();

        $children = $this->parseStatements();

        // Release references to large token arrays between parse runs.
        $this->stream = new TokenStream([new Token(TokenType::EOF, '', 1, 1)]);

        $this->initSubParsers();

        return new RootNode($children);
    }

    /**
     * @return array<int, AstNode>
     */
    protected function parseStatements(bool $insideBlock = false): array
    {
        $statements = [];

        while (! $this->stream->isEof()) {
            $this->stream->skipWhitespace();

            if ($this->stream->isEof()) {
                break;
            }

            if (
                $this->stream->is(TokenType::COMMENT_LOUD)
                || $this->stream->is(TokenType::COMMENT_PRESERVED)
            ) {
                $token = $this->stream->current();

                $statements[] = new CommentNode(
                    trim($token->value),
                    $token->type === TokenType::COMMENT_PRESERVED,
                    $token->line,
                    $token->column,
                );

                $this->stream->advance();

                continue;
            }

            if ($insideBlock && $this->stream->is(TokenType::RBRACE)) {
                break;
            }

            $statement = $this->parseStatement();

            if ($statement !== null) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    protected function parseStatement(): ?AstNode
    {
        $this->stream->skipWhitespace();

        if ($this->stream->isEof()) {
            return null;
        }

        if ($this->stream->is(TokenType::AT)) {
            return $this->directives->parseDirective();
        }

        if ($this->stream->is(TokenType::DOLLAR)) {
            return $this->rules->parseVariableDeclaration();
        }

        if ($this->stream->is(TokenType::IDENTIFIER)) {
            $moduleVarDeclaration = $this->rules->parseModuleVarDeclaration();

            if ($moduleVarDeclaration !== null) {
                return $moduleVarDeclaration;
            }
        }

        return $this->rules->parseRuleOrDeclaration();
    }

    /**
     * @return array<int, AstNode>
     */
    protected function parseBlock(): array
    {
        $body = [];

        $this->stream->skipWhitespace();

        if ($this->stream->consume(TokenType::LBRACE)) {
            $this->blockDepth++;

            $body = $this->parseStatementsInsideBlock();

            $this->blockDepth--;

            $this->stream->consume(TokenType::RBRACE);
        }

        return $body;
    }

    /**
     * @return array<int, AstNode>
     */
    protected function parseStatementsInsideBlock(): array
    {
        $statements = [];
        $loopCount  = 0;

        while (! $this->stream->isEof()) {
            $loopCount++;

            if ($loopCount > 1000) {
                break;
            }

            $this->stream->skipWhitespace();

            if (
                $this->stream->is(TokenType::COMMENT_LOUD)
                || $this->stream->is(TokenType::COMMENT_PRESERVED)
            ) {
                $token = $this->stream->current();

                $statements[] = new CommentNode(
                    trim($token->value),
                    $token->type === TokenType::COMMENT_PRESERVED,
                    $token->line,
                    $token->column,
                );

                $this->stream->advance();

                continue;
            }

            if ($this->stream->is(TokenType::RBRACE)) {
                break;
            }

            $statement = $this->parseStatement();

            if ($statement !== null) {
                $statements[] = $statement;
            } else {
                break;
            }
        }

        return $statements;
    }

    protected function parseRuleFromSelector(string $selector, int $line = 1, int $column = 1): RuleNode
    {
        $this->stream->skipWhitespace();

        if (! $this->stream->is(TokenType::LBRACE)) {
            return new RuleNode($selector, [], $line, $column);
        }

        $this->stream->advance();

        $this->blockDepth++;

        $children = $this->parseStatements(true);

        if ($this->stream->is(TokenType::RBRACE)) {
            $this->blockDepth--;

            $this->stream->advance();
        }

        return new RuleNode($selector, $children, $line, $column);
    }

    protected function parseInlineValue(string $expression): AstNode
    {
        $expr = trim($expression);

        if ($expr === '') {
            return new StringNode('');
        }

        $inlineParser = new self();
        $inlineParser->setTrackSourceLocations($this->trackSourceLocations);

        $ast = $inlineParser->parse(".__tmp__ { __tmp__: $expr; }");

        return $this->extractInlineValueFromAst($ast, $expr);
    }

    private function initSubParsers(): void
    {
        $this->values = new ValueParser(
            $this->stream,
            fn(string $expr): AstNode => $this->parseInlineValue($expr),
        );

        $this->rules = new RuleParser(
            $this->stream,
            fn(): AstNode => $this->values->parseValue(),
            fn(): array => $this->values->parseValueModifiers(),
            fn(): string => $this->values->parseCustomPropertyValue(),
            fn(): bool => $this->blockDepth > 0,
            fn(string $selector, int $line, int $column): RuleNode => $this->parseRuleFromSelector(
                $selector,
                $line,
                $column,
            ),
            $this->trackSourceLocations,
        );

        $this->directives = new DirectiveParser(
            $this->stream,
            fn(): array => $this->parseBlock(),
            fn(): array => $this->parseStatementsInsideBlock(),
            fn(): AstNode => $this->values->parseValue(),
            fn(array $stopTokens): ?AstNode => $this->values->parseValueUntil($stopTokens),
            fn(): array => $this->values->parseValueModifiers(),
            fn(): array => $this->values->parseArgumentList(),
            fn(): string => $this->values->parseString(),
            fn(): string => $this->values->consumeIdentifier(),
            fn(string $selector, int $line, int $column): RuleNode => $this->parseRuleFromSelector(
                $selector,
                $line,
                $column,
            ),
            fn(string $expr): AstNode => $this->parseInlineValue($expr),
            function (): void {
                $this->blockDepth++;
            },
            function (): void {
                $this->blockDepth--;
            },
        );
    }

    private function extractInlineValueFromAst(RootNode $ast, string $expr): AstNode
    {
        $ruleNode = $ast->children[0] ?? null;

        if (! $ruleNode instanceof RuleNode) {
            return new StringNode($expr);
        }

        $declaration = $ruleNode->children[0] ?? null;
        if (! $declaration instanceof DeclarationNode) {
            return new StringNode($expr);
        }

        return $declaration->value;
    }
}
