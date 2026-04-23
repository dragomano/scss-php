<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Tests\RuntimeFactory;

describe('Condition', function () {
    beforeEach(function () {
        $this->ctx       = new CompilerContext();
        $this->runtime   = RuntimeFactory::createRuntime(context: $this->ctx);
        $this->condition = $this->runtime->condition();
        $this->env       = new Environment();
    });

    it('evaluates boolean expressions and compares values', function () {
        expect($this->condition->evaluate('1 < 2 and 3 > 1', $this->env))->toBeTrue()
            ->and($this->condition->compare(new NumberNode(2), '>=', new NumberNode(1), $this->env))->toBeTrue()
            ->and($this->condition->isTruthy($this->runtime->evaluation()->createBooleanNode(false)))->toBeFalse();
    });

    it('caches top level operator splits', function () {
        $first  = $this->condition->splitTopLevelByOperator('a and (b and c)', 'and');
        $second = $this->condition->splitTopLevelByOperator('a and (b and c)', 'and');

        expect($first)->toBe(['a', '(b and c)'])
            ->and($second)->toBe($first);
    });

    it('reuses normalized cached conditions and handles empty or wrapped inputs', function () {
        $this->ctx->conditionCacheState->parsed['1 < 2'] = [
            'type'     => 'comparison',
            'left'     => '1',
            'operator' => '<',
            'right'    => '2',
        ];

        expect($this->condition->evaluate(' 1 < 2 ', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->parsed[' 1 < 2 ']['type'])->toBe('comparison')
            ->and($this->ctx->conditionCacheState->parsed[' 1 < 2 ']['left'])->toBe('1')
            ->and($this->ctx->conditionCacheState->parsed[' 1 < 2 '])->toBe($this->ctx->conditionCacheState->parsed['1 < 2'])
            ->and($this->condition->evaluate('   ', $this->env))->toBeFalse()
            ->and($this->ctx->conditionCacheState->parsed[''])->toBe(['type' => 'empty'])
            ->and($this->condition->evaluate('(1 < 2)', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->parsed['(1 < 2)']['type'])->toBe('comparison');
    });

    it('parses top-level or conditions and falls back to leaf values when operators stay nested', function () {
        expect($this->condition->evaluate('1 < 0 or 2 < 3', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->parsed['1 < 0 or 2 < 3']['type'])->toBe('or')
            ->and($this->ctx->conditionCacheState->parsed['1 < 0 or 2 < 3']['items'])->toHaveCount(2)
            ->and($this->condition->evaluate('fn(1 or 2)', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->parsed['fn(1 or 2)'])->toBe([
                'type' => 'value',
                'raw'  => 'fn(1 or 2)',
            ]);
    });

    it('evaluates parsed empty and or conditions defensively', function () {
        expect($this->condition->evaluate('', $this->env))->toBeFalse()
            ->and($this->condition->evaluate('() or ()', $this->env))->toBeFalse()
            ->and($this->condition->evaluate('() or 1', $this->env))->toBeTrue();
    });

    it('compares booleans colors lists maps and function identities for equality', function () {
        $trueNode       = $this->runtime->evaluation()->createBooleanNode(true);
        $falseNode      = $this->runtime->evaluation()->createBooleanNode(false);
        $sharedFunction = new FunctionNode('rgb', [new NumberNode(255), new NumberNode(0), new NumberNode(0)]);

        expect($this->condition->compare($trueNode, '==', $trueNode, $this->env))->toBeTrue()
            ->and($this->condition->compare($trueNode, '==', $falseNode, $this->env))->toBeFalse()
            ->and($this->condition->compare(new ColorNode('#ABC'), '==', new ColorNode('#abc'), $this->env))->toBeTrue()
            ->and($this->condition->compare(
                new ListNode([new StringNode('a'), new NumberNode(1)], 'space'),
                '==',
                new ListNode([new StringNode('a'), new NumberNode(1)], 'space'),
                $this->env,
            ))->toBeTrue()
            ->and($this->condition->compare(
                new ListNode([new StringNode('a')], 'space', true),
                '==',
                new ListNode([new StringNode('a')], 'space', false),
                $this->env,
            ))->toBeFalse()
            ->and($this->condition->compare(
                new MapNode([new MapPair(new StringNode('a'), new NumberNode(1))]),
                '==',
                new MapNode([new MapPair(new StringNode('a'), new NumberNode(1))]),
                $this->env,
            ))->toBeTrue()
            ->and($this->condition->compare(
                new MapNode([new MapPair(new StringNode('a'), new NumberNode(1))]),
                '==',
                new MapNode([new MapPair(new StringNode('a'), new NumberNode(2))]),
                $this->env,
            ))->toBeFalse()
            ->and($this->condition->compare($sharedFunction, '==', $sharedFunction, $this->env))->toBeTrue()
            ->and($this->condition->compare(
                new FunctionNode('rgb', [new NumberNode(255), new NumberNode(0), new NumberNode(0)]),
                '==',
                new FunctionNode('rgb', [new NumberNode(255), new NumberNode(0), new NumberNode(0)]),
                $this->env,
            ))->toBeFalse();
    });

    it('compares numbers with unit guards and conversions', function () {
        expect($this->condition->compare(new NumberNode(1, 'px'), '==', new NumberNode(1), $this->env))->toBeFalse()
            ->and($this->condition->compare(new NumberNode(1, 'px'), '==', new NumberNode(1, 'deg'), $this->env))->toBeFalse()
            ->and($this->condition->compare(new NumberNode(2), '>', new NumberNode(1), $this->env))->toBeTrue()
            ->and($this->condition->compare(new NumberNode(10, 'mm'), '==', new NumberNode(1, 'cm'), $this->env))->toBeTrue()
            ->and(fn() => $this->condition->compare(new NumberNode(1, 'px'), '>', new NumberNode(1, 'deg'), $this->env))
            ->toThrow(IncompatibleUnitsException::class);
    });

    it('compares scalar values and normalizes numeric precision', function () {
        expect($this->condition->compare(new StringNode('b'), '>', new StringNode('a'), $this->env))->toBeTrue()
            ->and($this->condition->compare(new StringNode('a'), '<', new StringNode('b'), $this->env))->toBeTrue()
            ->and($this->condition->compare(new StringNode('same'), '==', new StringNode('same'), $this->env))->toBeTrue()
            ->and($this->condition->compare(new StringNode('same'), '!=', new StringNode('same'), $this->env))->toBeFalse()
            // Numbers within 1e-11 fuzzy-equal range (differ by 4e-12 < 5e-12): equal
            ->and($this->condition->compare(new NumberNode(1.000000000004), '==', new NumberNode(1.0), $this->env))->toBeTrue()
            // Numbers outside 1e-11 range (differ by 4e-11): not equal per spec
            ->and($this->condition->compare(new NumberNode(1.12345678906), '==', new NumberNode(1.1234567891), $this->env))->toBeFalse();
    });

    it('returns false for list and map mismatches at each comparison guard', function () {
        expect($this->condition->compare(
            new ListNode([new StringNode('a')], 'space'),
            '==',
            new ListNode([new StringNode('a')], 'comma'),
            $this->env,
        ))->toBeFalse()
            ->and($this->condition->compare(
                new ListNode([new StringNode('a')], 'space'),
                '==',
                new ListNode([new StringNode('a'), new StringNode('b')], 'space'),
                $this->env,
            ))->toBeFalse()
            ->and($this->condition->compare(
                new ListNode([new StringNode('a')], 'space'),
                '==',
                new ListNode([new StringNode('b')], 'space'),
                $this->env,
            ))->toBeFalse()
            ->and($this->condition->compare(
                new MapNode([new MapPair(new StringNode('a'), new NumberNode(1))]),
                '==',
                new MapNode([
                    new MapPair(new StringNode('a'), new NumberNode(1)),
                    new MapPair(new StringNode('b'), new NumberNode(2)),
                ]),
                $this->env,
            ))->toBeFalse()
            ->and($this->condition->compare(
                new MapNode([new MapPair(new StringNode('a'), new NumberNode(1))]),
                '==',
                new MapNode([new MapPair(new StringNode('b'), new NumberNode(1))]),
                $this->env,
            ))->toBeFalse();
    });

    it('handles numeric inequality branches for incompatible and compatible values', function () {
        expect($this->condition->compare(new NumberNode(1, 'px'), '!=', new NumberNode(1, 'deg'), $this->env))->toBeTrue()
            ->and($this->condition->compare(new NumberNode(2), '==', new NumberNode(2), $this->env))->toBeTrue()
            ->and($this->condition->compare(new NumberNode(2), '!=', new NumberNode(2), $this->env))->toBeFalse();
    });

    it('resolves literal values and caches split comparisons', function () {
        $this->ctx->conditionCacheState->comparison['foo >= bar'] = ['foo', '>=', 'bar'];

        expect($this->condition->evaluate('#AbCd', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->literalValue['#AbCd'])->toBeInstanceOf(ColorNode::class)
            ->and($this->ctx->conditionCacheState->literalValue['#AbCd']->value)->toBe('#AbCd')
            ->and($this->condition->evaluate('foo >= bar', $this->env))->toBeTrue()
            ->and($this->condition->evaluate('foo >= bar', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->comparison['foo >= bar'])->toBe(['foo', '>=', 'bar']);
    });

    it('ignores incomplete comparison operands and caches the miss', function () {
        expect($this->condition->evaluate('>= 10', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->comparison['>= 10'])->toBeNull();
    });

    it('handles number and color literal edge cases', function () {
        $this->ctx->conditionCacheState->parsed['cached-empty'] = [
            'type' => 'value',
            'raw'  => '',
        ];

        expect($this->condition->evaluate('+', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->literalValue['+'])->toBeInstanceOf(StringNode::class)
            ->and($this->condition->evaluate('1.25em', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->literalValue['1.25em'])->toBeInstanceOf(NumberNode::class)
            ->and($this->ctx->conditionCacheState->literalValue['1.25em']->value)->toBe(1.25)
            ->and($this->ctx->conditionCacheState->literalValue['1.25em']->unit)->toBe('em')
            ->and($this->condition->evaluate('cached-empty', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->literalValue[''])->toBeInstanceOf(StringNode::class)
            ->and($this->ctx->conditionCacheState->literalValue['']->value)->toBe('')
            ->and($this->condition->evaluate('1px2', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->literalValue['1px2'])->toBeInstanceOf(StringNode::class)
            ->and($this->condition->evaluate('#abcd', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->literalValue['#abcd'])->toBeInstanceOf(ColorNode::class)
            ->and($this->condition->evaluate('#12', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->literalValue['#12'])->toBeInstanceOf(StringNode::class)
            ->and($this->condition->evaluate('#xyz', $this->env))->toBeTrue()
            ->and($this->ctx->conditionCacheState->literalValue['#xyz'])->toBeInstanceOf(StringNode::class);
    });

    it('resolves parsed function-like values and falls back when reparsing does not yield a declaration', function () {
        expect($this->condition->evaluate('calc(1px + 2px) == 3px', $this->env))->toBeTrue();

        $parser = new class implements ParserInterface {
            public function setTrackSourceLocations(bool $track): void {}

            public function parse(string $source): RootNode
            {
                return new RootNode([new StringNode('not-a-rule')]);
            }
        };

        $ctx       = new CompilerContext();
        $runtime   = RuntimeFactory::createRuntime(context: $ctx, parser: $parser);
        $condition = $runtime->condition();
        $env       = new Environment();

        expect($condition->evaluate('func(test)', $env))->toBeTrue()
            ->and($ctx->conditionCacheState->literalValue['func(test)'])->toBeInstanceOf(StringNode::class)
            ->and($ctx->conditionCacheState->literalValue['func(test)']->value)->toBe('func(test)');
    });
});
