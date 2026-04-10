<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\DeprecatedExpressionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Closure;

use function count;
use function ctype_digit;
use function in_array;
use function str_contains;
use function strlen;
use function strtolower;
use function substr;
use function trim;

final readonly class ValueParser
{
    private FunctionCallParser $functions;

    /**
     * @param Closure(string): AstNode $parseInlineValue
     */
    public function __construct(private TokenStream $stream, Closure $parseInlineValue)
    {
        $this->functions = new FunctionCallParser(
            $this->stream,
            $parseInlineValue,
            fn(): ?AstNode => $this->parseSingleValue(),
            fn(array $stopTokens): ?AstNode => $this->parseValueUntil($stopTokens),
            fn(): VariableReferenceNode => $this->parseVariableReference(),
            fn(): ?AstNode => $this->parseCommaSeparatedValue(),
        );
    }

    public function parseValue(): AstNode
    {
        return $this->parseValueOrEmptyList([
            TokenType::SEMICOLON,
            TokenType::RBRACE,
            TokenType::RPAREN,
        ]);
    }

    /**
     * @param array<int, TokenType> $stopTokens
     */
    public function parseValueUntil(array $stopTokens): ?AstNode
    {
        $this->stream->skipWhitespace();

        $groups               = [];
        $currentGroup         = [];
        $deprecatedExpression = null;

        while (! $this->stream->isEof()) {
            $hasLeadingWhitespace = $this->stream->is(TokenType::WHITESPACE);

            $this->stream->skipWhitespace();

            foreach ($stopTokens as $tokenType) {
                if ($this->stream->is($tokenType)) {
                    return $this->wrapDeprecatedExpression(
                        $currentGroup !== [] ? [...$groups, $currentGroup] : $groups,
                        $deprecatedExpression,
                    );
                }
            }

            if ($this->stream->is(TokenType::COMMA)) {
                if (! empty($currentGroup)) {
                    $groups[] = $currentGroup;
                }

                $currentGroup = [];

                $this->stream->advance();

                continue;
            }

            $moduleVar = $this->tryParseModuleVariable();

            if ($moduleVar !== null) {
                $currentGroup[] = $moduleVar;

                continue;
            }

            $value = $this->parseSingleValue();

            if ($value !== null) {
                $currentGroup[] = $value;
            } else {
                $operatorToken = $this->stream->current();
                $word          = $this->parseOperatorOrKeyword();

                if ($word !== '') {
                    if (
                        ($word === '-' || $word === '+')
                        && $hasLeadingWhitespace
                        && ! $this->stream->is(TokenType::WHITESPACE)
                        && ! empty($currentGroup)
                    ) {
                        $deprecatedExpression = [
                            'message' => 'This expression will be parsed differently in a future release. '
                                . "Write \"a $word b\" for subtraction or \"a ({$word}b)\" for a list.",
                            'line'    => $operatorToken->line,
                            'column'  => $operatorToken->column,
                        ];
                    }

                    $currentGroup[] = new StringNode($word);
                } else {
                    break;
                }
            }
        }

        if (! empty($currentGroup)) {
            $groups[] = $currentGroup;
        }

        return $this->wrapDeprecatedExpression($groups, $deprecatedExpression);
    }

    public function parseOperatorOrKeyword(): string
    {
        if ($this->stream->match(
            TokenType::EQUALS,
            TokenType::NOT_EQUALS,
            TokenType::LESS_THAN_EQUALS,
            TokenType::GREATER_THAN_EQUALS,
            TokenType::LESS_THAN,
            TokenType::GREATER_THAN,
            TokenType::PLUS,
            TokenType::MINUS,
            TokenType::STAR,
            TokenType::SLASH,
            TokenType::PERCENT,
        )) {
            $value = $this->stream->current()->value;

            $this->stream->advance();

            return $value;
        }

        if (
            $this->stream->is(TokenType::IDENTIFIER)
            && in_array($this->stream->current()->value, ['and', 'or', 'not'], true)
        ) {
            $value = $this->stream->current()->value;

            $this->stream->advance();

            return $value;
        }

        return '';
    }

    public function parseSingleValue(): ?AstNode
    {
        $this->stream->skipWhitespace();

        if ($this->stream->isEof()) {
            return null;
        }

        $interpolatedIdentifier = $this->tryParseInterpolatedIdentifierString();

        if ($interpolatedIdentifier !== null) {
            return $interpolatedIdentifier;
        }

        if ($this->stream->is(TokenType::STRING)) {
            $token = $this->stream->current();

            $this->stream->advance();

            return new StringNode($token->value, true);
        }

        if (
            $this->stream->is(TokenType::HASH)
            && $this->stream->peek()->type === TokenType::HASH
            && $this->stream->peek(2)->type === TokenType::LBRACE
        ) {
            $this->stream->advance();

            $interpolation = $this->parseHashInterpolationString();

            return new StringNode('#' . $interpolation->value);
        }

        if ($this->stream->is(TokenType::HASH)) {
            $token = $this->stream->current();

            $this->stream->advance();

            if (in_array(strlen($token->value), [3, 4, 6, 8], true)) {
                return new ColorNode('#' . $token->value);
            }

            return new StringNode('#' . $token->value);
        }

        if ($this->stream->is(TokenType::DOLLAR)) {
            return $this->parseVariableReference();
        }

        if ($this->stream->is(TokenType::CSS_VARIABLE)) {
            $token = $this->stream->current();

            $this->stream->advance();

            return new StringNode($token->value);
        }

        if ($this->stream->is(TokenType::AMPERSAND)) {
            $this->stream->advance();

            return new StringNode('&');
        }

        if ($this->stream->is(TokenType::EXCLAMATION)) {
            $savedPosition = $this->stream->getPosition();

            $this->stream->advance();
            $this->stream->skipWhitespace();

            if (
                $this->stream->is(TokenType::IDENTIFIER)
                && strtolower($this->stream->current()->value) === 'important'
            ) {
                $this->stream->advance();

                return new StringNode('!important');
            }

            $this->stream->setPosition($savedPosition);

            return null;
        }

        if ($this->stream->is(TokenType::NUMBER)) {
            return $this->parseNumber();
        }

        if ($this->stream->is(TokenType::LPAREN)) {
            return $this->parseParenthesizedValue();
        }

        if ($this->stream->is(TokenType::LBRACKET)) {
            return $this->parseBracketedListValue();
        }

        if ($this->stream->is(TokenType::IDENTIFIER)) {
            return $this->functions->parseIdentifierOrFunction();
        }

        return null;
    }

    public function parseVariableReference(): VariableReferenceNode
    {
        $this->stream->advance();

        $name = $this->consumeIdentifier();

        return new VariableReferenceNode($name);
    }

    public function parseNumber(): NumberNode
    {
        $token = $this->stream->current();

        $this->stream->advance();

        $value = $token->value;
        $unit  = null;

        $numberLength = $this->readNumericPrefixLength($value);
        $numberPart   = substr($value, 0, $numberLength);
        $unitPart     = substr($value, $numberLength);

        if ($unitPart !== '') {
            $unit = $unitPart;
        }

        if (in_array($numberPart, ['', '-', '+'], true)) {
            $number = 0;
        } elseif (str_contains($numberPart, '.') || str_contains($numberPart, 'e') || str_contains($numberPart, 'E')) {
            $number = (float) $numberPart;
        } else {
            $number = (int) $numberPart;
        }

        return new NumberNode($number, $unit);
    }

    public function parseParenthesizedValue(): AstNode
    {
        $this->stream->expect(TokenType::LPAREN);

        $items = [];
        $pairs = [];

        $hasMapPairs = false;

        while (! $this->stream->isEof()) {
            $this->stream->skipWhitespace();

            if ($this->stream->consume(TokenType::RPAREN)) {
                break;
            }

            $entry = $this->parseParenthesizedEntry();

            if ($entry === null) {
                break;
            }

            $this->stream->skipWhitespace();

            if ($this->stream->consume(TokenType::COLON)) {
                $hasMapPairs = true;

                $this->stream->skipWhitespace();

                $value = $this->parseParenthesizedMapValue();

                $pairs[] = new MapPair($entry, $value);
            } else {
                $items[] = $entry;
            }

            $this->stream->skipWhitespace();

            if ($this->stream->is(TokenType::COMMA)) {
                $this->stream->advance();

                continue;
            }

            if ($this->stream->is(TokenType::RPAREN)) {
                $this->stream->advance();

                break;
            }
        }

        if ($hasMapPairs) {
            return new MapNode($pairs);
        }

        if (count($items) === 1) {
            $singleItem = $items[0];

            if ($singleItem instanceof ListNode
                && $singleItem->separator === 'space'
                && count($singleItem->items) === 3
            ) {
                $first = $singleItem->items[0];
                $mid   = $singleItem->items[1];
                $last  = $singleItem->items[2];

                if ($first instanceof NumberNode
                    && $mid instanceof StringNode
                    && $mid->value === '/'
                    && $last instanceof NumberNode
                ) {
                    $singleItem->bracketed = true;
                }
            }

            return $singleItem;
        }

        return new ListNode($items, 'comma');
    }

    public function parseBracketedListValue(): AstNode
    {
        $this->stream->expect(TokenType::LBRACKET);

        $items = [];

        while (! $this->stream->isEof()) {
            $this->stream->skipWhitespace();

            if ($this->stream->consume(TokenType::RBRACKET)) {
                break;
            }

            $value = $this->parseValueUntil([TokenType::COMMA, TokenType::RBRACKET]);

            if ($value !== null) {
                $items[] = $value;
            }

            $this->stream->skipWhitespace();

            if ($this->stream->is(TokenType::COMMA)) {
                $this->stream->advance();
            }
        }

        return new ListNode($items, 'comma', true);
    }

    public function parseString(): string
    {
        return StreamUtils::parseStringToken($this->stream);
    }

    /**
     * @return array<int, AstNode>
     */
    public function parseArgumentList(): array
    {
        $this->stream->advance();

        $arguments = [];

        while (! $this->stream->isEof()) {
            $startPosition = $this->stream->getPosition();

            $this->stream->skipWhitespace();

            if ($this->stream->consume(TokenType::RPAREN)) {
                break;
            }

            if ($this->stream->is(TokenType::COMMA)) {
                $this->stream->advance();

                continue;
            }

            if ($this->stream->consume(TokenType::DOLLAR)) {
                $name = $this->consumeIdentifier();

                $this->stream->skipWhitespace();

                if ($this->stream->consume(TokenType::COLON)) {
                    $this->stream->skipWhitespace();

                    $value = $this->parseCommaSeparatedValueOrEmptyList();

                    $arguments[] = new NamedArgumentNode($name, $value);

                    $this->consumeArgumentSeparator();

                    continue;
                }

                $this->stream->setPosition($startPosition);
            }

            $argument = $this->parseCommaSeparatedValueOrEmptyList();

            if (StreamUtils::consumeEllipsis($this->stream)) {
                $argument = new SpreadArgumentNode($argument);
            }

            $arguments[] = $argument;

            $this->consumeArgumentSeparator();

            if ($this->stream->getPosition() === $startPosition) {
                break;
            }
        }

        return $arguments;
    }

    /**
     * @return array{default: bool, global: bool, important: bool}
     */
    public function parseValueModifiers(): array
    {
        $modifiers = [
            'default'   => false,
            'global'    => false,
            'important' => false,
        ];

        while (true) {
            $savedPosition = $this->stream->getPosition();

            $this->stream->skipWhitespace();

            if (! $this->stream->consume(TokenType::EXCLAMATION)) {
                $this->stream->setPosition($savedPosition);

                break;
            }

            $this->stream->skipWhitespace();

            if (! $this->stream->is(TokenType::IDENTIFIER)) {
                $this->stream->setPosition($savedPosition);

                break;
            }

            $name = strtolower($this->stream->current()->value);

            $this->stream->advance();

            if ($name === 'default') {
                $modifiers['default'] = true;

                continue;
            }

            if ($name === 'global') {
                $modifiers['global'] = true;

                continue;
            }

            if ($name === 'important') {
                $modifiers['important'] = true;
            }
        }

        return $modifiers;
    }

    public function parseCustomPropertyValue(): string
    {
        $buffer             = '';
        $parenDepth         = 0;
        $bracketDepth       = 0;
        $interpolationDepth = 0;

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if (StreamUtils::consumeInterpolationFragment($this->stream, $buffer, $interpolationDepth, $token)) {
                continue;
            }

            if ($interpolationDepth === 0) {
                StreamUtils::updateNestingDepth($token, $parenDepth, $bracketDepth);

                if (
                    $parenDepth === 0
                    && $bracketDepth === 0
                    && in_array($token->type, [TokenType::SEMICOLON, TokenType::RBRACE], true)
                ) {
                    break;
                }
            }

            StreamUtils::appendTokenToBuffer($buffer, $token, true);

            $this->stream->advance();
        }

        return trim($buffer);
    }

    public function consumeIdentifier(): string
    {
        if (! $this->stream->is(TokenType::IDENTIFIER)) {
            return '';
        }

        $token = $this->stream->current();

        $this->stream->advance();

        return $token->value;
    }

    /**
     * @param array<int, TokenType> $stopTokens
     */
    private function parseValueOrEmptyList(array $stopTokens): AstNode
    {
        return $this->parseValueUntil($stopTokens) ?? new ListNode([], 'comma');
    }

    private function parseParenthesizedEntry(): ?AstNode
    {
        return $this->parseValueUntil([TokenType::COLON, TokenType::COMMA, TokenType::RPAREN]);
    }

    private function parseParenthesizedMapValue(): AstNode
    {
        return $this->parseCommaSeparatedValue() ?? new StringNode('');
    }

    private function parseCommaSeparatedValueOrEmptyList(): AstNode
    {
        return $this->parseValueOrEmptyList([TokenType::COMMA, TokenType::RPAREN]);
    }

    private function parseCommaSeparatedValue(): ?AstNode
    {
        return $this->parseValueUntil([TokenType::COMMA, TokenType::RPAREN]);
    }

    private function consumeArgumentSeparator(): void
    {
        $this->stream->skipWhitespace();
        $this->stream->consume(TokenType::COMMA);
    }

    /**
     * @param array<int, array<int, AstNode>> $groups
     */
    private function buildListFromGroups(array $groups): ?AstNode
    {
        if ($groups === []) {
            return null;
        }

        if (count($groups) === 1) {
            $singleGroup = $groups[0];

            if (count($singleGroup) === 1) {
                return $singleGroup[0];
            }

            return new ListNode($singleGroup, 'space');
        }

        $resultItems = [];

        foreach ($groups as $group) {
            $resultItems[] = count($group) === 1
                ? $group[0]
                : new ListNode($group, 'space');
        }

        return new ListNode($resultItems, 'comma');
    }

    /**
     * @param array<int, array<int, AstNode>> $groups
     * @param array{message: string, line: int, column: int}|null $deprecatedExpression
     */
    private function wrapDeprecatedExpression(array $groups, ?array $deprecatedExpression): ?AstNode
    {
        $result = $this->buildListFromGroups($groups);

        if ($deprecatedExpression === null || ! $result instanceof AstNode) {
            return $result;
        }

        return new DeprecatedExpressionNode(
            $result,
            $deprecatedExpression['message'],
            $deprecatedExpression['line'],
            $deprecatedExpression['column'],
        );
    }

    private function tryParseModuleVariable(): ?VariableReferenceNode
    {
        if (! $this->stream->is(TokenType::IDENTIFIER)) {
            return null;
        }

        $savedPos   = $this->stream->getPosition();
        $identifier = $this->consumeIdentifier();

        $this->stream->skipWhitespace();

        if (! $this->stream->is(TokenType::DOT)) {
            $this->stream->setPosition($savedPos);

            return null;
        }

        $this->stream->advance();
        $this->stream->skipWhitespace();

        if (! $this->stream->consume(TokenType::DOLLAR)) {
            $this->stream->setPosition($savedPos);

            return null;
        }

        $this->stream->skipWhitespace();

        if (! $this->stream->is(TokenType::IDENTIFIER)) {
            $this->stream->setPosition($savedPos);

            return null;
        }

        $varName = $this->consumeIdentifier();

        return new VariableReferenceNode($identifier . '.' . $varName);
    }

    private function tryParseInterpolatedIdentifierString(): ?StringNode
    {
        if (! in_array($this->stream->current()->type, [
            TokenType::IDENTIFIER,
            TokenType::MINUS,
            TokenType::HASH,
        ], true)) {
            return null;
        }

        $savedPosition = $this->stream->getPosition();

        $result = '';

        $sawInterpolation = false;
        $consumedAny      = false;

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if ($token->type === TokenType::IDENTIFIER || $token->type === TokenType::MINUS) {
                $result .= $token->value;

                $consumedAny = true;

                $this->stream->advance();

                continue;
            }

            if ($token->type === TokenType::HASH && $this->stream->peek()->type === TokenType::LBRACE) {
                $result .= $this->parseHashInterpolationString()->value;

                $sawInterpolation = true;
                $consumedAny      = true;

                continue;
            }

            break;
        }

        if ($consumedAny && $sawInterpolation) {
            return new StringNode($result);
        }

        $this->stream->setPosition($savedPosition);

        return null;
    }

    private function parseHashInterpolationString(): StringNode
    {
        $this->stream->consume(TokenType::HASH);
        $this->stream->consume(TokenType::LBRACE);

        $inner = '';
        $depth = 1;

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if ($token->type === TokenType::LBRACE) {
                $depth++;

                $inner .= '{';

                $this->stream->advance();

                continue;
            }

            if ($token->type === TokenType::RBRACE) {
                $depth--;

                if ($depth === 0) {
                    $this->stream->advance();

                    break;
                }

                $inner .= '}';

                $this->stream->advance();

                continue;
            }

            StreamUtils::appendTokenToBuffer($inner, $token, true);

            $this->stream->advance();
        }

        return new StringNode('#{' . trim($inner) . '}');
    }

    private function readNumericPrefixLength(string $value): int
    {
        $length = strlen($value);

        if ($length === 0) {
            return 0;
        }

        $i = 0;

        if ($value[$i] === '+' || $value[$i] === '-') {
            $i++;
        }

        while ($i < $length && ctype_digit($value[$i])) {
            $i++;
        }

        if ($i < $length && $value[$i] === '.') {
            $i++;

            while ($i < $length && ctype_digit($value[$i])) {
                $i++;
            }
        }

        if ($i < $length && ($value[$i] === 'e' || $value[$i] === 'E')) {
            $next     = $value[$i + 1] ?? '';
            $nextNext = $value[$i + 2] ?? '';

            if (ctype_digit($next) || (($next === '+' || $next === '-') && ctype_digit($nextNext))) {
                $i++;

                if ($i < $length && ($value[$i] === '+' || $value[$i] === '-')) {
                    $i++;
                }

                while ($i < $length && ctype_digit($value[$i])) {
                    $i++;
                }
            }
        }

        return $i;
    }
}
