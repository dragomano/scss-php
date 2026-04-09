<?php

declare(strict_types=1);

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
use Tests\ReflectionAccessor;
use Tests\RuntimeFactory;

describe('Condition', function () {
    beforeEach(function () {
        $this->runtime   = RuntimeFactory::createRuntime();
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
        $ctx = (new ReflectionAccessor($this->runtime))->getProperty('ctx');
        $ctx->conditionCacheState->parsed['1 < 2'] = [
            'type'     => 'comparison',
            'left'     => '1',
            'operator' => '<',
            'right'    => '2',
        ];

        $parsed = (new ReflectionAccessor($this->condition))->callMethod('parse', [' 1 < 2 ']);

        expect($parsed['type'])->toBe('comparison')
            ->and($parsed['left'])->toBe('1')
            ->and($ctx->conditionCacheState->parsed[' 1 < 2 '])->toBe($ctx->conditionCacheState->parsed['1 < 2'])
            ->and($this->condition->evaluate('   ', $this->env))->toBeFalse()
            ->and($ctx->conditionCacheState->parsed[''])->toBe(['type' => 'empty'])
            ->and($this->condition->evaluate('(1 < 2)', $this->env))->toBeTrue()
            ->and($ctx->conditionCacheState->parsed['(1 < 2)']['type'])->toBe('comparison');
    });

    it('parses top-level or conditions and falls back to leaf values when operators stay nested', function () {
        $accessor = new ReflectionAccessor($this->condition);
        $orNode   = $accessor->callMethod('parse', ['1 < 0 or 2 < 3']);
        $leafNode = $accessor->callMethod('parse', ['fn(1 or 2)']);

        expect($orNode['type'])->toBe('or')
            ->and($orNode['items'])->toHaveCount(2)
            ->and($leafNode)->toBe([
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
            ->and($this->condition->compare(new NumberNode(1.12345678906), '==', new NumberNode(1.1234567891), $this->env))->toBeTrue();
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

    it('evaluates compareNumbers branches for incompatible and equal numeric values directly', function () {
        $accessor = new ReflectionAccessor($this->condition);

        expect($accessor->callMethod('compareNumbers', [
            new NumberNode(1, 'px'),
            '!=',
            new NumberNode(1, 'deg'),
        ]))->toBeTrue()
            ->and($accessor->callMethod('compareNumbers', [
                new NumberNode(1, 'px'),
                '==',
                new NumberNode(1, 'deg'),
            ]))->toBeFalse()
            ->and($accessor->callMethod('compareNumbers', [
                new NumberNode(2),
                '==',
                new NumberNode(2),
            ]))->toBeTrue()
            ->and($accessor->callMethod('compareNumbers', [
                new NumberNode(2),
                '!=',
                new NumberNode(2),
            ]))->toBeFalse();
    });

    it('evaluates direct scalar compareValues equality branches', function () {
        $accessor = new ReflectionAccessor($this->condition);

        expect($accessor->callMethod('compareValues', ['same', '==', 'same']))->toBeTrue()
            ->and($accessor->callMethod('compareValues', ['same', '!=', 'same']))->toBeFalse();
    });

    it('resolves literal values and caches split comparisons', function () {
        $accessor = new ReflectionAccessor($this->condition);
        $ctx      = (new ReflectionAccessor($this->runtime))->getProperty('ctx');

        $empty            = $accessor->callMethod('resolveLiteralValue', ['']);
        $hex              = $accessor->callMethod('resolveLiteralValue', ['#AbCd']);
        $comparison       = $accessor->callMethod('splitComparison', ['foo >= bar']);
        $cachedComparison = $accessor->callMethod('splitComparison', ['foo >= bar']);

        expect($empty)->toBeInstanceOf(StringNode::class)
            ->and($empty->value)->toBe('')
            ->and($hex)->toBeInstanceOf(ColorNode::class)
            ->and($hex->value)->toBe('#AbCd')
            ->and($comparison)->toBe(['foo', '>=', 'bar'])
            ->and($cachedComparison)->toBe($comparison)
            ->and($ctx->conditionCacheState->comparison['foo >= bar'])->toBe($comparison);
    });

    it('handles number and color literal edge cases', function () {
        $accessor = new ReflectionAccessor($this->condition);

        expect($accessor->callMethod('parseNumberLiteral', ['']))->toBeNull()
            ->and($accessor->callMethod('parseNumberLiteral', ['+']))->toBeNull()
            ->and($accessor->callMethod('parseNumberLiteral', ['1.25em']))->toBe(['1.25', 'em'])
            ->and($accessor->callMethod('parseNumberLiteral', ['1px2']))->toBeNull()
            ->and($accessor->callMethod('isHexColorLiteral', ['#abcd']))->toBeTrue()
            ->and($accessor->callMethod('isHexColorLiteral', ['#12']))->toBeFalse()
            ->and($accessor->callMethod('isHexColorLiteral', ['#xyz']))->toBeFalse();
    });

    it('resolves parsed function-like values and falls back when reparsing does not yield a declaration', function () {
        $accessor = new ReflectionAccessor($this->condition);

        $resolvedFunction = $accessor->callMethod('resolveValue', ['calc(1px + 2px)', $this->env]);

        expect($resolvedFunction)->toBeInstanceOf(NumberNode::class)
            ->and($resolvedFunction->value)->toBe(3.0)
            ->and($resolvedFunction->unit)->toBe('px');

        $parser = new class implements ParserInterface {
            public function setTrackSourceLocations(bool $track): void {}

            public function parse(string $source): RootNode
            {
                return new RootNode([new StringNode('not-a-rule')]);
            }
        };

        $runtime   = RuntimeFactory::createRuntime(parser: $parser);
        $condition = $runtime->condition();
        $accessor  = new ReflectionAccessor($condition);
        $fallback  = $accessor->callMethod('resolveValue', ['func(test)', new Environment()]);

        expect($fallback)->toBeInstanceOf(StringNode::class)
            ->and($fallback->value)->toBe('func(test)');
    });
});
