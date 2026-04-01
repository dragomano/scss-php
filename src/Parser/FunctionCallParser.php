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
use Closure;

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
    /**
     * @param TokenStream $stream
     * @param Closure(string): AstNode $parseInlineValue
     * @param Closure(): ?AstNode $parseSingleValue
     * @param Closure(array<int, TokenType>): ?AstNode $parseValueUntil
     * @param Closure(): VariableReferenceNode $parseVariableReference
     * @param Closure(): ?AstNode $parseCommaSeparatedValue
     */
    public function __construct(
        private TokenStream $stream,
        private Closure $parseInlineValue,
        private Closure $parseSingleValue,
        private Closure $parseValueUntil,
        private Closure $parseVariableReference,
        private Closure $parseCommaSeparatedValue
    ) {}

    public function parseIdentifierOrFunction(): AstNode
    {
        $identifier = StreamUtils::parseQualifiedIdentifier($this->stream);

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
            $this->stream->skipWhitespace();

            return $this->parseFunctionFromName($identifier);
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

        return new StringNode($identifier);
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
            $fallback = ($this->parseValueUntil)([TokenType::RPAREN]);

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
                $argument .= StreamUtils::tokenToRawString($token->type, $token->value);
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

        return new FunctionNode('url', [($this->parseInlineValue)($argument)]);
    }

    public function parseFunctionFromName(string $name): FunctionNode
    {
        $line = $this->stream->current()->line;

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
                        $varRef = ($this->parseVariableReference)();

                        $this->stream->skipWhitespace();

                        if ($this->stream->consume(TokenType::COLON)) {
                            $this->stream->skipWhitespace();

                            $value = ($this->parseCommaSeparatedValue)();

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
        return ($this->parseSingleValue)();
    }

    private function parseFunctionArgument(): ?AstNode
    {
        $argument = ($this->parseCommaSeparatedValue)();

        if ($argument !== null && StreamUtils::consumeEllipsis($this->stream)) {
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

        $arguments = [];
        $condition = ($this->parseValueUntil)([TokenType::COLON, TokenType::COMMA, TokenType::RPAREN]);

        if ($condition === null) {
            $this->stream->setPosition($savedPosition);

            return null;
        }

        if (! $this->stream->is(TokenType::COLON)) {
            $this->stream->setPosition($savedPosition);

            return null;
        }

        $arguments[] = $condition;

        $this->stream->advance();
        $this->stream->skipWhitespace();

        $truthy = ($this->parseValueUntil)([TokenType::SEMICOLON, TokenType::RPAREN, TokenType::COMMA]);

        if ($truthy !== null) {
            $arguments[] = $truthy;
        }

        $this->stream->skipWhitespace();

        if ($this->stream->consume(TokenType::SEMICOLON)) {
            $this->stream->skipWhitespace();

            if (
                $this->stream->is(TokenType::IDENTIFIER)
                && strtolower($this->stream->current()->value) === 'else'
            ) {
                $this->stream->advance();
                $this->stream->skipWhitespace();
                $this->stream->consume(TokenType::COLON);
                $this->stream->skipWhitespace();
            }

            $falsy = ($this->parseValueUntil)([TokenType::RPAREN]);

            if ($falsy !== null) {
                $arguments[] = $falsy;
            }
        }

        $this->stream->skipWhitespace();
        $this->stream->consume(TokenType::RPAREN);

        return $arguments;
    }

    private function isValidUnquotedUrl(string $argument): bool
    {
        if ($argument === '') {
            return false;
        }

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
            return "$node->value$node->unit";
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
