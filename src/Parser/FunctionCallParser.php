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
use Bugo\SCSS\Utils\CssNamedColors;
use Bugo\SCSS\Utils\StringEscapeDecoder;

use function array_key_last;
use function implode;
use function in_array;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strlen;
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

        if ($this->isSpecialProgidName($identifier)
            && $this->stream->is(TokenType::COLON)
            && $this->hasSpecialProgidFunctionAhead()
        ) {
            return $this->parseSpecialProgidFunction($identifier);
        }

        if ($this->stream->is(TokenType::LPAREN)) {
            if ($this->isSpecialUrlName($identifier)) {
                return $this->parseUrlFunctionFromName($identifier);
            }

            if (strtolower($identifier) === 'var') {
                return $this->parseVarFunction($identifier);
            }

            if ($this->isSpecialGeneralName($identifier)) {
                return $this->parseSpecialGeneralFunction($identifier);
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

        if (isset(CssNamedColors::NAMED_HEX[$normalizedIdentifier])) {
            return new ColorNode($identifier, $startToken->line, $startToken->column);
        }

        return new StringNode($identifier, false, $startToken->line, $startToken->column);
    }

    public function parseVarFunction(string $name = 'var'): FunctionNode
    {
        $this->stream->advance();

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
                    $last = null;

                    if ($arguments !== []) {
                        $last = $arguments[array_key_last($arguments)];
                    }

                    if (! $last instanceof SpreadArgumentNode && ! $last instanceof NamedArgumentNode) {
                        $arguments[] = new StringNode('');
                    }

                    break;
                }
            }

            $this->stream->skipWhitespace();

            $savedPos     = $this->stream->getPosition();
            $potentialArg = $this->parseSingleValueNode();

            if ($potentialArg !== null) {
                $this->stream->skipWhitespace();

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

                                continue;
                            }
                        }
                    }
                }

                $this->stream->setPosition($savedPos);

                $arg = $this->parseFunctionArgument();

                if ($arg !== null) {
                    $arguments[] = $arg;

                    continue;
                }

                break;
            }

            $this->stream->setPosition($savedPos);

            $arg = $this->parseFunctionArgument();

            if ($arg !== null) {
                $arguments[] = $arg;

                continue;
            }

            break;
        }

        return new FunctionNode($name, $arguments);
    }

    public function parseUrlFunctionFromName(?string $identifier = null): FunctionNode
    {
        $argument = $this->parseSpecialFunctionArgument(true, true);

        if ($argument === '') {
            return new FunctionNode('url', []);
        }

        if ($this->isPlainCssUrlArgument($argument)) {
            $quote = $argument[0];

            if ($quote !== '"' && $quote !== "'") {
                $argument = str_replace('\\#{', '\\' . StringEscapeDecoder::PROTECTED_HASH . '{', $argument);
            }

            if (
                $quote !== '"' && $quote !== "'"
                && str_contains($argument, '\\')
                && ! str_contains($argument, '#')
            ) {
                $argument = StringEscapeDecoder::decodeUnquotedUrlEscapes($argument);
            }

            return new FunctionNode('url', [new StringNode($argument)]);
        }

        return new FunctionNode($identifier ?? 'url', [$this->inlineValueParser->parseInlineValue($argument)]);
    }

    public function parseFunctionFromName(string $name): FunctionNode
    {
        $line = $this->stream->current()->line;

        if (strtolower($name) === 'css' && $this->stream->is(TokenType::LPAREN) && $this->stream->getSource() !== '') {
            $raw = $this->captureRawCssArgument();

            return new FunctionNode($name, [new StringNode($raw)], $line);
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

    private function parseSpecialGeneralFunction(string $identifier): FunctionNode
    {
        $argument = $this->parseSpecialFunctionArgument();

        return new FunctionNode(strtolower($identifier), [new StringNode($argument)]);
    }

    private function parseSpecialProgidFunction(string $identifier): FunctionNode
    {
        $name     = strtolower($identifier) . ':' . $this->collectProgidSuffixName();
        $argument = $this->parseSpecialFunctionArgument();

        return new FunctionNode($name, [new StringNode($argument)]);
    }

    private function collectProgidSuffixName(): string
    {
        $suffix = '';

        $this->stream->consume(TokenType::COLON);

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if (! in_array($token->type, [TokenType::IDENTIFIER, TokenType::DOT], true)) {
                break;
            }

            $suffix .= TokenStreamHelper::tokenToRawString($token->type, $token->value);

            $this->stream->advance();
        }

        return $suffix;
    }

    private function parseSpecialFunctionArgument(bool $trimWhitespace = false, bool $preserveSilentComments = false): string
    {
        $source             = $this->stream->getSource();
        $open               = $this->stream->current();
        $depth              = 0;
        $interpolationDepth = 0;
        $closeStart         = null;
        $tokenValues        = [];

        $this->stream->advance();

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if ($token->type === TokenType::HASH && $this->stream->peek()->type === TokenType::LBRACE) {
                $interpolationDepth++;

                $tokenValues[] = str_starts_with($token->value, '#') ? $token->value : '#';
                $tokenValues[] = $this->stream->peek()->value;

                $this->stream->advance(2);

                continue;
            }

            if ($interpolationDepth > 0 && $token->type === TokenType::RBRACE) {
                $interpolationDepth--;

                $tokenValues[] = $token->value;

                $this->stream->advance();

                continue;
            }

            if ($interpolationDepth === 0 && $token->type === TokenType::LPAREN) {
                $tokenValues[] = $token->value;

                $depth++;

                $this->stream->advance();

                continue;
            }

            if ($interpolationDepth === 0 && $token->type === TokenType::RPAREN) {
                if ($depth === 0) {
                    $closeStart = $token->start;

                    $this->stream->advance();

                    break;
                }

                $depth--;

                $tokenValues[] = $token->value;

                $this->stream->advance();

                continue;
            }

            $raw = match (true) {
                $token->type === TokenType::HASH && ! str_starts_with($token->value, '#') => '#' . $token->value,
                $token->type === TokenType::STRING => '"' . str_replace('\\', '\\\\', $token->value) . '"',
                default => $token->value,
            };

            $tokenValues[] = $raw;

            if ($interpolationDepth > 0) {
                $this->stream->advance();

                continue;
            }

            $this->stream->advance();
        }

        if ($closeStart === null) {
            return '';
        }

        $argument = $source === ''
            ? implode('', $tokenValues)
            : substr($source, $open->start + 1, $closeStart - $open->start - 1);
        $argument = $this->collapseSpecialFunctionWhitespace($argument, $preserveSilentComments);

        if (! $trimWhitespace) {
            return $argument;
        }

        return trim($argument);
    }

    private function collapseSpecialFunctionWhitespace(string $value, bool $preserveSilentComments = false): string
    {
        $result = '';
        $length = strlen($value);
        $index  = 0;

        while ($index < $length) {
            $char = $value[$index];

            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                $result .= ' ';

                while ($index < $length
                    && ($value[$index] === ' ' || $value[$index] === "\t"
                        || $value[$index] === "\r" || $value[$index] === "\n")
                ) {
                    $index++;
                }

                continue;
            }

            if (! $preserveSilentComments && $char === '/' && $index + 1 < $length && $value[$index + 1] === '/') {
                $newline = strpos($value, "\n", $index);

                $index = $newline === false ? $length : $newline + 1;

                continue;
            }

            $result .= $char;

            $index++;
        }

        return $result;
    }

    private function isSpecialUrlName(string $identifier): bool
    {
        $lower = strtolower($identifier);

        return $lower === 'url' || str_ends_with($lower, '-url');
    }

    private function hasSpecialProgidFunctionAhead(): bool
    {
        $offset = 1;

        while (true) {
            $type = $this->stream->peek($offset)->type;

            if ($type === TokenType::IDENTIFIER || $type === TokenType::DOT) {
                $offset++;

                continue;
            }

            return $type === TokenType::LPAREN;
        }
    }

    private function isSpecialGeneralName(string $identifier): bool
    {
        $name = strtolower($identifier);

        if (str_contains($name, '-')) {
            $pos = strrpos($name, '-');

            if ($pos === false) {
                return false;
            }

            $tail = substr($name, $pos + 1);

            return in_array($tail, ['calc', 'element', 'expression'], true);
        }

        return in_array($name, ['element', 'expression', 'type'], true);
    }

    private function isSpecialProgidName(string $identifier): bool
    {
        $lower = strtolower($identifier);

        return $lower === 'progid' || str_ends_with($lower, '-progid');
    }

    private function isPlainCssUrlArgument(string $argument): bool
    {
        $first = $argument[0] ?? '';

        if ($first === '"' || $first === "'") {
            return true;
        }

        if (str_contains($argument, '#{')) {
            return true;
        }

        if (str_contains($argument, '$')) {
            return false;
        }

        return ! $this->containsArithmeticOperator($argument);
    }

    private function containsArithmeticOperator(string $text): bool
    {
        $length = strlen($text);

        for ($index = 0; $index < $length; $index++) {
            $char = $text[$index];

            if (! in_array($char, ['+', '*', '/', '%', '-'], true)) {
                continue;
            }

            $left  = $index > 0 && $text[$index - 1] !== ' ' && $text[$index - 1] !== "\t";
            $right = $index + 1 < $length && $text[$index + 1] !== ' ' && $text[$index + 1] !== "\t";

            if ($left && $right) {
                continue;
            }

            return true;
        }

        return false;
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

        if ($this->stream->is(TokenType::COMMA)) {
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
