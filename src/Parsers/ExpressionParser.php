<?php

declare(strict_types=1);

namespace DartSass\Parsers;

use DartSass\Exceptions\SyntaxException;
use DartSass\Parsers\Nodes\AstNode;
use DartSass\Parsers\Nodes\ColorNode;
use DartSass\Parsers\Nodes\CssCustomPropertyNode;
use DartSass\Parsers\Nodes\CssPropertyNode;
use DartSass\Parsers\Nodes\FunctionNode;
use DartSass\Parsers\Nodes\HexColorNode;
use DartSass\Parsers\Nodes\IdentifierNode;
use DartSass\Parsers\Nodes\InterpolationNode;
use DartSass\Parsers\Nodes\ListNode;
use DartSass\Parsers\Nodes\MapNode;
use DartSass\Parsers\Nodes\NodeType;
use DartSass\Parsers\Nodes\NumberNode;
use DartSass\Parsers\Nodes\OperationNode;
use DartSass\Parsers\Nodes\OperatorNode;
use DartSass\Parsers\Nodes\PropertyAccessNode;
use DartSass\Parsers\Nodes\StringNode;
use DartSass\Parsers\Nodes\UnaryNode;
use DartSass\Parsers\Nodes\VariableNode;
use DartSass\Parsers\Tokens\Token;

use function array_map;
use function count;
use function explode;
use function in_array;
use function is_string;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_ends_with;
use function trim;

class ExpressionParser extends AbstractParser
{
    protected const UNARY_OPERATORS = [
        '-'   => true,
        '+'   => true,
        'not' => true,
    ];

    protected const COLOR_FUNCTIONS = [
        'lch'   => true,
        'oklch' => true,
        'hsl'   => true,
        'hwb'   => true,
        'lab'   => true,
    ];

    protected const OPERATOR_TYPES = [
        'operator'         => true,
        'logical_operator' => true,
        'colon'            => true,
        'asterisk'         => true,
    ];

    protected const BLOCK_END_TYPES = [
        'semicolon'   => true,
        'brace_close' => true,
        'brace_open'  => true,
    ];

    /**
     * @throws SyntaxException
     */
    public function parse(): AstNode
    {
        $left = $this->parseBinaryExpression(0);

        $this->skipWhitespace();

        if ($left->type === NodeType::PROPERTY_ACCESS && $this->peek('semicolon')) {
            return $left;
        }

        if ($this->matchesAny('brace_open', 'brace_close')) {
            return $left;
        }

        $token = $this->currentToken();
        if ($token === null) {
            return $left;
        }

        if ($token->type === 'identifier' && $token->value === 'null') {
            return $left;
        }

        if ($token->type === 'operator' && $token->value === ',') {
            $values = [$left];

            while ($this->currentToken()->type === 'operator' && $this->currentToken()->value === ',') {
                $this->advanceToken();
                $this->skipWhitespace();

                $values[] = $this->parseBinaryExpression(0);
                $this->skipWhitespace();

                if ($this->peek('brace_open')) {
                    break;
                }
            }

            return new ListNode($values, line: $left->line ?? 0);
        }

        if ($token && $token->type === 'operator' && $token->value === '!') {
            return $left;
        }

        if (
            $token
            && ! isset(self::BLOCK_END_TYPES[$token->type])
            && ! ($token->type === 'operator' && $token->value === ',')
        ) {
            $currentPos = $this->getTokenIndex();

            $this->skipWhitespace();

            if (
                $this->currentToken()
                && ! isset(self::BLOCK_END_TYPES[$this->currentToken()->type])
                && ! ($this->currentToken()->type === 'operator' && $this->currentToken()->value === ',')
            ) {
                $nextToken = $this->currentToken();

                if (
                    $nextToken && $nextToken->type === 'identifier'
                    && in_array($nextToken->value, ['to', 'through', 'from'], true)
                ) {
                    $this->setTokenIndex($currentPos);

                    return $left;
                }

                if ($left->type === NodeType::VARIABLE) {
                    if ($nextToken && $nextToken->type === 'colon') {
                        $this->setTokenIndex($currentPos);

                        return $left;
                    }
                }

                $values = [$left];

                while (
                    $this->currentToken()
                    && ! isset(self::BLOCK_END_TYPES[$this->currentToken()->type])
                    && $this->currentToken()->type !== 'paren_close'
                    && ! ($this->currentToken()->type === 'operator' && $this->currentToken()->value === ',')
                ) {
                    $this->skipWhitespace();

                    $values[] = $this->parseBinaryExpression(0);

                    $this->skipWhitespace();
                }

                if (count($values) > 1) {
                    return new ListNode($values, 'space', line: $left->line ?? 0);
                }
            }
        }

        return $left;
    }

    /**
     * @throws SyntaxException
     */
    public function parseBinaryExpression(int $minPrecedence): AstNode
    {
        $this->skipWhitespace();

        $token = $this->currentToken();

        if ($token && (isset(self::UNARY_OPERATORS[$token->value]) || $token->type === 'unary_operator')) {
            $this->advanceToken();

            $operand = $this->parseBinaryExpression(5);

            return new UnaryNode($token->value, $operand, $token->line);
        }

        $left = $this->parsePrimaryExpression();

        $this->skipWhitespace();

        while (true) {
            $token = $this->currentToken();
            if (! $token || ! isset(self::OPERATOR_TYPES[$token->type]) || isset(self::BLOCK_END_TYPES[$token->type])) {
                break;
            }

            $operator = $token->value;

            $tokensToSkip = 1;

            $nextValue = $this->peekValue();
            if ($operator === '=' && $nextValue === '=') {
                $operator = '==';
                $tokensToSkip = 2;
            } elseif ($operator === '!' && $nextValue === '=') {
                $operator = '!=';
                $tokensToSkip = 2;
            } elseif ($operator === '<' && $nextValue === '=') {
                $operator = '<=';
                $tokensToSkip = 2;
            } elseif ($operator === '>' && $nextValue === '=') {
                $operator = '>=';
                $tokensToSkip = 2;
            }

            if ($operator === '.') {
                $this->advanceToken();

                $right = $this->parsePrimaryExpression();

                $this->skipWhitespace();

                $left = new PropertyAccessNode($left, $right, $token->line);

                $nextToken = $this->currentToken();
                if ($nextToken && isset(self::BLOCK_END_TYPES[$nextToken->type])) {
                    return $left;
                }

                continue;
            }

            $precedence = $this->getOperatorPrecedence($operator);

            if ($precedence <= $minPrecedence) {
                break;
            }

            for ($i = 0; $i < $tokensToSkip; $i++) {
                $this->advanceToken();
            }

            $this->skipWhitespace();

            $right = $this->parseBinaryExpression($precedence + 1);
            $left  = new OperationNode($left, $operator, $right, $token->line);
        }

        return $left;
    }

    /**
     * @throws SyntaxException
     */
    public function parsePrimaryExpression(): AstNode
    {
        $token = $this->currentToken();

        if ($token === null) {
            throw new SyntaxException('Unexpected end of input', 0, 0);
        }

        $this->advanceToken();

        return match ($token->type) {
            'operator',
            'asterisk',
            'colon',
            'semicolon'           => new OperatorNode($token->value, $token->line),
            'hex_color'           => new HexColorNode($token->value, $token->line),
            'string'              => new StringNode(
                trim($token->value, '"\''),
                $token->line,
                $this->isQuotedStringToken($token->value)
            ),
            'variable'            => new VariableNode($token->value, $token->line),
            'css_custom_property' => new CssCustomPropertyNode($token->value, $token->line),
            'important_modifier'  => new IdentifierNode('!important', $token->line),
            'number'              => $this->parseNumber($token),
            'identifier'          => $this->parseIdentifierOrCssProperty($token),
            'function'            => $this->parseFunctionCall($token),
            'url_function'        => $this->parseUrlFunction($token),
            'paren_open'          => $this->parseParenthesizedExpression($token),
            'interpolation_open'  => $this->parseInterpolation($token),
            'attribute_selector'  => $this->parseAttributeSelector($token),
            'spread_operator'     => throw new SyntaxException(
                'Spread operator (...) can only be used in function calls',
                $token->line,
                $token->column
            ),
        };
    }

    /**
     * @throws SyntaxException
     */
    public function parseArgumentList(bool $includeSpreads = false): array
    {
        $args = [];

        while (! $this->peek('paren_close')) {
            $this->skipWhitespace();

            $namedArg = $this->tryParseNamedArgument();

            if ($namedArg !== null) {
                [$argName, $argValue] = $namedArg;

                $args[$argName] = $argValue;

                $this->consumeCommaIfPresent();

                continue;
            }

            $arg = $this->parseBinaryExpression(0);

            if ($includeSpreads) {
                $arg = $this->maybeExpandListArgument($arg);
            }

            $args[] = $this->maybeWrapWithSpread($arg);

            $this->consumeCommaIfPresent();
        }

        return $args;
    }

    private function parseNumber(Token $token): NumberNode
    {
        $valueStr = $token->value;

        if (preg_match('/^(-?\d*\.?\d+)(.*)$/', $valueStr, $matches)) {
            $value = (float) $matches[1];
            $unit  = trim($matches[2]) ?: null;
        }

        return new NumberNode($value ?? 0.0, $unit ?? null, $token->line);
    }

    /**
     * @throws SyntaxException
     */
    private function parseFunctionCall(Token $token): FunctionNode|ColorNode
    {
        $funcName = $token->value;

        $this->consume('paren_open');

        $args = $this->parseArgumentList(includeSpreads: true);

        $this->consume('paren_close');

        if (isset(self::COLOR_FUNCTIONS[$funcName])) {
            return $this->createColorNode($funcName, $args, $token->line);
        }

        return new FunctionNode($funcName, $args, line: $token->line);
    }

    /**
     * @throws SyntaxException
     */
    private function parseParenthesizedExpression(Token $token): AstNode
    {
        $this->skipWhitespace();

        if ($this->peek('paren_close')) {
            $this->consume('paren_close');

            return new ListNode([], 'space', line: $token->line);
        }

        $savedPosition = $this->getTokenIndex();
        $mapResult     = $this->tryParseMap();

        if ($mapResult !== null) {
            return $mapResult;
        }

        $this->setTokenIndex($savedPosition);

        $node = $this->parse();

        $this->consume('paren_close');

        return $node;
    }

    /**
     * @throws SyntaxException
     */
    private function parseInterpolation(Token $token): InterpolationNode
    {
        $expression = $this->parse();

        $this->consume('brace_close');

        return new InterpolationNode($expression, $token->line);
    }

    private function parseAttributeSelector(Token $token): ListNode
    {
        $value  = trim($token->value, '[]');
        $parts  = array_map(trim(...), explode(',', $value));
        $values = [];

        foreach ($parts as $part) {
            if (preg_match('/^(\d+(?:\.\d+)?)(px|em|rem|%)?$/', $part, $matches)) {
                $values[] = isset($matches[2]) && $matches[2]
                    ? ['value' => (float) $matches[1], 'unit' => $matches[2]]
                    : (float) $matches[1];
            } else {
                $values[] = $part;
            }
        }

        return new ListNode($values, bracketed: true, line: $token->line);
    }

    /**
     * @throws SyntaxException
     */
    private function parseCssPropertyValue(): AstNode
    {
        $propertyToken = $this->consume('identifier');

        $this->consume('colon');

        $valueExpression = $this->parseBinaryExpression(0);

        return new CssPropertyNode($propertyToken->value, $valueExpression, $propertyToken->line);
    }

    /**
     * @throws SyntaxException
     */
    private function parseIdentifierOrCssProperty(Token $token): AstNode
    {
        if ($this->peek('colon')) {
            $this->setTokenIndex($this->getTokenIndex() - 1);

            return $this->parseCssPropertyValue();
        }

        return new IdentifierNode($token->value, $token->line);
    }

    private function parseUrlFunction(Token $token): FunctionNode
    {
        $fullContent = preg_replace('/^url\((.*)\)$/s', '$1', $token->value);
        $fullContent = trim($fullContent);

        $urlNode = new StringNode($fullContent, $token->line, $this->isQuotedStringToken($fullContent));

        return new FunctionNode('url', [$urlNode], line: $token->line);
    }

    /**
     * @throws SyntaxException
     */
    private function tryParseNamedArgument(): ?array
    {
        if (! $this->peek('variable')) {
            return null;
        }

        $varToken = $this->consume('variable');
        $argName  = $varToken->value;

        $this->skipWhitespace();

        if (! $this->peek('colon')) {
            $this->setTokenIndex($this->getTokenIndex() - 1);

            return null;
        }

        $this->consume('colon');
        $this->skipWhitespace();

        $argValue = $this->parseBinaryExpression(0);

        return [$argName, $argValue];
    }

    private function consumeCommaIfPresent(): void
    {
        if ($this->peek('paren_close')) {
            return;
        }

        $this->skipWhitespace();

        $commaToken = $this->currentToken();
        if ($commaToken && $commaToken->type === 'operator' && $commaToken->value === ',') {
            $this->advanceToken();
        }
    }

    /**
     * @throws SyntaxException
     */
    private function maybeExpandListArgument(AstNode $arg): AstNode
    {
        $this->skipWhitespace();

        $next = $this->currentToken();

        if ($next
            && ! $this->peek('paren_close')
            && ! ($next->type === 'operator' && $next->value === ',')
            && ! ($next->type === 'spread_operator')
            && ! isset(self::BLOCK_END_TYPES[$next->type])
        ) {
            $values = [$arg];

            while (
                $this->currentToken()
                && ! $this->peek('paren_close')
                && ! ($this->currentToken()->type === 'operator' && $this->currentToken()->value === ',')
                && ! ($this->currentToken()->type === 'spread_operator')
                && ! isset(self::BLOCK_END_TYPES[$this->currentToken()->type])
            ) {
                $values[] = $this->parseBinaryExpression(0);

                $this->skipWhitespace();
            }

            return new ListNode($values, 'space', line: $arg->line ?? 0);
        }

        return $arg;
    }

    /**
     * @throws SyntaxException
     */
    private function maybeWrapWithSpread(AstNode $arg): array|AstNode
    {
        $this->skipWhitespace();

        if ($this->peek('spread_operator')) {
            $this->consume('spread_operator');
            $this->skipWhitespace();

            if (! $this->peek('paren_close')) {
                throw new SyntaxException(
                    'Spread operator (...) must be the last argument',
                    $this->currentToken()->line,
                    $this->currentToken()->column
                );
            }

            return ['type' => 'spread', 'value' => $arg];
        }

        return $arg;
    }

    private function createColorNode(string $funcName, array $args, int $line): ColorNode
    {
        $alpha = null;

        $components = [];
        foreach ($args as $arg) {
            if ($arg instanceof ListNode) {
                foreach ($arg->values as $value) {
                    $components[] = $this->extractColorComponent($value);
                }
            } else {
                $components[] = $this->extractColorComponent($arg);
            }
        }

        if (count($components) > 0) {
            $lastComponent = $components[count($components) - 1];
            if (is_string($lastComponent) && str_contains($lastComponent, '/')) {
                [$colorPart, $alphaPart] = explode('/', $lastComponent, 2);

                $components[count($components) - 1] = trim($colorPart);

                $alpha = (float) trim($alphaPart);
            }
        }

        return new ColorNode($funcName, $components, $alpha, $line);
    }

    private function extractColorComponent(OperationNode|AstNode $node): mixed
    {
        if ($node->type === NodeType::OPERATION) {
            return sprintf(
                '%s/%s',
                $this->extractColorComponent($node->left),
                $this->extractColorComponent($node->right)
            );
        }

        return $node->type === NodeType::NUMBER ? $node->value ?? '' : (string) $node;
    }

    private function getOperatorPrecedence(string $operator): int
    {
        return match ($operator) {
            'or'                 => 1,
            'and'                => 2,
            '+', '-'             => 3,
            '*', '/', '%'        => 4,
            '==', '!='           => 5,
            '<', '>', '<=', '>=' => 6,
            default              => 0,
        };
    }

    /**
     * @throws SyntaxException
     */
    private function tryParseMap(): ?AstNode
    {
        $pairs    = [];
        $position = $this->getTokenIndex();

        while (true) {
            $keyToken = $this->currentToken();

            if (! $keyToken || ! in_array($keyToken->type, ['identifier', 'string'], true)) {
                break;
            }

            $key = $keyToken->value;

            $this->advanceToken();

            if (! $this->peek('colon')) {
                break;
            }

            $this->consume('colon');

            $pairs[] = [$key, $this->parseMapValue()];

            if ($this->peek('operator') && $this->currentToken()?->value === ',') {
                $this->consume('operator');
                $this->skipWhitespace();

                continue;
            }

            if ($this->peek('paren_close')) {
                $this->consume('paren_close');

                return new MapNode(
                    $pairs,
                    $position > 0 ? $this->getTokens()[$position - 1]->line : 0
                );
            }
        }

        $this->setTokenIndex($position);

        return null;
    }

    /**
     * @throws SyntaxException
     */
    private function parseMapValue(): AstNode|null
    {
        $token = $this->currentToken();

        if (! $token) {
            return null;
        }

        switch ($token->type) {
            case 'number':
                $this->advanceToken();

                return $this->parseNumber($token);

            case 'string':
                $this->advanceToken();

                return new StringNode(
                    trim($token->value, "'\""),
                    $token->line,
                    $this->isQuotedStringToken($token->value)
                );

            case 'identifier':
                $this->advanceToken();

                return new IdentifierNode($token->value, $token->line);

            case 'hex_color':
                $this->advanceToken();

                return new HexColorNode($token->value, $token->line);

            default:
                return $this->parse();
        }
    }

    private function isQuotedStringToken(string $value): bool
    {
        return $value !== ''
            && (($value[0] === '"' && str_ends_with($value, '"'))
            || ($value[0] === "'" && str_ends_with($value, "'")));
    }
}
