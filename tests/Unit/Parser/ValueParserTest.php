<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Parser;

describe('ValueParser', function () {
    beforeEach(function () {
        $this->parser = new Parser();
    });

    describe('numbers', function () {
        it('parses integer values', function () {
            $ast = $this->parser->parse('.a { z-index: 10; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(NumberNode::class)
                ->and($decl->value->value)->toBe(10)
                ->and($decl->value->unit)->toBeNull();
        });

        it('parses float values', function () {
            $ast = $this->parser->parse('.a { opacity: 0.75; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(NumberNode::class)
                ->and($decl->value->value)->toBe(0.75);
        });

        it('parses negative numbers', function () {
            $ast = $this->parser->parse('.a { margin: -10px; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(NumberNode::class)
                ->and($decl->value->value)->toBe(-10)
                ->and($decl->value->unit)->toBe('px');
        });

        it('parses numbers with various units', function (string $declaration, int|float $expectedValue, string $expectedUnit) {
            $ast = $this->parser->parse(".a { $declaration }");
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
            $ast = $this->parser->parse('.a { display: flex; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(StringNode::class)
                ->and($decl->value->value)->toBe('flex');
        });

        it('parses double-quoted strings', function () {
            $ast = $this->parser->parse('$x: "hello world";');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(StringNode::class)
                ->and($decl->value->value)->toBe('hello world');
        });

        it('parses single-quoted strings', function () {
            $ast = $this->parser->parse('$x: \'hello\';');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(StringNode::class)
                ->and($decl->value->value)->toBe('hello');
        });
    });

    describe('colors', function () {
        it('parses 3-digit hex color', function () {
            $ast = $this->parser->parse('.a { color: #f00; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(ColorNode::class)
                ->and($decl->value->value)->toBe('#f00');
        });

        it('parses 6-digit hex color', function () {
            $ast = $this->parser->parse('.a { color: #ff0000; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(ColorNode::class)
                ->and($decl->value->value)->toBe('#ff0000');
        });
    });

    describe('boolean and null', function () {
        it('parses true', function () {
            $ast = $this->parser->parse('$x: true;');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(BooleanNode::class)
                ->and($decl->value->value)->toBeTrue();
        });

        it('parses false', function () {
            $ast = $this->parser->parse('$x: false;');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(BooleanNode::class)
                ->and($decl->value->value)->toBeFalse();
        });

        it('parses null', function () {
            $ast = $this->parser->parse('$x: null;');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(NullNode::class);
        });
    });

    describe('variable references', function () {
        it('parses variable reference', function () {
            $ast = $this->parser->parse('.a { color: $primary; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(VariableReferenceNode::class)
                ->and($decl->value->name)->toBe('primary');
        });

        it('parses namespaced variable as combined name', function () {
            $ast = $this->parser->parse('@use "colors"; .a { color: colors.$primary; }');
            $decl = $ast->children[1]->children[0];

            // Namespace is encoded as "namespace.varname" in the name property
            expect($decl->value)->toBeInstanceOf(VariableReferenceNode::class)
                ->and($decl->value->name)->toBe('colors.primary');
        });
    });

    describe('lists', function () {
        it('parses comma-separated list', function () {
            $ast = $this->parser->parse('$x: (a, b, c);');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(ListNode::class)
                ->and($decl->value->separator)->toBe('comma')
                ->and(count($decl->value->items))->toBe(3);
        });

        it('parses space-separated list', function () {
            $ast = $this->parser->parse('.a { margin: 10px 20px 10px 20px; }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(ListNode::class)
                ->and($decl->value->separator)->toBe('space')
                ->and(count($decl->value->items))->toBe(4);
        });

        it('parses bracketed list', function () {
            $ast = $this->parser->parse('$x: [a, b, c];');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(ListNode::class)
                ->and($decl->value->bracketed)->toBeTrue()
                ->and(count($decl->value->items))->toBe(3);
        });
    });

    describe('maps', function () {
        it('parses simple map', function () {
            $ast = $this->parser->parse('$x: (key: value, other: thing);');
            $decl = $ast->children[0];

            expect($decl->value)->toBeInstanceOf(MapNode::class)
                ->and(count($decl->value->pairs))->toBe(2);
        });

        it('parses map pair keys and values', function () {
            $ast = $this->parser->parse('$x: (primary: #f00);');
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
            $ast = $this->parser->parse('.a { color: rgb(255, 0, 0); }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(FunctionNode::class)
                ->and($decl->value->name)->toBe('rgb')
                ->and(count($decl->value->arguments))->toBe(3);
        });

        it('parses function call with named arguments', function () {
            $ast = $this->parser->parse('@use "sass:color"; .a { color: color.adjust($c, $red: 10); }');
            $decl = $ast->children[1]->children[0];

            expect($decl->value)->toBeInstanceOf(FunctionNode::class);

            $namedArg = $decl->value->arguments[1];
            expect($namedArg)->toBeInstanceOf(NamedArgumentNode::class)
                ->and($namedArg->name)->toBe('red');
        });

        it('parses CSS var() function', function () {
            $ast = $this->parser->parse('.a { color: var(--primary); }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(FunctionNode::class)
                ->and($decl->value->name)->toBe('var');
        });

        it('parses url() function', function () {
            $ast = $this->parser->parse('.a { background: url(image.png); }');
            $decl = $ast->children[0]->children[0];

            expect($decl->value)->toBeInstanceOf(FunctionNode::class)
                ->and($decl->value->name)->toBe('url');
        });
    });

    describe('modifiers', function () {
        it('parses !important as part of value list in AST', function () {
            // !important is stored as a StringNode("!important") inside the value ListNode at parse time,
            // and gets resolved to DeclarationNode::$important=true during evaluation
            $ast = $this->parser->parse('.a { color: red !important; }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->value)->toBeInstanceOf(ListNode::class);

            $items = $decl->value->items;
            $lastItem = end($items);
            expect($lastItem)->toBeInstanceOf(StringNode::class)
                ->and($lastItem->value)->toBe('!important');
        });

        it('parses !default on variable', function () {
            $ast = $this->parser->parse('$primary: blue !default;');
            $decl = $ast->children[0];

            expect($decl)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($decl->default)->toBeTrue()
                ->and($decl->global)->toBeFalse();
        });

        it('parses !global on variable', function () {
            $ast = $this->parser->parse('.a { $x: 10 !global; }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($decl->global)->toBeTrue();
        });
    });

    describe('custom properties', function () {
        it('parses CSS custom property value as raw string', function () {
            $ast = $this->parser->parse('.a { --color: #f00; }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('--color');
        });

        it('parses custom property with complex value', function () {
            $ast = $this->parser->parse('.a { --shadow: 0 1px 3px rgba(0, 0, 0, 0.5); }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('--shadow');
        });
    });
});
