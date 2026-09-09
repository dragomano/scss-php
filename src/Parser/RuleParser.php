<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;

use function in_array;
use function max;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function strpos;
use function strtolower;
use function substr;
use function trim;

final class RuleParser
{
    public function __construct(
        private readonly TokenStream $stream,
        private readonly RuleParserValueContextInterface $valueContext,
        private readonly RuleParserContextInterface $context,
        private bool $trackSourceLocations = true,
    ) {}

    public function setTrackSourceLocations(bool $track): void
    {
        $this->trackSourceLocations = $track;
    }

    public function parseVariableDeclaration(): ?VariableDeclarationNode
    {
        $startToken = $this->stream->consume(TokenType::DOLLAR);

        if ($startToken === null) {
            return null;
        }

        $line = $this->trackSourceLocations ? $startToken->line : 1;
        $name = $this->consumeIdentifier();

        $this->stream->skipWhitespace();

        while ($this->stream->is(TokenType::COMMENT_LOUD) || $this->stream->is(TokenType::COMMENT_PRESERVED)) {
            $this->stream->advance();
            $this->stream->skipWhitespace();
        }

        if (! $this->stream->consume(TokenType::COLON)) {
            return null;
        }

        $this->stream->skipWhitespace();

        $value     = $this->valueContext->parseValue();
        $modifiers = $this->valueContext->parseValueModifiers();

        $this->consumeSemicolon();

        return new VariableDeclarationNode($name, $value, $modifiers['global'], $modifiers['default'], $line);
    }

    public function parseModuleVarDeclaration(): ?ModuleVarDeclarationNode
    {
        $savedPos = $this->stream->getPosition();
        $module   = $this->consumeIdentifier();

        $this->stream->skipWhitespace();

        if (! $this->stream->consume(TokenType::DOT)) {
            $this->stream->setPosition($savedPos);

            return null;
        }

        $this->stream->skipWhitespace();

        if (! $this->stream->consume(TokenType::DOLLAR)) {
            $this->stream->setPosition($savedPos);

            return null;
        }

        $name = $this->consumeIdentifier();

        $this->stream->skipWhitespace();

        if (! $this->stream->consume(TokenType::COLON)) {
            $this->stream->setPosition($savedPos);

            return null;
        }

        $this->stream->skipWhitespace();

        $value     = $this->valueContext->parseValue();
        $modifiers = $this->valueContext->parseValueModifiers();

        $this->consumeSemicolon();

        return new ModuleVarDeclarationNode($module, $name, $value, $modifiers['default']);
    }

    public function parseRuleOrDeclaration(): ?AstNode
    {
        if ($this->stream->is(TokenType::AMPERSAND)) {
            return $this->parseRule();
        }

        if ($this->context->isInsideBraces() && $this->stream->is(TokenType::CSS_VARIABLE)) {
            $token = $this->stream->current();

            if (str_starts_with($token->value, '--')) {
                $continuedByInterpolation = $this->isVariableNameContinuedByInterpolation();

                if (str_contains($token->value, ':') || ! $continuedByInterpolation) {
                    return $this->parseCssVariableDeclaration($token->value, $token->line, $token->column);
                }
            }
        }

        $savedPos    = $this->stream->getPosition();
        $startToken  = $this->stream->current();
        $startLine   = $this->trackSourceLocations ? $startToken->line : 1;
        $startColumn = $this->trackSourceLocations ? $startToken->column : 1;

        $selectorOrProperty = $this->parseSelectorOrProperty();

        $this->stream->skipWhitespace();

        while ($this->stream->is(TokenType::COMMENT_LOUD) || $this->stream->is(TokenType::COMMENT_PRESERVED)) {
            $this->stream->advance();
            $this->stream->skipWhitespace();
        }

        if ($this->stream->is(TokenType::LBRACE)) {
            return $this->context->parseRuleFromSelector($selectorOrProperty, $startLine, $startColumn);
        }

        if ($this->stream->is(TokenType::COLON)) {
            if ($this->context->isInsideBraces()) {
                if (str_starts_with(trim($selectorOrProperty), '--')) {
                    return $this->parseDeclarationFromProperty($selectorOrProperty, $startLine, $startColumn);
                }

                if (
                    $this->context->isInsideCssFunctionBody()
                    && strtolower(trim($selectorOrProperty)) === 'result'
                    && $this->hasRuleBlockAfterColon()
                ) {
                    return $this->parseCssFunctionResultDeclaration($selectorOrProperty, $startLine, $startColumn);
                }

                if ($this->isLikelySelector($selectorOrProperty) && $this->hasRuleBlockAfterColon()) {
                    $this->stream->setPosition($savedPos);

                    return $this->parseRule();
                }

                return $this->parseDeclarationFromProperty($selectorOrProperty, $startLine, $startColumn);
            }

            if ($this->isLikelySelector($selectorOrProperty)) {
                $this->stream->setPosition($savedPos);

                return $this->parseRule();
            }

            $this->stream->advance();
            $this->stream->skipWhitespace();

            $afterColon = $this->stream->current();

            $this->stream->setPosition($savedPos);

            if ($afterColon->type === TokenType::LBRACE) {
                return $this->parseRule();
            }

            return $this->parseDeclarationFromProperty($selectorOrProperty, $startLine, $startColumn);
        }

        $this->consumeSemicolon();

        return null;
    }

    public function parseRule(): RuleNode
    {
        $startToken         = $this->stream->current();
        $startLine          = $this->trackSourceLocations ? $startToken->line : 1;
        $startColumn        = $this->trackSourceLocations ? $startToken->column : 1;
        $selector           = '';
        $interpolationDepth = 0;
        $pendingBreak       = false;

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if ($interpolationDepth === 0 && $token->type === TokenType::LBRACE) {
                break;
            }

            if ($token->type === TokenType::SEMICOLON) {
                break;
            }

            if (TokenStreamHelper::consumeInterpolationFragment(
                $this->stream,
                $selector,
                $interpolationDepth,
                $token,
            )) {
                continue;
            }

            if (in_array($token->type, [
                TokenType::COMMENT_SILENT,
                TokenType::COMMENT_LOUD,
                TokenType::COMMENT_PRESERVED,
            ], true)) {
                $this->stream->advance();
                $this->stream->skipWhitespace();

                if ($selector !== '' && $selector[-1] !== ' ') {
                    $selector .= ' ';
                }

                continue;
            }

            if ($token->type === TokenType::WHITESPACE) {
                if (str_contains($token->value, "\n") && $selector !== '' && $selector[-1] === ',') {
                    $selector .= "\n";
                } elseif (str_contains($token->value, "\n") && $selector !== '') {
                    $pendingBreak = true;
                    $selector    .= ' ';
                } else {
                    $selector .= ' ';
                }
            } elseif ($token->type === TokenType::STRING) {
                $selector .= '"' . ($token->rawValue ?? $token->value) . '"';
            } elseif ($token->type === TokenType::HASH) {
                $selector .= '#' . $token->value;
            } else {
                if ($token->value === ',' && $pendingBreak) {
                    $selector     = rtrim($selector) . "\n,";
                    $pendingBreak = false;
                } else {
                    $selector .= $token->value;
                }

                if ($token->value !== ',') {
                    $pendingBreak = false;
                }
            }

            $this->stream->advance();
        }

        $selector = trim($selector);

        return $this->context->parseRuleFromSelector($selector, $startLine, $startColumn);
    }

    public function parseDeclarationFromProperty(string $property, int $line = 1, int $column = 1): DeclarationNode
    {
        $this->stream->advance();

        if (str_starts_with(trim($property), '--')) {
            $value = new StringNode($this->valueContext->parseCustomPropertyValue());
        } else {
            $value = $this->valueContext->parseValue();
        }

        $modifiers = $this->valueContext->parseValueModifiers();

        $this->consumeSemicolon();

        return new DeclarationNode($property, $value, $line, $column, $modifiers['important']);
    }

    public function parseSelectorOrProperty(): string
    {
        $buffer             = '';
        $depth              = 0;
        $bracketDepth       = 0;
        $interpolationDepth = 0;
        $pendingBreak       = false;

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if (TokenStreamHelper::consumeInterpolationFragment($this->stream, $buffer, $interpolationDepth, $token)) {
                continue;
            }

            if ($token->type === TokenType::WHITESPACE) {
                if (str_contains($token->value, "\n") && $buffer !== '' && $buffer[-1] === ',') {
                    $buffer .= "\n";
                } elseif (str_contains($token->value, "\n") && $buffer !== '') {
                    $pendingBreak = true;
                    $buffer      .= ' ';
                } else {
                    $buffer .= ' ';
                }

                $this->stream->advance();

                continue;
            }

            if ($token->type === TokenType::STRING) {
                $buffer .= '"' . ($token->rawValue ?? $token->value) . '"';

                $this->stream->advance();

                continue;
            }

            if ($token->type === TokenType::HASH) {
                $buffer .= '#' . $token->value;

                $this->stream->advance();

                continue;
            }

            if ($depth === 0 && $bracketDepth === 0 && $token->type === TokenType::COLON) {
                $nextToken = $this->stream->peek();

                if ($nextToken->type === TokenType::COLON || $nextToken->type === TokenType::DOUBLE_COLON) {
                    $buffer .= ':';

                    $this->stream->advance();

                    continue;
                }

                if ($this->context->isInsideBraces()) {
                    if ($buffer === '' && $nextToken->type === TokenType::IDENTIFIER) {
                        $buffer .= ':';

                        $this->stream->advance();

                        continue;
                    }

                    break;
                }

                $savedPos = $this->stream->getPosition();

                $this->stream->advance();
                $this->stream->skipWhitespace();

                $afterColon = $this->stream->current();

                $this->stream->setPosition($savedPos);

                if (! in_array($afterColon->type, [
                    TokenType::LBRACE,
                    TokenType::SEMICOLON,
                    TokenType::EOF,
                ], true)) {
                    $buffer .= ':';

                    $this->stream->advance();

                    continue;
                }

                break;
            }

            if (
                $interpolationDepth === 0
                && $depth === 0
                && $bracketDepth === 0
                && $token->type === TokenType::LBRACE
            ) {
                break;
            }

            if (in_array($token->type, [
                TokenType::COMMENT_LOUD,
                TokenType::COMMENT_PRESERVED,
            ], true)) {
                $this->stream->advance();
                $this->stream->skipWhitespace();

                if ($buffer !== '' && $buffer[-1] !== ' ') {
                    $buffer .= ' ';
                }

                continue;
            }

            if ($token->type === TokenType::COMMENT_SILENT) {
                break;
            }

            if ($token->type === TokenType::LPAREN) {
                $depth++;
            }

            if ($token->type === TokenType::RPAREN) {
                $depth--;
            }

            if ($token->type === TokenType::LBRACKET) {
                $bracketDepth++;
            }

            if ($token->type === TokenType::RBRACKET) {
                $bracketDepth--;
            }

            if ($depth === 0 && $token->type === TokenType::SEMICOLON) {
                break;
            }

            if ($token->value === ',' && $pendingBreak) {
                $buffer        = rtrim($buffer) . "\n,";
                $pendingBreak  = false;
            } else {
                $buffer .= $token->value;
            }

            if ($token->value !== ',') {
                $pendingBreak = false;
            }

            $this->stream->advance();
        }

        return trim($buffer);
    }

    public function isLikelySelector(string $text): bool
    {
        if (strpbrk($text, '.#&:>+~') !== false) {
            return true;
        }

        return strlen($text) <= 10;
    }

    public function hasRuleBlockAfterColon(): bool
    {
        $savedPosition = $this->stream->getPosition();

        $depth = 0;

        while (! $this->stream->isEof()) {
            $this->stream->advance();

            $token = $this->stream->current();

            if ($token->type === TokenType::LPAREN) {
                $depth++;

                continue;
            }

            if ($token->type === TokenType::RPAREN) {
                $depth = max(0, $depth - 1);

                continue;
            }

            if ($token->type === TokenType::RBRACE && $depth > 0) {
                $depth--;

                continue;
            }

            if ($this->stream->is(TokenType::HASH) && $this->stream->peek()->type === TokenType::LBRACE) {
                $depth++;

                $this->stream->advance(2);

                continue;
            }

            if ($depth === 0 && $token->type === TokenType::LBRACE) {
                $this->stream->setPosition($savedPosition);

                return true;
            }

            if ($depth === 0 && in_array($token->type, [
                TokenType::SEMICOLON,
                TokenType::RBRACE,
                TokenType::EOF,
            ], true)) {
                $this->stream->setPosition($savedPosition);

                return false;
            }
        }

        $this->stream->setPosition($savedPosition);

        return false;
    }

    private function isVariableNameContinuedByInterpolation(): bool
    {
        $saved = $this->stream->getPosition();

        $this->stream->advance();

        while ($this->stream->is(TokenType::WHITESPACE)) {
            $this->stream->advance();
        }

        $isInterpolated = $this->stream->is(TokenType::HASH) && $this->stream->peek()->type === TokenType::LBRACE;

        $this->stream->setPosition($saved);

        return $isInterpolated;
    }

    private function parseCssVariableDeclaration(string $tokenValue, int $line = 1, int $column = 1): DeclarationNode
    {
        $colonPosition = strpos($tokenValue, ':');

        if ($colonPosition === false) {
            $this->stream->advance();

            return new DeclarationNode($tokenValue, new StringNode(''), $line, $column, false);
        }

        $property    = trim(substr($tokenValue, 0, $colonPosition));
        $inlineValue = trim(substr($tokenValue, $colonPosition + 1));

        $this->stream->advance();

        $tailValue = $this->valueContext->parseCustomPropertyValue();
        $separator = $inlineValue !== '' && $tailValue !== ''
            && ! str_starts_with($tailValue, ' ')
            && ! str_starts_with($tailValue, "\t")
            && $tailValue[0] !== '('
            ? ' '
            : '';

        $value     = $inlineValue . $separator . $tailValue;
        $modifiers = $this->valueContext->parseValueModifiers();

        $this->consumeSemicolon();

        return new DeclarationNode($property, new StringNode($value), $line, $column, $modifiers['important']);
    }

    private function parseCssFunctionResultDeclaration(string $property, int $line = 1, int $column = 1): DeclarationNode
    {
        $this->stream->advance();

        $value     = new StringNode(trim($this->valueContext->parseCustomPropertyValue()));
        $modifiers = $this->valueContext->parseValueModifiers();

        $this->consumeSemicolon();

        return new DeclarationNode($property, $value, $line, $column, $modifiers['important']);
    }

    private function consumeIdentifier(): string
    {
        if (! $this->stream->is(TokenType::IDENTIFIER)) {
            return '';
        }

        $token = $this->stream->current();

        $this->stream->advance();

        return $token->value;
    }

    private function consumeSemicolon(): void
    {
        $this->stream->skipWhitespace();
        $this->stream->consume(TokenType::SEMICOLON);
    }
}
