<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Parser;

describe('RuleParser', function () {
    beforeEach(function () {
        $this->parser = new Parser();
    });

    describe('variable declarations', function () {
        it('parses simple variable declaration', function () {
            $ast = $this->parser->parse('$color: red;');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($node->name)->toBe('color')
                ->and($node->value)->toBeInstanceOf(StringNode::class)
                ->and($node->global)->toBeFalse()
                ->and($node->default)->toBeFalse();
        });

        it('parses variable declaration with !default', function () {
            $ast = $this->parser->parse('$primary: blue !default;');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($node->name)->toBe('primary')
                ->and($node->default)->toBeTrue()
                ->and($node->global)->toBeFalse();
        });

        it('parses variable declaration with !global', function () {
            $ast = $this->parser->parse('.a { $x: 10 !global; }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($node->name)->toBe('x')
                ->and($node->global)->toBeTrue();
        });

        it('parses variable declaration with numeric value', function () {
            $ast = $this->parser->parse('$size: 16px;');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($node->name)->toBe('size')
                ->and($node->value)->toBeInstanceOf(NumberNode::class)
                ->and($node->value->value)->toBe(16)
                ->and($node->value->unit)->toBe('px');
        });

        it('parses multiple variable declarations', function () {
            $ast = $this->parser->parse('$a: 1; $b: 2; $c: 3;');

            expect(count($ast->children))->toBe(3)
                ->and($ast->children[0]->name)->toBe('a')
                ->and($ast->children[1]->name)->toBe('b')
                ->and($ast->children[2]->name)->toBe('c');
        });
    });

    describe('CSS declarations', function () {
        it('parses simple CSS declaration', function () {
            $ast = $this->parser->parse('.a { color: red; }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('color')
                ->and($decl->important)->toBeFalse();
        });

        it('parses declaration with vendor prefix', function () {
            $ast = $this->parser->parse('.a { -webkit-transform: rotate(45deg); }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('-webkit-transform');
        });

        it('parses declaration with !important (stored in value at parse time)', function () {
            // At parse time !important is part of the value list;
            // it becomes DeclarationNode::$important=true only after evaluation
            $ast = $this->parser->parse('.a { color: red !important; }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(DeclarationNode::class);

            $lastItem = end($decl->value->items);
            expect($lastItem)->toBeInstanceOf(StringNode::class)
                ->and($lastItem->value)->toBe('!important');
        });

        it('parses multiple declarations in one rule', function () {
            $ast = $this->parser->parse('.a { color: red; margin: 0; padding: 0; }');
            $children = $ast->children[0]->children;

            expect(count($children))->toBe(3)
                ->and($children[0]->property)->toBe('color')
                ->and($children[1]->property)->toBe('margin')
                ->and($children[2]->property)->toBe('padding');
        });
    });

    describe('CSS rules', function () {
        it('parses class selector rule', function () {
            $ast = $this->parser->parse('.button { display: flex; }');
            $rule = $ast->children[0];

            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('.button');
        });

        it('parses ID selector rule', function () {
            $ast = $this->parser->parse('#header { background: blue; }');
            $rule = $ast->children[0];

            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('#header');
        });

        it('parses element selector rule', function () {
            $ast = $this->parser->parse('body { margin: 0; }');
            $rule = $ast->children[0];

            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('body');
        });

        it('parses compound selector', function () {
            $ast = $this->parser->parse('.btn.primary:hover { color: white; }');
            $rule = $ast->children[0];

            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('.btn.primary:hover');
        });

        it('parses selector list (comma-separated)', function () {
            $ast = $this->parser->parse('h1, h2, h3 { font-weight: bold; }');
            $rule = $ast->children[0];

            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toContain('h1')
                ->and($rule->selector)->toContain('h2')
                ->and($rule->selector)->toContain('h3');
        });

        it('parses descendant combinator', function () {
            $ast = $this->parser->parse('.parent .child { color: red; }');
            $rule = $ast->children[0];

            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('.parent .child');
        });

        it('parses child combinator >', function () {
            $ast = $this->parser->parse('.parent > .child { color: red; }');
            $rule = $ast->children[0];

            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toContain('>');
        });

        it('parses nested rules', function () {
            $ast = $this->parser->parse('.parent { color: red; .child { color: blue; } }');
            $parent = $ast->children[0];
            $child = $parent->children[1];

            expect($parent)->toBeInstanceOf(RuleNode::class)
                ->and($parent->selector)->toBe('.parent')
                ->and($child)->toBeInstanceOf(RuleNode::class)
                ->and($child->selector)->toBe('.child');
        });

        it('parses & parent reference in nested rule', function () {
            $ast = $this->parser->parse('.btn { &:hover { color: red; } }');
            $nested = $ast->children[0]->children[0];

            expect($nested)->toBeInstanceOf(RuleNode::class)
                ->and($nested->selector)->toBe('&:hover');
        });

        it('parses pseudo-class selectors', function () {
            $ast = $this->parser->parse('a:hover { color: red; }');
            $rule = $ast->children[0];

            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('a:hover');
        });

        it('parses pseudo-element selectors', function () {
            $ast = $this->parser->parse('p::before { content: ""; }');
            $rule = $ast->children[0];

            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('p::before');
        });

        it('parses attribute selectors', function () {
            $ast = $this->parser->parse('input[type="text"] { border: none; }');
            $rule = $ast->children[0];

            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toContain('[type=');
        });
    });

    describe('custom properties', function () {
        it('parses CSS custom property declaration', function () {
            $ast = $this->parser->parse('.a { --primary: #ff0000; }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('--primary');
        });

        it('parses custom property with fallback value', function () {
            $ast = $this->parser->parse('.a { --shadow: 0 2px 4px rgba(0,0,0,.5); }');
            $decl = $ast->children[0]->children[0];

            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('--shadow');
        });
    });

    describe('module variable declarations', function () {
        it('parses module variable reassignment', function () {
            $ast = $this->parser->parse('@use "theme"; theme.$color: blue;');
            $node = $ast->children[1];

            expect($node)->toBeInstanceOf(ModuleVarDeclarationNode::class)
                ->and($node->module)->toBe('theme')
                ->and($node->name)->toBe('color');
        });
    });
});
