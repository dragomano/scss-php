<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Runtime\Environment;
use Tests\RuntimeFactory;

describe('CompilerRuntime', function () {
    it('uses slash-division evaluation when applying variable declarations during extend collection', function () {
        $runtime = RuntimeFactory::createRuntime();
        $env     = new Environment();

        $runtime->extends()->collectExtends(new IfNode('true', [
            new VariableDeclarationNode('size', new ListNode([
                new NumberNode(10),
                new StringNode('/'),
                new NumberNode(2),
            ], 'space')),
        ]), $env);

        /* @var $value NumberNode::class */
        $value = $env->getCurrentScope()->getAstVariable('size');

        expect($value)->toBeInstanceOf(NumberNode::class)
            ->and($value->value)->toBe(5.0)
            ->and($value->unit)->toBeNull();
    });
});
