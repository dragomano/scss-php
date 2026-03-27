<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Values\SassBoolean;
use Bugo\SCSS\Values\SassString;
use Bugo\SCSS\Values\ValueFactory;

describe('ValueFactory', function () {
    beforeEach(function () {
        $this->factory = new ValueFactory();
    });

    it('uses formatter for unsupported ast nodes', function () {
        $node = new class () extends AstNode {};

        $value = $this->factory->fromAst($node, static fn(AstNode $node): string => $node::class);

        expect($value)->toBeInstanceOf(SassString::class)
            ->and($value->toCss())->toContain('AstNode@anonymous');
    });

    it('returns true for unsupported ast nodes without formatter', function () {
        $value = $this->factory->fromAst(new class () extends AstNode {});

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
});
