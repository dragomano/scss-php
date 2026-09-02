<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DebugNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\EachNode;
use Bugo\SCSS\Nodes\ElseIfNode;
use Bugo\SCSS\Nodes\ErrorNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\Nodes\WarnNode;
use Bugo\SCSS\Nodes\WhileNode;

use function ctype_alnum;
use function in_array;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

final readonly class DirectiveParser
{
    private ModuleDirectiveParser $modules;

    private CallableDirectiveParser $callable;

    public function __construct(
        private TokenStream $stream,
        private CallableDirectiveParsingContextInterface $parsingContext,
        private InlineValueParserInterface $inlineValueParser,
        private CallableDirectiveValueContextInterface $callableValueContext,
        private ModuleDirectiveContextInterface $moduleValueContext,
    ) {
        $this->modules = new ModuleDirectiveParser(
            $this->stream,
            $this->moduleValueContext,
        );

        $this->callable = new CallableDirectiveParser(
            $this->stream,
            $this->parsingContext,
            $this->callableValueContext,
        );
    }

    public function parseDirective(): AstNode
    {
        $atToken = $this->stream->expect(TokenType::AT);

        $this->stream->skipWhitespaceAndComments();

        $name = $this->moduleValueContext->consumeIdentifier();

        if ($name === '' && $this->stream->is(TokenType::HASH) && $this->stream->peek()->type === TokenType::LBRACE) {
            $name = $this->readInterpolatedDirectiveName();
        }

        if (strtolower($name) === 'function') {
            $isCssFunctionName = $this->isCssFunctionNameAhead();

            if ($name === 'function' || $isCssFunctionName) {
                return $this->parseFunctionDirective($name, $atToken->line, $atToken->column);
            }
        }

        return match ($name) {
            'use'       => $this->parseUseDirective(),
            'import'    => $this->parseImportDirective(),
            'forward'   => $this->parseForwardDirective(),
            'font-face' => $this->parsingContext->parseRuleFromSelector('@font-face', $atToken->line, $atToken->column),
            'include'   => $this->parseIncludeDirective(),
            'mixin'     => $this->parseMixinDirective($atToken->line),
            'extend'    => $this->parseExtendDirective(),
            'at-root'   => $this->parseAtRootDirective(),
            'debug'     => $this->parseDebugDirective($atToken->line, $atToken->column),
            'warn'      => $this->parseWarnDirective($atToken->line, $atToken->column),
            'error'     => $this->parseErrorDirective($atToken->line, $atToken->column),
            'return'    => $this->parseReturnDirective(),
            'if'        => $this->parseIfDirective(),
            'each'      => $this->parseEachDirective(),
            'for'       => $this->parseForDirective(),
            'while'     => $this->parseWhileDirective(),
            'supports'  => $this->parseSupportsDirective(),
            default     => $this->parseGenericDirective($name, $atToken->line, $atToken->column),
        };
    }

    public function parseUseDirective(): UseNode
    {
        return $this->modules->parseUseDirective();
    }

    public function parseImportDirective(): ImportNode
    {
        return $this->modules->parseImportDirective();
    }

    public function parseForwardDirective(): ForwardNode
    {
        return $this->modules->parseForwardDirective();
    }

    public function parseIncludeDirective(): IncludeNode
    {
        return $this->callable->parseIncludeDirective();
    }

    public function parseMixinDirective(int $line = 1): AstNode
    {
        return $this->callable->parseMixinDirective($line);
    }

    public function parseFunctionDirective(string $keyword = 'function', int $line = 1, int $column = 1): AstNode
    {
        return $this->callable->parseFunctionDirective($keyword, $line, $column);
    }

    public function parseReturnDirective(): AstNode
    {
        return $this->callable->parseReturnDirective();
    }

    public function parseExtendDirective(): ExtendNode
    {
        $this->stream->skipWhitespace();

        $selector = TokenStreamHelper::readRawUntil(
            $this->stream,
            fn(Token $token): bool => $token->type === TokenType::SEMICOLON || $token->type === TokenType::RBRACE,
        );

        TokenStreamHelper::consumeSemicolonFromStream($this->stream);

        $selector = trim($selector);
        $optional = false;

        if (str_ends_with($selector, '!optional')) {
            $optional = true;
            $selector = trim(substr($selector, 0, -strlen('!optional')));
        }

        return new ExtendNode($selector, $optional);
    }

    public function parseAtRootDirective(): AtRootNode
    {
        $this->stream->skipWhitespace();

        $prelude = '';

        if (! $this->stream->is(TokenType::LBRACE)) {
            $prelude = trim($this->readPreludeUntilBlock());
        }

        $body = $this->parsingContext->parseBlock();

        if ($prelude === '') {
            return new AtRootNode($body);
        }

        $query = $this->parseAtRootQuery($prelude);

        if ($query !== null) {
            return new AtRootNode($body, $query['mode'], $query['rules']);
        }

        return new AtRootNode([new RuleNode($prelude, $body)]);
    }

    public function parseDebugDirective(int $line = 1, int $column = 1): DebugNode
    {
        return new DebugNode($this->parseDiagnosticDirectiveMessage(), $line, $column);
    }

    public function parseWarnDirective(int $line = 1, int $column = 1): WarnNode
    {
        return new WarnNode($this->parseDiagnosticDirectiveMessage(), $line, $column);
    }

    public function parseErrorDirective(int $line = 1, int $column = 1): ErrorNode
    {
        return new ErrorNode($this->parseDiagnosticDirectiveMessage(), $line, $column);
    }

    public function parseIfDirective(): IfNode
    {
        $this->stream->skipWhitespaceAndComments();

        $condition = $this->parseCondition();
        $ifBody    = $this->parsingContext->parseBlock();

        $elseIfBranches = [];
        $elseBody       = [];
        $maxIterations  = 10;
        $iterations     = 0;

        while ($iterations < $maxIterations) {
            $iterations++;

            $this->stream->skipWhitespaceAndComments();

            if (! $this->stream->is(TokenType::AT)) {
                break;
            }

            $savedPos = $this->stream->getPosition();

            $this->stream->advance();
            $this->stream->skipWhitespaceAndComments();

            $keyword = $this->moduleValueContext->consumeIdentifier();

            if ($keyword !== 'else') {
                $this->stream->setPosition($savedPos);

                break;
            }

            $this->stream->skipWhitespaceAndComments();

            $nextWord = '';

            if ($this->stream->is(TokenType::IDENTIFIER)) {
                $nextWord = $this->stream->current()->value;
            }

            if ($nextWord === 'if') {
                $this->stream->advance();
                $this->stream->skipWhitespaceAndComments();

                $elseIfCondition = $this->parseCondition();
                $elseIfBody      = $this->parsingContext->parseBlock();

                $elseIfBranches[] = new ElseIfNode($elseIfCondition, $elseIfBody);
            } else {
                $elseBody = $this->parsingContext->parseBlock();

                break;
            }
        }

        return new IfNode($condition, $ifBody, $elseIfBranches, $elseBody);
    }

    public function parseForDirective(): AstNode
    {
        $this->stream->skipWhitespaceAndComments();

        if (! $this->stream->consume(TokenType::DOLLAR)) {
            return $this->parseGenericDirective('for');
        }

        $variable = $this->moduleValueContext->consumeIdentifier();

        $this->stream->skipWhitespaceAndComments();

        if (! TokenStreamHelper::consumeKeyword($this->stream, 'from')) {
            return $this->parseGenericDirective('for');
        }

        $this->stream->skipWhitespaceAndComments();

        $startExpr = TokenStreamHelper::readRawUntilIdentifier($this->stream, ['through', 'to']);

        if (! $this->stream->is(TokenType::IDENTIFIER)) {
            return $this->parseGenericDirective('for');
        }

        $inclusive = $this->stream->current()->value === 'through';

        $this->stream->advance();
        $this->stream->skipWhitespaceAndComments();

        $endExpr = TokenStreamHelper::readRawUntilToken($this->stream, TokenType::LBRACE);
        $body    = $this->parsingContext->parseBlock();

        return new ForNode(
            $variable,
            $this->inlineValueParser->parseInlineValue($startExpr),
            $this->inlineValueParser->parseInlineValue($endExpr),
            $inclusive,
            $body,
        );
    }

    public function parseEachDirective(): AstNode
    {
        $this->stream->skipWhitespaceAndComments();

        if (! $this->stream->consume(TokenType::DOLLAR)) {
            return $this->parseGenericDirective('each');
        }

        $variables = [$this->moduleValueContext->consumeIdentifier()];

        while (true) {
            $savedPos = $this->stream->getPosition();

            $this->stream->skipWhitespaceAndComments();

            if (! $this->stream->consume(TokenType::COMMA)) {
                $this->stream->setPosition($savedPos);

                break;
            }

            $this->stream->skipWhitespaceAndComments();

            if (! $this->stream->consume(TokenType::DOLLAR)) {
                $this->stream->setPosition($savedPos);

                break;
            }

            $variables[] = $this->moduleValueContext->consumeIdentifier();
        }

        $this->stream->skipWhitespaceAndComments();

        if (! TokenStreamHelper::consumeKeyword($this->stream, 'in')) {
            return $this->parseGenericDirective('each');
        }

        $listExpr = TokenStreamHelper::readRawUntilToken($this->stream, TokenType::LBRACE);
        $body     = $this->parsingContext->parseBlock();

        return new EachNode($variables, $this->inlineValueParser->parseInlineValue($listExpr), $body);
    }

    public function parseWhileDirective(): AstNode
    {
        [$condition, $body] = $this->parseConditionAndBlock();

        return new WhileNode($condition, $body);
    }

    public function parseSupportsDirective(): AstNode
    {
        [$condition, $body] = $this->parseConditionAndBlock();

        return new SupportsNode($condition, $body);
    }

    public function parseCondition(): string
    {
        $condition          = '';
        $loopCount          = 0;
        $parenDepth         = 0;
        $bracketDepth       = 0;
        $interpolationDepth = 0;

        while (! $this->stream->isEof()) {
            $loopCount++;

            if ($loopCount > 1000) {
                break;
            }

            $token = $this->stream->current();

            if (TokenStreamHelper::consumeInterpolationFragment($this->stream, $condition, $interpolationDepth, $token)) {
                continue;
            }

            TokenStreamHelper::updateNestingDepth($token, $parenDepth, $bracketDepth);

            if (
                $interpolationDepth === 0
                && $parenDepth === 0
                && $bracketDepth === 0
                && $token->type === TokenType::LBRACE
            ) {
                break;
            }

            if ($token->type === TokenType::COMMENT_SILENT) {
                $this->stream->advance();

                // Preserve actual whitespace after silent comments
                if ($this->stream->is(TokenType::WHITESPACE)) {
                    $wsToken = $this->stream->current();

                    $condition .= $wsToken->value;

                    $this->stream->advance();
                }

                continue;
            }

            $wrapped = TokenStreamHelper::wrapComment($token);

            if ($wrapped !== null) {
                $condition .= $wrapped;

                $this->stream->advance();

                continue;
            }

            if ($token->type === TokenType::WHITESPACE && str_contains($token->value, "\n")) {
                $condition .= ltrim($token->value, " \t");
            } else {
                TokenStreamHelper::appendTokenToBuffer($condition, $token, true);
            }

            $this->stream->advance();
        }

        return trim($condition);
    }

    public function parseGenericDirective(string $name, int $line = 0, int $column = 0): AstNode
    {
        $prelude            = '';
        $parenDepth         = 0;
        $bracketDepth       = 0;
        $interpolationDepth = 0;

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if (TokenStreamHelper::consumeInterpolationFragment($this->stream, $prelude, $interpolationDepth, $token)) {
                continue;
            }

            if (
                $interpolationDepth === 0
                && $parenDepth === 0
                && $bracketDepth === 0
                && in_array($token->type, [
                    TokenType::SEMICOLON,
                    TokenType::LBRACE,
                    TokenType::RBRACE,
                    TokenType::EOF,
                ], true)
            ) {
                break;
            }

            TokenStreamHelper::updateNestingDepth($token, $parenDepth, $bracketDepth);

            if ($token->type === TokenType::COMMENT_SILENT) {
                $this->stream->advance();
                $this->stream->skipWhitespace();

                continue;
            }

            if (in_array($token->type, [
                TokenType::COMMENT_LOUD,
                TokenType::COMMENT_PRESERVED,
            ], true)) {
                $prelude .= TokenStreamHelper::wrapComment($token) ?? '';

                $this->stream->advance();

                continue;
            }

            TokenStreamHelper::appendTokenToBuffer($prelude, $token, true);

            $this->stream->advance();
        }

        $prelude = trim($prelude);

        if ($this->stream->consume(TokenType::SEMICOLON)) {
            return new DirectiveNode($name, $prelude, [], false, $line, $column);
        }

        if ($this->stream->consume(TokenType::LBRACE)) {
            $this->parsingContext->incrementBlockDepth();

            $body = $this->parsingContext->parseStatementsInsideBlock();

            $this->parsingContext->decrementBlockDepth();

            $this->stream->consume(TokenType::RBRACE);

            return new DirectiveNode($name, $prelude, $body, true, $line, $column);
        }

        return new DirectiveNode($name, $prelude, [], false, $line, $column);
    }

    /**
     * @return array{mode: string, rules: array<int, string>}|null
     */
    private function parseAtRootQuery(string $prelude): ?array
    {
        if ($prelude === '' || ! str_starts_with($prelude, '(') || ! str_ends_with($prelude, ')')) {
            return null;
        }

        $inner = trim(substr($prelude, 1, -1));

        if ($inner === '') {
            return null;
        }

        $colonPosition = strpos($inner, ':');

        if ($colonPosition === false) {
            return null;
        }

        $mode = strtolower(trim(substr($inner, 0, $colonPosition)));

        if (! in_array($mode, ['with', 'without'], true)) {
            return null;
        }

        $rulesText = trim(substr($inner, $colonPosition + 1));

        if ($rulesText === '') {
            return null;
        }

        $rules   = [];
        $current = '';
        $length  = strlen($rulesText);

        for ($index = 0; $index < $length; $index++) {
            $char = $rulesText[$index];

            if (ctype_alnum($char) || $char === '-' || $char === '_') {
                $current .= strtolower($char);

                continue;
            }

            if (in_array($char, [',', ' ', "\t", "\n", "\r"], true)) {
                if ($current !== '') {
                    $rules[] = $current;
                    $current = '';
                }

                continue;
            }

            return null;
        }

        if ($current !== '') {
            $rules[] = $current;
        }

        if ($rules === []) {
            return null;
        }

        return [
            'mode'  => $mode,
            'rules' => $rules,
        ];
    }

    private function isCssFunctionNameAhead(): bool
    {
        $savedPosition = $this->stream->getPosition();

        $this->stream->skipWhitespaceAndComments();

        $isCssFunctionName = $this->stream->is(TokenType::CSS_VARIABLE)
            && str_starts_with($this->stream->current()->value, '--');

        $this->stream->setPosition($savedPosition);

        return $isCssFunctionName;
    }

    private function readInterpolatedDirectiveName(): string
    {
        $buffer             = '';
        $interpolationDepth = 0;

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if (TokenStreamHelper::consumeInterpolationFragment($this->stream, $buffer, $interpolationDepth, $token)) {
                continue;
            }

            if ($interpolationDepth === 0) {
                break;
            }

            TokenStreamHelper::appendTokenToBuffer($buffer, $token, true);

            $this->stream->advance();
        }

        return $buffer;
    }

    private function readPreludeUntilBlock(): string
    {
        $buffer             = '';
        $parenDepth         = 0;
        $bracketDepth       = 0;
        $interpolationDepth = 0;

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if (TokenStreamHelper::consumeInterpolationFragment($this->stream, $buffer, $interpolationDepth, $token)) {
                continue;
            }

            if ($interpolationDepth === 0 && $parenDepth === 0 && $bracketDepth === 0 && $token->type === TokenType::LBRACE) {
                break;
            }

            TokenStreamHelper::updateNestingDepth($token, $parenDepth, $bracketDepth);
            TokenStreamHelper::appendTokenToBuffer($buffer, $token, true);

            $this->stream->advance();
        }

        return $buffer;
    }

    private function parseDiagnosticDirectiveMessage(): AstNode
    {
        $this->stream->skipWhitespace();

        $message = $this->callableValueContext->parseValue();

        TokenStreamHelper::consumeSemicolonFromStream($this->stream);

        return $message;
    }

    /**
     * @return array{0: string, 1: array<int, AstNode>}
     */
    private function parseConditionAndBlock(): array
    {
        $this->stream->skipWhitespace();

        return [$this->parseCondition(), $this->parsingContext->parseBlock()];
    }
}
