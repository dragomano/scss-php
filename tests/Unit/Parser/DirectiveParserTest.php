<?php

declare(strict_types=1);

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
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\WarnNode;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\Parser;

describe('DirectiveParser', function () {
    beforeEach(function () {
        $this->parser = new Parser();
    });

    describe('@forward', function () {
        it('parses @forward with show clause', function () {
            $ast = $this->parser->parse('@forward "utils" show $color, mix;');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForwardNode::class)
                ->and($node->path)->toBe('utils')
                ->and($node->visibility)->toBe('show')
                ->and($node->members)->toContain('$color')
                ->and($node->members)->toContain('mix');
        });

        it('parses @forward with hide clause', function () {
            $ast = $this->parser->parse('@forward "utils" hide $internal;');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForwardNode::class)
                ->and($node->visibility)->toBe('hide')
                ->and($node->members)->toContain('$internal');
        });

        it('parses @forward with as prefix', function () {
            $ast = $this->parser->parse('@forward "utils" as u-*;');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForwardNode::class)
                ->and($node->prefix)->toBe('u-');
        });

        it('parses @forward without options', function () {
            $ast = $this->parser->parse('@forward "utils";');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForwardNode::class)
                ->and($node->path)->toBe('utils')
                ->and($node->visibility)->toBeNull()
                ->and($node->prefix)->toBeNull();
        });
    });

    describe('@mixin', function () {
        it('parses mixin without parameters', function () {
            $ast = $this->parser->parse('@mixin clearfix { content: ""; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(MixinNode::class)
                ->and($node->name)->toBe('clearfix')
                ->and($node->arguments)->toBe([]);
        });

        it('parses mixin with parameters', function () {
            $ast = $this->parser->parse('@mixin flex($dir: row, $wrap: nowrap) { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(MixinNode::class)
                ->and($node->name)->toBe('flex')
                ->and(count($node->arguments))->toBe(2);
        });

        it('parses mixin with content block', function () {
            $ast = $this->parser->parse('@mixin hover { &:hover { @content; } }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(MixinNode::class)
                ->and($node->name)->toBe('hover');
        });
    });

    describe('@include', function () {
        it('parses include without arguments', function () {
            $ast = $this->parser->parse('.a { @include clearfix; }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(IncludeNode::class)
                ->and($node->name)->toBe('clearfix')
                ->and($node->namespace)->toBeNull();
        });

        it('parses include with positional arguments', function () {
            $ast = $this->parser->parse('.a { @include flex(column, wrap); }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(IncludeNode::class)
                ->and(count($node->arguments))->toBe(2);
        });

        it('parses namespaced include', function () {
            $ast = $this->parser->parse('@use "lib"; .a { @include lib.mixin; }');
            $node = $ast->children[1]->children[0];

            expect($node)->toBeInstanceOf(IncludeNode::class)
                ->and($node->name)->toBe('mixin')
                ->and($node->namespace)->toBe('lib');
        });
    });

    describe('@function', function () {
        it('parses function declaration without parameters', function () {
            $ast = $this->parser->parse('@function pi() { @return 3.14159; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(FunctionDeclarationNode::class)
                ->and($node->name)->toBe('pi')
                ->and($node->arguments)->toBe([]);
        });

        it('parses function with parameters', function () {
            $ast = $this->parser->parse('@function double($n) { @return $n * 2; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(FunctionDeclarationNode::class)
                ->and($node->name)->toBe('double')
                ->and(count($node->arguments))->toBe(1);
        });

        it('parses function with default parameter', function () {
            $ast = $this->parser->parse('@function pad($n, $min: 0) { @return $n; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(FunctionDeclarationNode::class)
                ->and(count($node->arguments))->toBe(2);
        });
    });

    describe('@return', function () {
        it('parses @return inside function body', function () {
            $ast = $this->parser->parse('@function f() { @return 42; }');
            $returnNode = $ast->children[0]->body[0];

            expect($returnNode)->toBeInstanceOf(ReturnNode::class);
        });
    });

    describe('@extend', function () {
        it('parses extend directive with selector', function () {
            $ast = $this->parser->parse('.error { @extend .alert; }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(ExtendNode::class)
                ->and($node->selector)->toBe('.alert');
        });

        it('parses extend with class selector', function () {
            $ast = $this->parser->parse('.button--primary { @extend .button; }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(ExtendNode::class)
                ->and($node->selector)->toBe('.button');
        });
    });

    describe('@at-root', function () {
        it('parses @at-root without query', function () {
            $ast = $this->parser->parse('.parent { @at-root .child { color: red; } }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(AtRootNode::class)
                ->and($node->queryMode)->toBeNull();
        });

        it('parses @at-root with without query', function () {
            $ast = $this->parser->parse('.a { @at-root (without: media) { color: red; } }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(AtRootNode::class)
                ->and($node->queryMode)->toBe('without')
                ->and($node->queryRules)->toContain('media');
        });

        it('parses @at-root with with query', function () {
            $ast = $this->parser->parse('.a { @at-root (with: rule) { color: red; } }');
            $node = $ast->children[0]->children[0];

            expect($node)->toBeInstanceOf(AtRootNode::class)
                ->and($node->queryMode)->toBe('with');
        });
    });

    describe('@if / @else', function () {
        it('parses simple @if', function () {
            $ast = $this->parser->parse('@if true { color: red; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(IfNode::class)
                ->and($node->condition)->toBe('true');
        });

        it('parses @if with @else branch', function () {
            $ast = $this->parser->parse('@if $x { color: red; } @else { color: blue; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(IfNode::class)
                ->and($node->elseBody)->not->toBeEmpty();
        });

        it('parses @if with @else if chain', function () {
            $ast = $this->parser->parse('@if $a { } @else if $b { } @else { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(IfNode::class)
                ->and($node->elseIfBranches)->not->toBeEmpty();
        });

        it('parses @if with complex condition', function () {
            $ast = $this->parser->parse('@if $x > 0 and $y < 10 { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(IfNode::class)
                ->and($node->condition)->toContain('$x');
        });
    });

    describe('@for', function () {
        it('parses @for from...to (exclusive)', function () {
            $ast = $this->parser->parse('@for $i from 1 to 5 { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForNode::class)
                ->and($node->variable)->toBe('i')
                ->and($node->inclusive)->toBeFalse();
        });

        it('parses @for from...through (inclusive)', function () {
            $ast = $this->parser->parse('@for $i from 1 through 5 { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(ForNode::class)
                ->and($node->variable)->toBe('i')
                ->and($node->inclusive)->toBeTrue();
        });
    });

    describe('@each', function () {
        it('parses @each over simple list', function () {
            $ast = $this->parser->parse('@each $color in red, green, blue { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(EachNode::class)
                ->and($node->variables)->toBe(['color']);
        });

        it('parses @each with multiple variables (map destructuring)', function () {
            $ast = $this->parser->parse('@each $key, $value in $map { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(EachNode::class)
                ->and($node->variables)->toBe(['key', 'value']);
        });
    });

    describe('@while', function () {
        it('parses @while with condition', function () {
            $ast = $this->parser->parse('@while $i > 0 { }');
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
            $ast = $this->parser->parse('@supports (display: grid) { .a { display: grid; } }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(SupportsNode::class)
                ->and($node->condition)->toContain('display: grid');
        });

        it('parses @supports with not condition', function () {
            $ast = $this->parser->parse('@supports not (display: grid) { }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(SupportsNode::class)
                ->and($node->condition)->toContain('not');
        });
    });

    describe('generic / unknown directives', function () {
        it('parses unknown block directive as DirectiveNode', function () {
            $ast = $this->parser->parse('@unknown-rule custom-param { color: red; }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(DirectiveNode::class)
                ->and($node->name)->toBe('unknown-rule');
        });

        it('parses @keyframes as DirectiveNode', function () {
            $ast = $this->parser->parse('@keyframes slide { from { opacity: 0; } to { opacity: 1; } }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(DirectiveNode::class)
                ->and($node->name)->toBe('keyframes')
                ->and($node->hasBlock)->toBeTrue();
        });

        it('parses @media as DirectiveNode', function () {
            $ast = $this->parser->parse('@media (max-width: 768px) { .a { display: none; } }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(DirectiveNode::class)
                ->and($node->name)->toBe('media');
        });

        it('parses @layer as DirectiveNode', function () {
            $ast = $this->parser->parse('@layer utilities { .a { color: red; } }');
            $node = $ast->children[0];

            expect($node)->toBeInstanceOf(DirectiveNode::class)
                ->and($node->name)->toBe('layer');
        });
    });
});
