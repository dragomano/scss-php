<?php

declare(strict_types=1);

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DebugNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\EachNode;
use Bugo\SCSS\Nodes\ErrorNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\FunctionDeclarationNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\MixinNode;
use Bugo\SCSS\Nodes\ReturnNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\WarnNode;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\Parser;
use Bugo\SCSS\Parser\CallableDirectiveParsingContextInterface;
use Bugo\SCSS\Parser\CallableDirectiveValueContextInterface;
use Bugo\SCSS\Parser\DirectiveParser;
use Bugo\SCSS\Parser\InlineValueParserInterface;
use Bugo\SCSS\Parser\ModuleDirectiveContextInterface;
use Bugo\SCSS\Parser\TokenStreamHelper;

function directiveTestToken(
    TokenType $type,
    string $value = '',
    int $line = 1,
    int $column = 1,
): Token {
    return new Token($type, $value, $line, $column);
}

/**
 * @param array<int, Token> $tokens
 * @param array<string, mixed> $overrides
 */
function createDirectiveParserForTest(array $tokens, array $overrides = []): DirectiveParser
{
    $stream = new TokenStream($tokens);

    $consumeIdentifier = $overrides['consumeIdentifier'] ?? static function () use ($stream): string {
        return TokenStreamHelper::consumeIdentifier($stream);
    };

    $parseBlock = $overrides['parseBlock'] ?? static fn(): array => [];
    $parseStatementsInsideBlock = $overrides['parseStatementsInsideBlock'] ?? static fn(): array => [];
    $parseValueUntil = $overrides['parseValueUntil'] ?? static fn(array $stopTypes): ?AstNode => null;

    $parseRuleFromSelector = $overrides['parseRuleFromSelector']
        ?? static fn(string $selector, int $line, int $column): RuleNode => new RuleNode($selector, [], $line, $column);

    $parsingContext = new class (
        $parseBlock,
        $parseStatementsInsideBlock,
        $consumeIdentifier,
        $parseRuleFromSelector,
    ) implements CallableDirectiveParsingContextInterface {
        public function __construct(
            private readonly Closure $parseBlock,
            private readonly Closure $parseStatementsInsideBlock,
            private readonly Closure $consumeIdentifier,
            private readonly Closure $parseRuleFromSelector,
        ) {}

        public function parseBlock(): array
        {
            return ($this->parseBlock)();
        }

        public function parseStatementsInsideBlock(): array
        {
            return ($this->parseStatementsInsideBlock)();
        }

        public function consumeIdentifier(): string
        {
            return ($this->consumeIdentifier)();
        }

        public function parseRuleFromSelector(string $selector, int $line = 1, int $column = 1): RuleNode
        {
            return ($this->parseRuleFromSelector)($selector, $line, $column);
        }

        public function incrementBlockDepth(): void {}

        public function decrementBlockDepth(): void {}
    };

    $inlineValueParser = new class implements InlineValueParserInterface {
        public function parseInlineValue(string $expression): AstNode
        {
            return new StringNode($expression);
        }
    };

    $callableValueContext = new class ($parseValueUntil) implements CallableDirectiveValueContextInterface {
        public function __construct(private readonly Closure $parseValueUntil) {}

        public function parseValue(): AstNode
        {
            return new StringNode('value');
        }

        public function parseValueUntil(array $stopTokens): ?AstNode
        {
            return ($this->parseValueUntil)($stopTokens);
        }

        public function parseArgumentList(): array
        {
            return [];
        }
    };

    $moduleValueContext = new class ($consumeIdentifier, $parseValueUntil) implements ModuleDirectiveContextInterface {
        public function __construct(
            private readonly Closure $consumeIdentifier,
            private readonly Closure $parseValueUntil,
        ) {}

        public function parseString(): string
        {
            return '';
        }

        public function consumeIdentifier(): string
        {
            return ($this->consumeIdentifier)();
        }

        public function parseValueUntil(array $stopTokens): ?AstNode
        {
            return ($this->parseValueUntil)($stopTokens);
        }

        /**
         * @return array{default: bool, global: bool, important: bool}
         */
        public function parseValueModifiers(): array
        {
            return ['default' => false, 'global' => false, 'important' => false];
        }
    };

    return new DirectiveParser($stream, $parsingContext, $inlineValueParser, $callableValueContext, $moduleValueContext);
}

describe('DirectiveParser', function () {
    beforeEach(function () {
        $this->parser = new Parser();
    });

    describe('@forward', function () {
        it('parses @forward with show clause', function () {
            $ast  = $this->parser->parse('@forward "utils" show $color, mix;');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForwardNode::class)
                ->and($node->path)->toBe('utils')
                ->and($node->visibility)->toBe('show')
                ->and($node->members)->toContain('$color')
                ->and($node->members)->toContain('mix');
        });

        it('parses @forward with hide clause', function () {
            $ast  = $this->parser->parse('@forward "utils" hide $internal;');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForwardNode::class)
                ->and($node->visibility)->toBe('hide')
                ->and($node->members)->toContain('$internal');
        });

        it('parses @forward with as prefix', function () {
            $ast  = $this->parser->parse('@forward "utils" as u-*;');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForwardNode::class)
                ->and($node->prefix)->toBe('u-');
        });

        it('parses @forward without options', function () {
            $ast  = $this->parser->parse('@forward "utils";');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForwardNode::class)
                ->and($node->path)->toBe('utils')
                ->and($node->visibility)->toBeNull()
                ->and($node->prefix)->toBeNull();
        });
    });

    describe('@mixin', function () {
        it('parses mixin without parameters', function () {
            $ast  = $this->parser->parse('@mixin clearfix { content: ""; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(MixinNode::class)
                ->and($node->name)->toBe('clearfix')
                ->and($node->arguments)->toBe([]);
        });

        it('parses mixin with parameters', function () {
            $ast  = $this->parser->parse('@mixin flex($dir: row, $wrap: nowrap) { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(MixinNode::class)
                ->and($node->name)->toBe('flex')
                ->and(count($node->arguments))->toBe(2);
        });

        it('parses mixin with content block', function () {
            $ast  = $this->parser->parse('@mixin hover { &:hover { @content; } }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(MixinNode::class)
                ->and($node->name)->toBe('hover');
        });
    });

    describe('@include', function () {
        it('parses include without arguments', function () {
            $ast  = $this->parser->parse('.a { @include clearfix; }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(IncludeNode::class)
                ->and($node->name)->toBe('clearfix')
                ->and($node->namespace)->toBeNull();
        });

        it('parses include with positional arguments', function () {
            $ast  = $this->parser->parse('.a { @include flex(column, wrap); }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(IncludeNode::class)
                ->and(count($node->arguments))->toBe(2);
        });

        it('parses namespaced include', function () {
            $ast  = $this->parser->parse('@use "lib"; .a { @include lib.mixin; }');
            $node = $ast->children[1]->children[0];

            expect($node)->toBeInstanceOf(IncludeNode::class)
                ->and($node->name)->toBe('mixin')
                ->and($node->namespace)->toBe('lib');
        });
    });

    describe('@function', function () {
        it('parses function declaration without parameters', function () {
            $ast  = $this->parser->parse('@function pi() { @return 3.14159; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(FunctionDeclarationNode::class)
                ->and($node->name)->toBe('pi')
                ->and($node->arguments)->toBe([]);
        });

        it('parses function with parameters', function () {
            $ast  = $this->parser->parse('@function double($n) { @return $n * 2; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(FunctionDeclarationNode::class)
                ->and($node->name)->toBe('double')
                ->and(count($node->arguments))->toBe(1);
        });

        it('parses function with default parameter', function () {
            $ast  = $this->parser->parse('@function pad($n, $min: 0) { @return $n; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(FunctionDeclarationNode::class)
                ->and(count($node->arguments))->toBe(2);
        });
    });

    describe('@return', function () {
        it('parses @return inside function body', function () {
            $ast  = $this->parser->parse('@function f() { @return 42; }');
            $node = $ast->children[0]->body[0];

            expect($node)->toBeInstanceOf(ReturnNode::class);
        });
    });

    describe('@extend', function () {
        it('parses extend directive with selector', function () {
            $ast  = $this->parser->parse('.error { @extend .alert; }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(ExtendNode::class)
                ->and($node->selector)->toBe('.alert');
        });

        it('parses extend with class selector', function () {
            $ast  = $this->parser->parse('.button--primary { @extend .button; }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(ExtendNode::class)
                ->and($node->selector)->toBe('.button');
        });

        it('parses extend without trailing semicolon', function () {
            $ast  = $this->parser->parse('.error { @extend .alert }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(ExtendNode::class)
                ->and($node->selector)->toBe('.alert')
                ->and($node->optional)->toBeFalse();
        });

        it('parses extend before a sibling rule without trailing semicolon', function () {
            $ast  = $this->parser->parse('.error { @extend .alert } .alert { color: red; }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(ExtendNode::class)
                ->and($node->selector)->toBe('.alert');
        });

        it('parses optional extend', function () {
            $ast  = $this->parser->parse('.error { @extend .alert !optional; }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(ExtendNode::class)
                ->and($node->selector)->toBe('.alert')
                ->and($node->optional)->toBeTrue();
        });

        it('parses optional extend without trailing semicolon', function () {
            $ast  = $this->parser->parse('.error { @extend .alert !optional }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(ExtendNode::class)
                ->and($node->selector)->toBe('.alert')
                ->and($node->optional)->toBeTrue();
        });
    });

    describe('@at-root', function () {
        it('parses @at-root without query', function () {
            $ast  = $this->parser->parse('.parent { @at-root .child { color: red; } }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(AtRootNode::class)
                ->and($node->queryMode)->toBeNull();
        });

        it('parses @at-root with without query', function () {
            $ast  = $this->parser->parse('.a { @at-root (without: media) { color: red; } }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(AtRootNode::class)
                ->and($node->queryMode)->toBe('without')
                ->and($node->queryRules)->toContain('media');
        });

        it('parses @at-root with with query', function () {
            $ast  = $this->parser->parse('.a { @at-root (with: rule) { color: red; } }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(AtRootNode::class)
                ->and($node->queryMode)->toBe('with');
        });

        it('parses @at-root query with multiple rules separated by commas and whitespace', function () {
            $ast  = $this->parser->parse('.a { @at-root (with: rule, media tabs_and_spaces) { color: red; } }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(AtRootNode::class)
                ->and($node->queryMode)->toBe('with')
                ->and($node->queryRules)->toBe(['rule', 'media', 'tabs_and_spaces']);
        });

        it('falls back to selector form for invalid queries', function (string $source, string $selector) {
            $ast  = $this->parser->parse($source);
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(AtRootNode::class)
                ->and($node->queryMode)->toBeNull()
                ->and($node->queryRules)->toBe([])
                ->and($node->body)->toHaveCount(1)
                ->and($node->body[0])->toBeInstanceOf(RuleNode::class)
                ->and($node->body[0]->selector)->toBe($selector);
        })->with([
            ['.a { @at-root () { color: red; } }', '()'],
            ['.a { @at-root (media) { color: red; } }', '(media)'],
            ['.a { @at-root (inside: media) { color: red; } }', '(inside: media)'],
            ['.a { @at-root (with:) { color: red; } }', '(with:)'],
            ['.a { @at-root (with: media!) { color: red; } }', '(with: media!)'],
            ['.a { @at-root (with: ,) { color: red; } }', '(with: ,)'],
        ]);
    });

    describe('@if / @else', function () {
        it('parses simple @if', function () {
            $ast  = $this->parser->parse('@if true { color: red; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(IfNode::class)
                ->and($node->condition)->toBe('true');
        });

        it('parses @if with @else branch', function () {
            $ast  = $this->parser->parse('@if $x { color: red; } @else { color: blue; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(IfNode::class)
                ->and($node->elseBody)->not->toBeEmpty();
        });

        it('parses @if with @else if chain', function () {
            $ast  = $this->parser->parse('@if $a { } @else if $b { } @else { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(IfNode::class)
                ->and($node->elseIfBranches)->not->toBeEmpty();
        });

        it('parses @if with complex condition', function () {
            $ast  = $this->parser->parse('@if $x > 0 and $y < 10 { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(IfNode::class)
                ->and($node->condition)->toContain('$x');
        });
    });

    describe('@for', function () {
        it('parses @for from...to (exclusive)', function () {
            $ast  = $this->parser->parse('@for $i from 1 to 5 { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForNode::class)
                ->and($node->variable)->toBe('i')
                ->and($node->inclusive)->toBeFalse();
        });

        it('parses @for from...through (inclusive)', function () {
            $ast  = $this->parser->parse('@for $i from 1 through 5 { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForNode::class)
                ->and($node->variable)->toBe('i')
                ->and($node->inclusive)->toBeTrue();
        });

        it('falls back to a generic directive for invalid @for syntax', function (
            string $source,
            string $prelude,
            bool $hasBlock,
        ) {
            $ast  = $this->parser->parse($source);
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(DirectiveNode::class)
                ->and($node->name)->toBe('for')
                ->and($node->prelude)->toBe($prelude)
                ->and($node->hasBlock)->toBe($hasBlock);
        })->with([
            ['@for i from 1 to 5 { }', 'i from 1 to 5', true],
            ['@for $i 1 to 5 { }', '1 to 5', true],
            ['@for $i from 1 5 { }', '', false],
        ]);
    });

    describe('@each', function () {
        it('parses @each over simple list', function () {
            $ast  = $this->parser->parse('@each $color in red, green, blue { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(EachNode::class)
                ->and($node->variables)->toBe(['color']);
        });

        it('parses @each with multiple variables (map destructuring)', function () {
            $ast  = $this->parser->parse('@each $key, $value in $map { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(EachNode::class)
                ->and($node->variables)->toBe(['key', 'value']);
        });

        it('falls back to a generic directive for invalid @each syntax', function (string $source, string $prelude) {
            $ast  = $this->parser->parse($source);
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(DirectiveNode::class)
                ->and($node->name)->toBe('each')
                ->and($node->prelude)->toBe($prelude)
                ->and($node->hasBlock)->toBeTrue();
        })->with([
            ['@each color in red, green { }', 'color in red, green'],
            ['@each $key, value in $map { }', ', value in $map'],
            ['@each $color red, green { }', 'red, green'],
        ]);
    });

    describe('@while', function () {
        it('parses @while with condition', function () {
            $ast  = $this->parser->parse('@while $i > 0 { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(WhileNode::class)
                ->and($node->condition)->toContain('$i');
        });
    });

    describe('@debug / @warn / @error', function () {
        it('parses @debug with string message', function () {
            $ast = $this->parser->parse('@debug "hello";');

            expect($ast->children[0])->toBeInstanceOf(DebugNode::class);
        });

        it('parses @warn with string message', function () {
            $ast = $this->parser->parse('@warn "deprecated";');

            expect($ast->children[0])->toBeInstanceOf(WarnNode::class);
        });

        it('parses @error with string message', function () {
            $ast = $this->parser->parse('@error "invalid value";');

            expect($ast->children[0])->toBeInstanceOf(ErrorNode::class);
        });

        it('parses @debug with expression', function () {
            $ast = $this->parser->parse('@debug $variable;');

            expect($ast->children[0])->toBeInstanceOf(DebugNode::class);
        });
    });

    describe('@supports', function () {
        it('parses @supports with simple condition', function () {
            $ast  = $this->parser->parse('@supports (display: grid) { .a { display: grid; } }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(SupportsNode::class)
                ->and($node->condition)->toContain('display: grid');
        });

        it('parses @supports with not condition', function () {
            $ast  = $this->parser->parse('@supports not (display: grid) { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(SupportsNode::class)
                ->and($node->condition)->toContain('not');
        });

        it('stops reading supports conditions after the loop guard threshold', function () {
            $ast  = $this->parser->parse('@supports ' . str_repeat('(', 1002) . 'display:grid');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(SupportsNode::class)
                ->and(strlen($node->condition))->toBe(1000)
                ->and($node->condition)->toBe(str_repeat('(', 1000));
        });
    });

    describe('generic / unknown directives', function () {
        it('parses unknown block directive as DirectiveNode', function () {
            $ast  = $this->parser->parse('@unknown-rule custom-param { color: red; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(DirectiveNode::class)
                ->and($node->name)->toBe('unknown-rule');
        });

        it('parses @keyframes as DirectiveNode', function () {
            $ast  = $this->parser->parse('@keyframes slide { from { opacity: 0; } to { opacity: 1; } }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(DirectiveNode::class)
                ->and($node->name)->toBe('keyframes')
                ->and($node->hasBlock)->toBeTrue();
        });

        it('parses @media as DirectiveNode', function () {
            $ast  = $this->parser->parse('@media (max-width: 768px) { .a { display: none; } }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(DirectiveNode::class)
                ->and($node->name)->toBe('media');
        });

        it('parses @layer as DirectiveNode', function () {
            $ast  = $this->parser->parse('@layer utilities { .a { color: red; } }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(DirectiveNode::class)
                ->and($node->name)->toBe('layer');
        });

        it('parses generic directives without semicolon or block at eof', function () {
            $ast  = $this->parser->parse('@media screen');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(DirectiveNode::class)
                ->and($node->name)->toBe('media')
                ->and($node->prelude)->toBe('screen')
                ->and($node->body)->toBe([])
                ->and($node->hasBlock)->toBeFalse();
        });
    });
});

describe('DirectiveParser without raw source', function () {
    it('tokenizes a plain prelude of a generic directive', function () {
        $parser = createDirectiveParserForTest([
            directiveTestToken(TokenType::AT, '@'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::IDENTIFIER, 'u'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::IDENTIFIER, 'a'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::NUMBER, '1'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::SEMICOLON, ';'),
            directiveTestToken(TokenType::EOF),
        ]);

        $node = $parser->parseDirective();

        /** @var DirectiveNode $node */
        expect($node)->toBeInstanceOf(DirectiveNode::class)
            ->and($node->name)->toBe('u')
            ->and($node->prelude)->toBe('a 1')
            ->and($node->hasBlock)->toBeFalse();
    });

    it('keeps interpolation fragments inside a tokenized prelude', function () {
        $parser = createDirectiveParserForTest([
            directiveTestToken(TokenType::AT, '@'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::IDENTIFIER, 'v'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::HASH, '#'),
            directiveTestToken(TokenType::LBRACE, '{'),
            directiveTestToken(TokenType::IDENTIFIER, 'x'),
            directiveTestToken(TokenType::RBRACE, '}'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::SEMICOLON, ';'),
            directiveTestToken(TokenType::EOF),
        ]);

        $node = $parser->parseDirective();

        /** @var DirectiveNode $node */
        expect($node)->toBeInstanceOf(DirectiveNode::class)
            ->and($node->name)->toBe('v')
            ->and($node->prelude)->toBe('#{x}');
    });

    it('drops silent comments and keeps loud comments in a tokenized prelude', function () {
        $parser = createDirectiveParserForTest([
            directiveTestToken(TokenType::AT, '@'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::IDENTIFIER, 'k'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::IDENTIFIER, 'a'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::COMMENT_SILENT, 'x'),
            directiveTestToken(TokenType::WHITESPACE, "\n"),
            directiveTestToken(TokenType::COMMENT_LOUD, 'y'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::IDENTIFIER, 'b'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::SEMICOLON, ';'),
            directiveTestToken(TokenType::EOF),
        ]);

        $node = $parser->parseDirective();

        /** @var DirectiveNode $node */
        expect($node)->toBeInstanceOf(DirectiveNode::class)
            ->and($node->name)->toBe('k')
            ->and($node->prelude)->toBe('a /*y*/ b');
    });

    it('opens a block for a generic directive with a tokenized prelude', function () {
        $parser = createDirectiveParserForTest([
            directiveTestToken(TokenType::AT, '@'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::IDENTIFIER, 'q'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::IDENTIFIER, 'p'),
            directiveTestToken(TokenType::LBRACE, '{'),
            directiveTestToken(TokenType::RBRACE, '}'),
            directiveTestToken(TokenType::EOF),
        ]);

        $node = $parser->parseDirective();

        /** @var DirectiveNode $node */
        expect($node)->toBeInstanceOf(DirectiveNode::class)
            ->and($node->name)->toBe('q')
            ->and($node->prelude)->toBe('p')
            ->and($node->hasBlock)->toBeTrue();
    });

    it('falls back to a selector for an at-root query with an unclosed quote', function () {
        $parser = createDirectiveParserForTest([
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::LPAREN, '('),
            directiveTestToken(TokenType::IDENTIFIER, 'without'),
            directiveTestToken(TokenType::COLON, ':'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::IDENTIFIER, 'media"x'),
            directiveTestToken(TokenType::RPAREN, ')'),
            directiveTestToken(TokenType::WHITESPACE, ' '),
            directiveTestToken(TokenType::LBRACE, '{'),
            directiveTestToken(TokenType::RBRACE, '}'),
            directiveTestToken(TokenType::EOF),
        ]);

        $node = $parser->parseAtRootDirective();

        /** @var AtRootNode $node */
        expect($node)->toBeInstanceOf(AtRootNode::class)
            ->and($node->queryMode)->toBeNull()
            ->and($node->body)->toHaveCount(1)
            ->and($node->body[0])->toBeInstanceOf(RuleNode::class)
            ->and($node->body[0]->selector)->toBe('(without: media"x)');
    });
});
