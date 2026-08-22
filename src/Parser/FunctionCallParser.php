<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;

use function implode;
use function in_array;
use function str_contains;
use function strlen;
use function strpbrk;
use function strpos;
use function strtolower;
use function substr;
use function trim;

final readonly class FunctionCallParser
{
    public function __construct(
        private TokenStream $stream,
        private InlineValueParserInterface $inlineValueParser,
        private FunctionCallParsingContextInterface $parsingContext,
    ) {}

    public function parseIdentifierOrFunction(): AstNode
    {
        $startToken = $this->stream->current();
        $identifier = TokenStreamHelper::parseQualifiedIdentifier($this->stream);

        if ($this->stream->is(TokenType::LPAREN)) {
            if ($identifier === 'url') {
                return $this->parseUrlFunctionFromName();
            }

            if ($identifier === 'var') {
                return $this->parseVarFunction();
            }

            return $this->parseFunctionFromName($identifier);
        }

        if (
            in_array(strtolower($identifier), ['and', 'or'], true)
            && $this->stream->is(TokenType::WHITESPACE)
            && $this->stream->peek()->type === TokenType::LPAREN
        ) {
            return new StringNode($identifier, false, $startToken->line, $startToken->column);
        }

        $moduleVariableSeparator = strpos($identifier, '.$');

        if ($moduleVariableSeparator !== false) {
            $moduleName   = substr($identifier, 0, $moduleVariableSeparator);
            $variableName = substr($identifier, $moduleVariableSeparator + 2);

            if ($moduleName !== '' && $variableName !== '') {
                return new VariableReferenceNode($moduleName . '.' . $variableName);
            }
        }

        $normalizedIdentifier = strtolower($identifier);

        if ($normalizedIdentifier === 'true') {
            return new BooleanNode(true);
        }

        if ($normalizedIdentifier === 'false') {
            return new BooleanNode(false);
        }

        if ($normalizedIdentifier === 'null') {
            return new NullNode();
        }

        return new StringNode($identifier, false, $startToken->line, $startToken->column);
    }

    public function parseVarFunction(): FunctionNode
    {
        $this->stream->advance();

        $arguments = [];

        $this->stream->skipWhitespace();

        $name = $this->parseSingleValueNode();

        if ($name !== null) {
            $arguments[] = $name;
        }

        $this->stream->skipWhitespace();

        if ($this->stream->consume(TokenType::COMMA)) {
            $fallback = $this->parsingContext->parseValueUntil([TokenType::RPAREN]);

            if ($fallback !== null) {
                $arguments[] = $fallback;
            }
        }

        $this->stream->consume(TokenType::RPAREN);

        return new FunctionNode('var', $arguments);
    }

    public function parseUrlFunctionFromName(): FunctionNode
    {
        $this->stream->advance();

        $argument = '';
        $depth    = 1;

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if ($token->type === TokenType::LPAREN) {
                $depth++;

                $argument .= '(';

                $this->stream->advance();

                continue;
            }

            if ($token->type === TokenType::RPAREN) {
                $depth--;

                if ($depth === 0) {
                    $this->stream->advance();

                    break;
                }

                $argument .= ')';

                $this->stream->advance();

                continue;
            }

            if ($token->type === TokenType::WHITESPACE) {
                $this->stream->advance();

                continue;
            }

            if ($token->type === TokenType::HASH && $this->stream->peek()->type === TokenType::LBRACE) {
                $argument .= '#{';

                $this->stream->advance(2);

                continue;
            }

            if ($token->type === TokenType::HASH) {
                $argument .= '#' . $token->value;
            } elseif ($token->type === TokenType::STRING) {
                $argument .= $this->quoteStringForReparse($token->value);
            } else {
                $argument .= TokenStreamHelper::tokenToRawString($token->type, $token->value);
            }

            $this->stream->advance();
        }

        $argument = trim($argument);

        if ($argument === '') {
            return new FunctionNode('url', []);
        }

        if ($this->isValidUnquotedUrl($argument)) {
            return new FunctionNode('url', [new StringNode($argument)]);
        }

        return new FunctionNode('url', [$this->inlineValueParser->parseInlineValue($argument)]);
    }

    public function parseFunctionFromName(string $name): FunctionNode
    {
        $line = $this->stream->current()->line;

        if (strtolower($name) === 'css' && $this->stream->is(TokenType::LPAREN) && $this->stream->getSource() !== '') {
            return new FunctionNode($name, [new StringNode($this->captureRawCssArgument())], $line);
        }

        $this->stream->advance();

        if ($name === 'if') {
            $inlineIfArguments = $this->tryParseInlineIfExpressionArguments();

            if ($inlineIfArguments !== null) {
                return new FunctionNode($name, $inlineIfArguments, $line, modernSyntax: true);
            }
        }

        $arguments = [];
        $loopCount = 0;

        while (! $this->stream->isEof()) {
            $loopCount++;

            if ($loopCount > 100) {
                break;
            }

            $this->stream->skipWhitespace();

            if ($this->stream->consume(TokenType::RPAREN)) {
                break;
            }

            if ($this->stream->is(TokenType::COMMA)) {
                $this->stream->advance();
                $this->stream->skipWhitespace();

                if ($this->stream->consume(TokenType::RPAREN)) {
                    break;
                }
            }

            $this->stream->skipWhitespace();

            $savedPos     = $this->stream->getPosition();
            $potentialArg = $this->parseSingleValueNode();

            if ($potentialArg !== null) {
                $this->stream->skipWhitespace();

                // Legacy = operator for IE compatibility (creates unquoted string)
                if ($this->stream->is(TokenType::ASSIGN)) {
                    $this->stream->advance();
                    $this->stream->skipWhitespace();

                    $rightSide = $this->parseSingleValueNode();

                    if ($rightSide !== null) {
                        // Convert both sides to strings and combine with =
                        $leftStr  = $this->nodeToString($potentialArg);
                        $rightStr = $this->nodeToString($rightSide);

                        $arguments[] = new StringNode($leftStr . '=' . $rightStr, false);

                        continue;
                    }
                }

                if ($this->stream->is(TokenType::COLON)) {
                    $this->stream->setPosition($savedPos);

                    if ($this->stream->is(TokenType::DOLLAR)) {
                        $varRef = $this->parsingContext->parseVariableReference();

                        $this->stream->skipWhitespace();

                        if ($this->stream->consume(TokenType::COLON)) {
                            $this->stream->skipWhitespace();

                            $value = $this->parsingContext->parseCommaSeparatedValue();

                            if ($value !== null) {
                                $arguments[] = new NamedArgumentNode($varRef->name, $value);
                            }
                        }
                    } else {
                        $arg = $this->parseFunctionArgument();

                        if ($arg !== null) {
                            $arguments[] = $arg;
                        }
                    }
                } else {
                    $this->stream->setPosition($savedPos);

                    $arg = $this->parseFunctionArgument();

                    if ($arg !== null) {
                        $arguments[] = $arg;
                    }
                }
            } else {
                $this->stream->setPosition($savedPos);

                $arg = $this->parseFunctionArgument();

                if ($arg !== null) {
                    $arguments[] = $arg;

                    continue;
                }

                break;
            }
        }

        return new FunctionNode($name, $arguments, $line);
    }

    private function parseSingleValueNode(): ?AstNode
    {
        return $this->parsingContext->parseSingleValue();
    }

    private function captureRawCssArgument(): string
    {
        $open = $this->stream->consume(TokenType::LPAREN);

        if ($open === null) {
            return '';
        }

        $depth      = 1;
        $closeStart = null;
        $source     = $this->stream->getSource();

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if ($token->type === TokenType::LPAREN) {
                $depth++;

                $this->stream->advance();

                continue;
            }

            if ($token->type === TokenType::RPAREN) {
                $depth--;

                if ($depth === 0) {
                    $closeStart = $token->start;

                    $this->stream->advance();

                    break;
                }

                $this->stream->advance();

                continue;
            }

            $this->stream->advance();
        }

        if ($closeStart === null) {
            return '';
        }

        $start = $open->start + 1;

        return trim(substr($source, $start, $closeStart - $start));
    }

    private function parseFunctionArgument(): ?AstNode
    {
        $argument = $this->parsingContext->parseCommaSeparatedValue();

        if ($argument !== null && TokenStreamHelper::consumeEllipsis($this->stream)) {
            return new SpreadArgumentNode($argument);
        }

        return $argument;
    }

    private function quoteStringForReparse(string $value): string
    {
        $quote = str_contains($value, '"') && ! str_contains($value, "'")
            ? "'"
            : '"';

        $escaped = '';
        $length  = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $char = $value[$index];

            if ($char === '\\' || $char === $quote) {
                $escaped .= '\\';
            }

            $escaped .= $char;
        }

        return $quote . $escaped . $quote;
    }

    /**
     * @return array<int, AstNode>|null
     */
    private function tryParseInlineIfExpressionArguments(): ?array
    {
        $savedPosition = $this->stream->getPosition();

        $clauses = [];
        $else    = null;

        while (true) {
            $this->stream->skipWhitespace();

            if ($this->stream->consume(TokenType::SEMICOLON)) {
                $this->stream->skipWhitespace();
            }

            if (
                $this->stream->is(TokenType::IDENTIFIER)
                && strtolower($this->stream->current()->value) === 'else'
            ) {
                $this->stream->advance();
                $this->stream->skipWhitespace();
                $this->stream->consume(TokenType::COLON);
                $this->stream->skipWhitespace();

                $else ??= $this->parsingContext->parseValueUntil([TokenType::RPAREN, TokenType::SEMICOLON]);

                continue;
            }

            $condition = $this->parsingContext->parseValueUntil([TokenType::COLON, TokenType::COMMA, TokenType::RPAREN]);

            if ($condition === null || ! $this->stream->is(TokenType::COLON)) {
                break;
            }

            $this->stream->advance();
            $this->stream->skipWhitespace();

            $value = $this->parsingContext->parseValueUntil([TokenType::SEMICOLON, TokenType::RPAREN, TokenType::COMMA]);

            $clauses[] = [$condition, $value];

            $this->stream->skipWhitespace();

            if (! $this->stream->consume(TokenType::SEMICOLON)) {
                break;
            }

            $this->stream->skipWhitespace();

            if (
                $this->stream->is(TokenType::IDENTIFIER)
                && strtolower($this->stream->current()->value) === 'else'
            ) {
                $this->stream->advance();
                $this->stream->skipWhitespace();
                $this->stream->consume(TokenType::COLON);
                $this->stream->skipWhitespace();

                $else ??= $this->parsingContext->parseValueUntil([TokenType::RPAREN, TokenType::SEMICOLON]);

                continue;
            }
        }

        if ($clauses === [] && $else === null) {
            $this->stream->setPosition($savedPosition);

            return null;
        }

        $arguments = [];

        foreach ($clauses as [$condition, $value]) {
            if ($value === null) {
                continue;
            }

            $arguments[] = $condition;
            $arguments[] = $value;
        }

        if ($else !== null) {
            $arguments[] = new StringNode('__else__');
            $arguments[] = $else;
        }

        $this->stream->skipWhitespace();

        if ($this->stream->consume(TokenType::SEMICOLON)) {
            $this->stream->skipWhitespace();
        }

        $this->stream->consume(TokenType::RPAREN);

        return $arguments;
    }

    private function isValidUnquotedUrl(string $argument): bool
    {
        if (str_contains($argument, '$') || str_contains($argument, '+')) {
            return false;
        }

        $withoutInterpolation = '';

        $length = strlen($argument);
        $index  = 0;

        while ($index < $length) {
            if ($argument[$index] === '#' && $index + 1 < $length && $argument[$index + 1] === '{') {
                $closingBrace = strpos($argument, '}', $index + 2);

                if ($closingBrace !== false) {
                    $index = $closingBrace + 1;

                    continue;
                }
            }

            $withoutInterpolation .= $argument[$index];

            $index++;
        }

        if ($withoutInterpolation === '') {
            return false;
        }

        return strpbrk($withoutInterpolation, " \t\n\r\0\x0B\"'()") === false;
    }

    private function nodeToString(AstNode $node): string
    {
        if ($node instanceof StringNode) {
            return $node->value;
        }

        if ($node instanceof NumberNode) {
            return (string) $node;
        }

        if ($node instanceof ColorNode) {
            return $node->value;
        }

        if ($node instanceof VariableReferenceNode) {
            return '$' . $node->name;
        }

        if ($node instanceof ListNode) {
            $parts = [];
            foreach ($node->items as $item) {
                $parts[] = $this->nodeToString($item);
            }

            return implode($node->separator === ',' ? ', ' : ' ', $parts);
        }

        return '';
    }
}
