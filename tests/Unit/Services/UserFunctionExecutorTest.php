<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\EachNode;
use Bugo\SCSS\Nodes\ElseIfNode;
use Bugo\SCSS\Nodes\ErrorNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\ReturnNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Nodes\WarnNode;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\Runtime\CallableDefinition;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\UserFunctionExecutor;
use Tests\RuntimeFactory;

describe('UserFunctionExecutor', function () {
    beforeEach(function () {
        $this->diagnostics = [];

        $evaluateValue = function (AstNode $node, Environment $env): AstNode {
            if ($node instanceof VariableReferenceNode) {
                $resolved = $env->getCurrentScope()->getAstVariable($node->name);

                return $resolved ?? new StringNode('');
            }

            return $node;
        };

        $this->executor = new UserFunctionExecutor(
            RuntimeFactory::createRuntime()->condition(),
            $evaluateValue,
            static fn(AstNode $statement, Environment $env): bool => false,
            static fn(AstNode $iterable): array => $iterable instanceof ListNode ? $iterable->items : [$iterable],
            static function (array $variables, AstNode $item, Environment $env): void {
                $env->getCurrentScope()->setVariable($variables[0] ?? 'item', $item);
            },
            $evaluateValue,
            function (string $kind, AstNode $message, Environment $env, ?AstNode $statement): void {
                $this->diagnostics[] = $kind;
            },
        );
    });

    it('returns from each loops when the body yields a result', function () {
        $env = new Environment();
        $function = new CallableDefinition([], [
            new EachNode(['item'], new ListNode([new StringNode('a')]), [
                new ReturnNode(new StringNode('done')),
            ]),
        ], $env->getCurrentScope(), 1);

        $result = $this->executor->executeDefinition('each-fn', $function, [], [], $env);

        expect($result)->toBeInstanceOf(StringNode::class);

        if (! $result instanceof StringNode) {
            throw new RuntimeException('Expected result to be StringNode.');
        }

        expect($result->value)->toBe('done');
    });

    it('adjusts exclusive for-loop upper bounds and throws after too many iterations', function () {
        $env = new Environment();
        $function = new CallableDefinition([], [
            new ForNode('i', new NumberNode(1), new NumberNode(10002), false, []),
        ], $env->getCurrentScope(), 1);

        expect(fn() => $this->executor->executeDefinition('for-fn', $function, [], [], $env))
            ->toThrow(MaxIterationsExceededException::class);
    });

    it('returns from for loops when the body yields a result', function () {
        $env = new Environment();
        $function = new CallableDefinition([], [
            new ForNode('i', new NumberNode(1), new NumberNode(2), true, [
                new ReturnNode(new StringNode('loop-result')),
            ]),
        ], $env->getCurrentScope(), 1);

        $result = $this->executor->executeDefinition('for-return', $function, [], [], $env);

        expect($result)->toBeInstanceOf(StringNode::class);

        if (! $result instanceof StringNode) {
            throw new RuntimeException('Expected result to be StringNode.');
        }

        expect($result->value)->toBe('loop-result');
    });

    it('throws after too many while-loop iterations', function () {
        $env = new Environment();
        $function = new CallableDefinition([], [
            new WhileNode('true', []),
        ], $env->getCurrentScope(), 1);

        expect(fn() => $this->executor->executeDefinition('while-fn', $function, [], [], $env))
            ->toThrow(MaxIterationsExceededException::class);
    });

    it('returns from while loops when the body yields a result', function () {
        $env = new Environment();
        $function = new CallableDefinition([], [
            new WhileNode('true', [
                new ReturnNode(new StringNode('while-result')),
            ]),
        ], $env->getCurrentScope(), 1);

        $result = $this->executor->executeDefinition('while-return', $function, [], [], $env);

        expect($result)->toBeInstanceOf(StringNode::class);

        if (! $result instanceof StringNode) {
            throw new RuntimeException('Expected result to be StringNode.');
        }

        expect($result->value)->toBe('while-result');
    });

    it('handles warn and error diagnostics inside user function bodies', function () {
        $env = new Environment();
        $function = new CallableDefinition([], [
            new WarnNode(new StringNode('careful')),
            new ErrorNode(new StringNode('fatal')),
            new ReturnNode(new StringNode('done')),
        ], $env->getCurrentScope(), 1);

        $result = $this->executor->executeDefinition('diag-fn', $function, [], [], $env);

        expect($result)->toBeInstanceOf(StringNode::class);

        if (! $result instanceof StringNode) {
            throw new RuntimeException('Expected result to be StringNode.');
        }

        expect($this->diagnostics)->toBe(['warn', 'error']);
    });

    it('returns from executed else-if branches', function () {
        $env = new Environment();
        $function = new CallableDefinition([], [
            new IfNode('false', [], [
                new ElseIfNode('true', [
                    new ReturnNode(new StringNode('elseif-result')),
                ]),
            ]),
        ], $env->getCurrentScope(), 1);

        $result = $this->executor->executeDefinition('if-fn', $function, [], [], $env);

        expect($result)->toBeInstanceOf(StringNode::class);

        if (! $result instanceof StringNode) {
            throw new RuntimeException('Expected result to be StringNode.');
        }

        expect($result->value)->toBe('elseif-result');
    });

    it('builds rest argument lists using only unmatched named arguments', function () {
        $scope = (new Environment())->getCurrentScope();

        $this->executor->bindParametersToCurrentScope(
            [
                new ArgumentNode('first'),
                new ArgumentNode('rest', rest: true),
            ],
            [new StringNode('positional'), new StringNode('tail')],
            [
                'first' => new StringNode('named-first'),
                'extra' => new StringNode('named-extra'),
            ],
            $scope,
        );

        $rest = $scope->getAstVariable('rest');

        expect($rest)->toBeInstanceOf(ArgumentListNode::class);

        if (! $rest instanceof ArgumentListNode) {
            throw new RuntimeException('Expected rest value to be ArgumentListNode.');
        }

        expect($rest->items)->toHaveCount(1)
            ->and($rest->keywords)->toHaveKey('extra')
            ->and($rest->keywords)->not->toHaveKey('first');
    });

    it('uses zero as loop boundary when evaluated bounds are not numbers', function () {
        $env = new Environment();
        $function = new CallableDefinition([], [
            new ForNode('i', new StringNode('oops'), new NumberNode(0), true, [
                new ReturnNode(new VariableReferenceNode('i')),
            ]),
        ], $env->getCurrentScope(), 1);

        $result = $this->executor->executeDefinition('fallback-boundary', $function, [], [], $env);

        expect($result)->toBeInstanceOf(NumberNode::class);

        if (! $result instanceof NumberNode) {
            throw new RuntimeException('Expected result to be NumberNode.');
        }

        expect($result->value)->toBe(0);
    });
});
