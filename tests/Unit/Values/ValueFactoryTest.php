<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\ModuleRefNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Values\SassBoolean;
use Bugo\SCSS\Values\SassMap;
use Bugo\SCSS\Values\SassModule;
use Bugo\SCSS\Values\SassString;
use Bugo\SCSS\Values\ValueFactory;

describe('ValueFactory', function () {
    beforeEach(function () {
        $this->factory = new ValueFactory();
    });

    it('uses formatter for unsupported ast nodes', function () {
        $node = new class extends AstNode {};

        $value = $this->factory->fromAst($node, static fn(AstNode $node): string => $node::class);

        expect($value)->toBeInstanceOf(SassString::class)
            ->and($value->toCss())->toContain('AstNode@anonymous');
    });

    it('returns true for unsupported ast nodes without formatter', function () {
        $value = $this->factory->fromAst(new class extends AstNode {});

        expect($value)->toBeInstanceOf(SassBoolean::class)
            ->and($value->toCss())->toBe('true')
            ->and($value->isTruthy())->toBeTrue();
    });

    it('keeps callable names without namespace separator and trims qualified names', function () {
        $scope = new Scope();

        $function = $this->factory->fromAst(new FunctionNode('lighten', capturedScope: $scope));
        $mixin    = $this->factory->fromAst(new MixinRefNode('theme.button'));

        expect($function->toCss())->toBe('get-function("lighten")')
            ->and($mixin->toCss())->toBe('get-mixin("button")');
    });

    it('converts map ast nodes to sass maps with recursive pair values', function () {
        $node = new MapNode([
            new MapPair(new StringNode('width'), new NumberNode(10, 'px')),
            new MapPair(new StringNode('nested'), new MapNode([
                new MapPair(new StringNode('color'), new StringNode('red')),
            ])),
        ]);

        /** @var SassMap $value */
        $value = $this->factory->fromAst($node);

        expect($value)->toBeInstanceOf(SassMap::class)
            ->and($value->toCss())->toBe('(width: 10px, nested: (color: red))');
    });

    it('converts a builtin module reference into a named module value', function () {
        $value = $this->factory->fromAst(new ModuleRefNode(builtinName: 'meta'));

        expect($value)->toBeInstanceOf(SassModule::class)
            ->and($value->toCss())->toBe('get-module("meta")');
    });

    it('converts a user module reference into an unnamed module value', function () {
        $value = $this->factory->fromAst(new ModuleRefNode(scope: new Scope()));

        expect($value)->toBeInstanceOf(SassModule::class)
            ->and($value->toCss())->toBe('get-module()');
    });
});
