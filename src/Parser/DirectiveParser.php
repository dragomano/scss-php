<?php

declare(strict_types=1);

namespace Bugo\SCSS\Parser;

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
use Closure;

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

    /**
     * @param Closure(): array<int, AstNode> $parseBlock
     * @param Closure(): array<int, AstNode> $parseStatementsInsideBlock
     * @param Closure(): AstNode $parseValue
     * @param Closure(array<int, TokenType>): ?AstNode $parseValueUntil
     * @param Closure(): array{global: bool, default: bool, important: bool} $parseValueModifiers
     * @param Closure(): array<int, AstNode> $parseArgumentList
     * @param Closure(): string $parseString
     * @param Closure(): string $consumeIdentifier
     * @param Closure(string, int, int): RuleNode $parseRuleFromSelector
     * @param Closure(string): AstNode $parseInlineValue
     * @param Closure(): void $incrementBlockDepth
     * @param Closure(): void $decrementBlockDepth
     */
    public function __construct(
        private TokenStream $stream,
        private Closure $parseBlock,
        private Closure $parseStatementsInsideBlock,
        private Closure $parseValue,
        private Closure $parseValueUntil,
        private Closure $parseValueModifiers,
        private Closure $parseArgumentList,
        private Closure $parseString,
        private Closure $consumeIdentifier,
        private Closure $parseRuleFromSelector,
        private Closure $parseInlineValue,
        private Closure $incrementBlockDepth,
        private Closure $decrementBlockDepth
    ) {
        $this->modules = new ModuleDirectiveParser(
            $this->stream,
            $this->parseString,
            $this->consumeIdentifier,
            $this->parseValueUntil,
            $this->parseValueModifiers,
        );

        $this->callable = new CallableDirectiveParser(
            $this->stream,
            $this->parseBlock,
            $this->parseStatementsInsideBlock,
            $this->parseValue,
            $this->parseValueUntil,
            $this->parseArgumentList,
            $this->consumeIdentifier,
            $this->parseRuleFromSelector,
            $this->incrementBlockDepth,
            $this->decrementBlockDepth,
        );
    }

    public function parseDirective(): ?AstNode
    {
        $atToken = $this->stream->consume(TokenType::AT);

        if ($atToken === null) {
            return null;
        }

        $this->stream->skipWhitespace();

        $name = ($this->consumeIdentifier)();

        return match ($name) {
            'use'       => $this->parseUseDirective(),
            'import'    => $this->parseImportDirective(),
            'forward'   => $this->parseForwardDirective(),
            'font-face' => ($this->parseRuleFromSelector)('@font-face', $atToken->line, $atToken->column),
            'include'   => $this->parseIncludeDirective(),
            'mixin'     => $this->parseMixinDirective($atToken->line),
            'function'  => $this->parseFunctionDirective($atToken->line, $atToken->column),
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
            default     => $this->parseGenericDirective($name),
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

    public function parseFunctionDirective(int $line = 1, int $column = 1): AstNode
    {
        return $this->callable->parseFunctionDirective($line, $column);
    }

    public function parseReturnDirective(): AstNode
    {
        return $this->callable->parseReturnDirective();
    }

    public function parseExtendDirective(): ExtendNode
    {
        $this->stream->skipWhitespace();

        $selector = StreamUtils::readRawUntilToken($this->stream, TokenType::SEMICOLON);

        StreamUtils::consumeSemicolonFromStream($this->stream);

        return new ExtendNode(trim($selector));
    }

    public function parseAtRootDirective(): AtRootNode
    {
        $this->stream->skipWhitespace();

        $prelude = '';

        if (! $this->stream->is(TokenType::LBRACE)) {
            $prelude = trim($this->readPreludeUntilBlock());
        }

        $body = ($this->parseBlock)();

        if ($prelude === '') {
            return new AtRootNode($body);
        }

        $query = $this->parseAtRootQuery($prelude);

        if ($query !== null) {
            return new AtRootNode($body, $query['mode'], $query['rules']);
        }

        return new AtRootNode([new RuleNode($prelude, $body)]);
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

    private function readPreludeUntilBlock(): string
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

            if ($interpolationDepth === 0 && $parenDepth === 0 && $bracketDepth === 0 && $token->type === TokenType::LBRACE) {
                break;
            }

            StreamUtils::updateNestingDepth($token, $parenDepth, $bracketDepth);
            StreamUtils::appendTokenToBuffer($buffer, $token, true);

            $this->stream->advance();
        }

        return $buffer;
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
        $this->stream->skipWhitespace();

        $condition = $this->parseCondition();
        $ifBody    = ($this->parseBlock)();

        $elseIfBranches = [];
        $elseBody       = [];
        $maxIterations  = 10;
        $iterations     = 0;

        while ($iterations < $maxIterations) {
            $iterations++;

            $this->stream->skipWhitespace();

            if (! $this->stream->is(TokenType::AT)) {
                break;
            }

            $savedPos = $this->stream->getPosition();

            $this->stream->advance();
            $this->stream->skipWhitespace();

            $keyword = ($this->consumeIdentifier)();

            if ($keyword !== 'else') {
                $this->stream->setPosition($savedPos);

                break;
            }

            $this->stream->skipWhitespace();

            $nextWord = '';

            if ($this->stream->is(TokenType::IDENTIFIER)) {
                $nextWord = $this->stream->current()->value;
            }

            if ($nextWord === 'if') {
                $this->stream->advance();
                $this->stream->skipWhitespace();

                $elseIfCondition = $this->parseCondition();
                $elseIfBody      = ($this->parseBlock)();

                $elseIfBranches[] = new ElseIfNode($elseIfCondition, $elseIfBody);
            } else {
                $elseBody = ($this->parseBlock)();

                break;
            }
        }

        return new IfNode($condition, $ifBody, $elseIfBranches, $elseBody);
    }

    public function parseForDirective(): AstNode
    {
        $this->stream->skipWhitespace();

        if (! $this->stream->consume(TokenType::DOLLAR)) {
            return $this->parseGenericDirective('for');
        }

        $variable = ($this->consumeIdentifier)();

        $this->stream->skipWhitespace();

        if (! StreamUtils::consumeKeyword($this->stream, 'from')) {
            return $this->parseGenericDirective('for');
        }

        $startExpr = StreamUtils::readRawUntilIdentifier($this->stream, ['through', 'to']);

        if (! $this->stream->is(TokenType::IDENTIFIER)) {
            return $this->parseGenericDirective('for');
        }

        $inclusive = $this->stream->current()->value === 'through';

        $this->stream->advance();
        $this->stream->skipWhitespace();

        $endExpr = StreamUtils::readRawUntilToken($this->stream, TokenType::LBRACE);
        $body    = ($this->parseBlock)();

        return new ForNode(
            $variable,
            ($this->parseInlineValue)($startExpr),
            ($this->parseInlineValue)($endExpr),
            $inclusive,
            $body
        );
    }

    public function parseEachDirective(): AstNode
    {
        $this->stream->skipWhitespace();

        if (! $this->stream->consume(TokenType::DOLLAR)) {
            return $this->parseGenericDirective('each');
        }

        $variables = [($this->consumeIdentifier)()];

        while (true) {
            $savedPos = $this->stream->getPosition();

            $this->stream->skipWhitespace();

            if (! $this->stream->consume(TokenType::COMMA)) {
                $this->stream->setPosition($savedPos);

                break;
            }

            $this->stream->skipWhitespace();

            if (! $this->stream->consume(TokenType::DOLLAR)) {
                $this->stream->setPosition($savedPos);

                break;
            }

            $variables[] = ($this->consumeIdentifier)();
        }

        $this->stream->skipWhitespace();

        if (! StreamUtils::consumeKeyword($this->stream, 'in')) {
            return $this->parseGenericDirective('each');
        }

        $listExpr = StreamUtils::readRawUntilToken($this->stream, TokenType::LBRACE);
        $body     = ($this->parseBlock)();

        return new EachNode($variables, ($this->parseInlineValue)($listExpr), $body);
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

            if (StreamUtils::consumeInterpolationFragment($this->stream, $condition, $interpolationDepth, $token)) {
                continue;
            }

            StreamUtils::updateNestingDepth($token, $parenDepth, $bracketDepth);

            if (
                $interpolationDepth === 0
                && $parenDepth === 0
                && $bracketDepth === 0
                && $token->type === TokenType::LBRACE
            ) {
                break;
            }

            StreamUtils::appendTokenToBuffer($condition, $token, true);

            $this->stream->advance();
        }

        return trim($condition);
    }

    public function parseGenericDirective(string $name): AstNode
    {
        $prelude            = '';
        $parenDepth         = 0;
        $bracketDepth       = 0;
        $interpolationDepth = 0;

        while (! $this->stream->isEof()) {
            $token = $this->stream->current();

            if (StreamUtils::consumeInterpolationFragment($this->stream, $prelude, $interpolationDepth, $token)) {
                continue;
            }

            if (
                $interpolationDepth === 0
                && $parenDepth === 0
                && $bracketDepth === 0
                && in_array($token->type, [
                    TokenType::SEMICOLON,
                    TokenType::LBRACE,
                    TokenType::EOF,
                ], true)
            ) {
                break;
            }

            StreamUtils::updateNestingDepth($token, $parenDepth, $bracketDepth);
            StreamUtils::appendTokenToBuffer($prelude, $token, true);

            $this->stream->advance();
        }

        $prelude = trim($prelude);

        if ($this->stream->consume(TokenType::SEMICOLON)) {
            return new DirectiveNode($name, $prelude, [], false);
        }

        if ($this->stream->consume(TokenType::LBRACE)) {
            ($this->incrementBlockDepth)();

            $body = ($this->parseStatementsInsideBlock)();

            ($this->decrementBlockDepth)();

            $this->stream->consume(TokenType::RBRACE);

            return new DirectiveNode($name, $prelude, $body, true);
        }

        return new DirectiveNode($name, $prelude, [], false);
    }

    private function parseDiagnosticDirectiveMessage(): AstNode
    {
        $this->stream->skipWhitespace();

        $message = ($this->parseValue)();

        StreamUtils::consumeSemicolonFromStream($this->stream);

        return $message;
    }

    /**
     * @return array{0: string, 1: array<int, AstNode>}
     */
    private function parseConditionAndBlock(): array
    {
        $this->stream->skipWhitespace();

        return [$this->parseCondition(), ($this->parseBlock)()];
    }
}
