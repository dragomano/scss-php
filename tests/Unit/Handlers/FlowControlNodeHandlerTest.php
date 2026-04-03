<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\InvalidLoopBoundaryException;
use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\EachNode;
use Bugo\SCSS\Nodes\ElseIfNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Nodes\WhileNode;
use Tests\RuntimeFactory;

it('handles if branches', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context(indent: 1);
    $node    = new IfNode(
        '1 > 2',
        [new DeclarationNode('color', new StringNode('red'))],
        [new ElseIfNode('2 > 1', [new DeclarationNode('color', new StringNode('blue'))])],
    );

    expect($runtime->flow()->handleIf($node, $ctx))->toBe('  color: blue;');
});

it('handles each for and while loops', function () {
    $runtime = RuntimeFactory::createRuntime();

    $ctx = RuntimeFactory::context(indent: 1);
    $ctx->env->getCurrentScope()->setVariable('i', new NumberNode(0));

    $each = $runtime->flow()->handleEach(
        new EachNode(['item'], new ListNode([new StringNode('a'), new StringNode('b')], 'space'), [
            new DeclarationNode('value', new VariableReferenceNode('item')),
        ]),
        $ctx,
    );

    $for = $runtime->flow()->handleFor(
        new ForNode('i', new NumberNode(1), new NumberNode(2), true, [
            new DeclarationNode('step', new VariableReferenceNode('i')),
        ]),
        $ctx,
    );

    $while = $runtime->flow()->handleWhile(
        new WhileNode('$i < 2', [
            new VariableDeclarationNode('i', new ListNode(
                [new VariableReferenceNode('i'), new StringNode('+'), new NumberNode(1)],
                'space',
            )),
            new DeclarationNode('count', new VariableReferenceNode('i')),
        ]),
        $ctx,
    );

    expect($each)->toBe("  value: a;\n  value: b;")
        ->and($for)->toBe("  step: 1;\n  step: 2;")
        ->and($while)->toBe("  count: 1;\n  count: 2;");
});

it('throws when flow control loops exceed the iteration limit', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    expect(fn() => $runtime->flow()->handleFor(
        new ForNode('i', new NumberNode(1), new NumberNode(10001), true, []),
        $ctx,
    ))->toThrow(MaxIterationsExceededException::class)
        ->and(fn() => $runtime->flow()->handleWhile(
            new WhileNode('true', []),
            $ctx,
        ))->toThrow(MaxIterationsExceededException::class);
});

it('throws for non-numeric loop boundaries', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    expect(fn() => $runtime->flow()->handleFor(
        new ForNode('i', new StringNode('abc'), new NumberNode(2), true, []),
        $ctx,
    ))->toThrow(InvalidLoopBoundaryException::class);
});

it('accepts numeric string loop boundaries', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context(indent: 1);

    $result = $runtime->flow()->handleFor(
        new ForNode('i', new StringNode('1'), new StringNode('2'), true, [
            new DeclarationNode('step', new VariableReferenceNode('i')),
        ]),
        $ctx,
    );

    expect($result)->toBe("  step: 1;\n  step: 2;");
});
