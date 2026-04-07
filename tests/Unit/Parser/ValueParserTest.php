<?php

declare(strict_types=1);

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\Tokenizer;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DeprecatedExpressionNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Parser;
use Bugo\SCSS\Parser\ValueParser;
use Tests\ReflectionAccessor;

describe('ValueParser', function () {
    beforeEach(function () {
        $this->parser = new Parser();
        $this->createValueParser = function (string $source): array {
            $stream = new TokenStream((new Tokenizer())->tokenize($source));
            $parser = new ValueParser($stream, static fn(string $expression): AstNode => new StringNode($expression));

            return [$parser, $stream];
        };
    });

    describe('numbers', function () {
        it('parses integer values', function () {
            $ast  = $this->parser->parse('.a { z-index: 10; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(NumberNode::class)
                ->and($decl->value->value)->toBe(10)
                ->and($decl->value->unit)->toBeNull();
        });

        it('parses float values', function () {
            $ast  = $this->parser->parse('.a { opacity: 0.75; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(NumberNode::class)
                ->and($decl->value->value)->toBe(0.75);
        });

        it('parses negative numbers', function () {
            $ast  = $this->parser->parse('.a { margin: -10px; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(NumberNode::class)
                ->and($decl->value->value)->toBe(-10)
                ->and($decl->value->unit)->toBe('px');
        });

        it('parses numbers with various units', function (string $declaration, int|float $expectedValue, string $expectedUnit) {
            $ast   = $this->parser->parse(".a { $declaration }");
            $value = $ast->children[0]->children[0]->value;

            expect($value)->toBeInstanceOf(NumberNode::class)
                ->and($value->value)->toBe($expectedValue)
                ->and($value->unit)->toBe($expectedUnit);
        })->with([
            ['width: 100%;',  100,  '%'],
            ['height: 2em;',    2,  'em'],
            ['font: 1.5rem;', 1.5,  'rem'],
            ['angle: 90deg;',  90,  'deg'],
            ['time: 200ms;',  200,  'ms'],
        ]);
    });

    describe('strings', function () {
        it('parses unquoted string identifiers', function () {
            $ast  = $this->parser->parse('.a { display: flex; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(StringNode::class)
                ->and($decl->value->value)->toBe('flex');
        });

        it('parses double-quoted strings', function () {
            $ast  = $this->parser->parse('$x: "hello world";');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(StringNode::class)
                ->and($decl->value->value)->toBe('hello world');
        });

        it('parses single-quoted strings', function () {
            $ast  = $this->parser->parse('$x: \'hello\';');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(StringNode::class)
                ->and($decl->value->value)->toBe('hello');
        });

        it('parses hash interpolation as a string value', function () {
            [$valueParser] = ($this->createValueParser)('#{foo}');

            $value = $valueParser->parseSingleValue();

            expect($value)->toBeInstanceOf(StringNode::class)
                ->and($value->value)->toBe('#{foo}');
        });

        it('parses nested hash interpolation preserving inner braces', function () {
            $stream = new TokenStream([
                new Token(TokenType::HASH, '#', 1, 1),
                new Token(TokenType::LBRACE, '{', 1, 2),
                new Token(TokenType::IDENTIFIER, 'a', 1, 3),
                new Token(TokenType::LBRACE, '{', 1, 4),
                new Token(TokenType::IDENTIFIER, 'b', 1, 5),
                new Token(TokenType::RBRACE, '}', 1, 6),
                new Token(TokenType::RBRACE, '}', 1, 7),
                new Token(TokenType::EOF, '', 1, 8),
            ]);

            $valueParser = new ValueParser(
                $stream,
                static fn(string $expression): AstNode => new StringNode($expression),
            );

            /* @var $value StringNode */
            $value = $valueParser->parseSingleValue();

            expect($value)->toBeInstanceOf(StringNode::class)
                ->and($value->value)->toBe('#{a{b}}');
        });
    });

    describe('colors', function () {
        it('parses 3-digit hex color', function () {
            $ast  = $this->parser->parse('.a { color: #f00; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(ColorNode::class)
                ->and($decl->value->value)->toBe('#f00');
        });

        it('parses 6-digit hex color', function () {
            $ast  = $this->parser->parse('.a { color: #ff0000; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(ColorNode::class)
                ->and($decl->value->value)->toBe('#ff0000');
        });
    });

    describe('boolean and null', function () {
        it('parses true', function () {
            $ast  = $this->parser->parse('$x: true;');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(BooleanNode::class)
                ->and($decl->value->value)->toBeTrue();
        });

        it('parses false', function () {
            $ast  = $this->parser->parse('$x: false;');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(BooleanNode::class)
                ->and($decl->value->value)->toBeFalse();
        });

        it('parses null', function () {
            $ast  = $this->parser->parse('$x: null;');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(NullNode::class);
        });
    });

    describe('variable references', function () {
        it('parses variable reference', function () {
            $ast  = $this->parser->parse('.a { color: $primary; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(VariableReferenceNode::class)
                ->and($decl->value->name)->toBe('primary');
        });

        it('parses namespaced variable as combined name', function () {
            $ast  = $this->parser->parse('@use "colors"; .a { color: colors.$primary; }');
            $decl = $ast->children[1]->children[0];

            // Namespace is encoded as "namespace.varname" in the name property
            expect($decl->value)->toBeInstanceOf(VariableReferenceNode::class)
                ->and($decl->value->name)->toBe('colors.primary');
        });

        it('re-parses dotted identifiers as namespaced functions when no dollar follows the dot', function () {
            $ast  = $this->parser->parse('.a { color: color.adjust(10px); }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(FunctionNode::class)
                ->and($decl->value->name)->toBe('color.adjust')
                ->and(count($decl->value->arguments))->toBe(1);
        });
    });

    describe('lists', function () {
        it('parses comma-separated list', function () {
            $ast  = $this->parser->parse('$x: (a, b, c);');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(ListNode::class)
                ->and($decl->value->separator)->toBe('comma')
                ->and(count($decl->value->items))->toBe(3);
        });

        it('parses space-separated list', function () {
            $ast  = $this->parser->parse('.a { margin: 10px 20px 10px 20px; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(ListNode::class)
                ->and($decl->value->separator)->toBe('space')
                ->and(count($decl->value->items))->toBe(4);
        });

        it('parses bracketed list', function () {
            $ast  = $this->parser->parse('$x: [a, b, c];');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(ListNode::class)
                ->and($decl->value->bracketed)->toBeTrue()
                ->and(count($decl->value->items))->toBe(3);
        });
    });

    describe('maps', function () {
        it('parses simple map', function () {
            $ast  = $this->parser->parse('$x: (key: value, other: thing);');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(MapNode::class)
                ->and(count($decl->value->pairs))->toBe(2);
        });

        it('parses map pair keys and values', function () {
            $ast  = $this->parser->parse('$x: (primary: #f00);');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(MapNode::class);

            $pair = $decl->value->pairs[0];
            expect($pair['key'])->toBeInstanceOf(StringNode::class)
                ->and($pair['key']->value)->toBe('primary')
                ->and($pair['value'])->toBeInstanceOf(ColorNode::class);
        });
    });

    describe('function calls', function () {
        it('parses simple function call', function () {
            $ast  = $this->parser->parse('.a { color: rgb(255, 0, 0); }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(FunctionNode::class)
                ->and($decl->value->name)->toBe('rgb')
                ->and(count($decl->value->arguments))->toBe(3);
        });

        it('parses function call with named arguments', function () {
            $ast  = $this->parser->parse('@use "sass:color"; .a { color: color.adjust($c, $red: 10); }');
            $decl = $ast->children[1]->children[0];

            expect($decl->value)->toBeInstanceOf(FunctionNode::class);

            $namedArg = $decl->value->arguments[1];
            expect($namedArg)->toBeInstanceOf(NamedArgumentNode::class)
                ->and($namedArg->name)->toBe('red');
        });

        it('parses CSS var() function', function () {
            $ast  = $this->parser->parse('.a { color: var(--primary); }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(FunctionNode::class)
                ->and($decl->value->name)->toBe('var');
        });

        it('parses url() function', function () {
            $ast  = $this->parser->parse('.a { background: url(image.png); }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(FunctionNode::class)
                ->and($decl->value->name)->toBe('url');
        });
    });

    describe('modifiers', function () {
        it('parses !important as part of value list in AST', function () {
            // !important is stored as a StringNode("!important") inside the value ListNode at parse time,
            // and gets resolved to DeclarationNode::$important=true during evaluation
            $ast  = $this->parser->parse('.a { color: red !important; }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->value)->toBeInstanceOf(ListNode::class);

            $items = $decl->value->items;
            $lastItem = end($items);
            expect($lastItem)->toBeInstanceOf(StringNode::class)
                ->and($lastItem->value)->toBe('!important');
        });

        it('parses !default on variable', function () {
            $ast  = $this->parser->parse('$primary: blue !default;');
            $decl = $ast->children[0];

            expect($decl)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($decl->default)->toBeTrue()
                ->and($decl->global)->toBeFalse();
        });

        it('parses !global on variable', function () {
            $ast  = $this->parser->parse('.a { $x: 10 !global; }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($decl->global)->toBeTrue();
        });

        it('stops parsing modifiers when ! is not followed by an identifier', function () {
            [$valueParser, $stream] = ($this->createValueParser)('! 10');

            expect($valueParser->parseValueModifiers())->toBe([
                'default' => false,
                'global' => false,
                'important' => false,
            ])
                ->and($stream->current()->type)->toBe(TokenType::EXCLAMATION);
        });

        it('parses chained value modifiers directly', function () {
            [$valueParser] = ($this->createValueParser)('!default !global !important');

            expect($valueParser->parseValueModifiers())->toBe([
                'default' => true,
                'global' => true,
                'important' => true,
            ]);
        });
    });

    describe('direct parser helpers', function () {
        it('parses operator keywords directly', function (string $keyword) {
            [$valueParser] = ($this->createValueParser)($keyword);

            expect($valueParser->parseOperatorOrKeyword())->toBe($keyword);
        })->with(['and', 'or', 'not']);

        it('returns null for single values at eof', function () {
            [$valueParser] = ($this->createValueParser)('');

            expect($valueParser->parseSingleValue())->toBeNull();
        });

        it('returns css variable tokens as plain strings in single-value mode', function () {
            [$valueParser] = ($this->createValueParser)('--primary');

            $value = $valueParser->parseSingleValue();

            expect($value)->toBeInstanceOf(StringNode::class)
                ->and($value->value)->toBe('--primary');
        });

        it('parses sign-only number tokens as zero', function () {
            $stream = new TokenStream([
                new Token(TokenType::NUMBER, '+', 1, 1),
                new Token(TokenType::EOF, '', 1, 2),
            ]);
            $valueParser = new ValueParser($stream, static fn(string $expression): AstNode => new StringNode($expression));

            $number = $valueParser->parseNumber();

            expect($number)->toBeInstanceOf(NumberNode::class)
                ->and($number->value)->toBe(0)
                ->and($number->unit)->toBeNull();
        });

        it('parses argument lists with leading commas positional variables and spread arguments', function () {
            [$valueParser] = ($this->createValueParser)('(, $first, items ...)');

            $arguments = $valueParser->parseArgumentList();

            expect($arguments)->toHaveCount(2)
                ->and($arguments[0])->toBeInstanceOf(VariableReferenceNode::class)
                ->and($arguments[0]->name)->toBe('first')
                ->and($arguments[1])->toBeInstanceOf(SpreadArgumentNode::class);

            /** @var SpreadArgumentNode $spread */
            $spread = $arguments[1];
            expect($spread->value)->toBeInstanceOf(StringNode::class);

            /** @var StringNode $spreadValue */
            $spreadValue = $spread->value;

            expect($spreadValue->value)->toBe('items');
        });

        it('returns an empty string when consumeIdentifier is called away from identifiers', function () {
            [$valueParser] = ($this->createValueParser)('42');

            expect($valueParser->consumeIdentifier())->toBe('');
        });

        it('returns null for empty grouped values in buildListFromGroups', function () {
            [$valueParser] = ($this->createValueParser)('');

            $result = (new ReflectionAccessor($valueParser))->callMethod('buildListFromGroups', [[]]);

            expect($result)->toBeNull();
        });

        it('restores stream position when tryParseModuleVariable has no member identifier', function () {
            $stream = new TokenStream([
                new Token(TokenType::IDENTIFIER, 'theme', 1, 1),
                new Token(TokenType::DOT, '.', 1, 6),
                new Token(TokenType::DOLLAR, '$', 1, 7),
                new Token(TokenType::EOF, '', 1, 8),
            ]);
            $valueParser = new ValueParser($stream, static fn(string $expression): AstNode => new StringNode($expression));
            $accessor = new ReflectionAccessor($valueParser);

            $result = $accessor->callMethod('tryParseModuleVariable');

            expect($result)->toBeNull()
                ->and($stream->getPosition())->toBe(0);
        });

        it('returns 0 as numeric prefix length for empty strings', function () {
            [$valueParser] = ($this->createValueParser)('');

            $length = (new ReflectionAccessor($valueParser))->callMethod('readNumericPrefixLength', ['']);

            expect($length)->toBe(0);
        });

        it('wraps ambiguous strict unary expressions in a deprecated expression node', function () {
            [$valueParser] = ($this->createValueParser)('$a -$b');

            /** @var DeprecatedExpressionNode $result */
            $result = $valueParser->parseValueUntil([TokenType::EOF]);

            expect($result)->toBeInstanceOf(DeprecatedExpressionNode::class)
                ->and($result->message)->toContain('This expression will be parsed differently in a future release.')
                ->and($result->line)->toBe(1)
                ->and($result->column)->toBe(4)
                ->and($result->expression)->toBeInstanceOf(ListNode::class);

            /** @var ListNode $expression */
            $expression = $result->expression;

            expect($expression->separator)->toBe('space')
                ->and($expression->items)->toHaveCount(3)
                ->and($expression->items[0])->toBeInstanceOf(VariableReferenceNode::class)
                ->and($expression->items[1])->toBeInstanceOf(StringNode::class)
                ->and($expression->items[2])->toBeInstanceOf(VariableReferenceNode::class);
        });
    });

    describe('custom properties', function () {
        it('parses CSS custom property value as raw string', function () {
            $ast  = $this->parser->parse('.a { --color: #f00; }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('--color');
        });

        it('parses custom property with complex value', function () {
            $ast  = $this->parser->parse('.a { --shadow: 0 1px 3px rgba(0, 0, 0, 0.5); }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('--shadow');
        });
    });
});
