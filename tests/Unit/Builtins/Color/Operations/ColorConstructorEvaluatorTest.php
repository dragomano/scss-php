<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\ColorModuleFactory;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;

describe('ColorConstructorEvaluator', function () {
    beforeEach(function () {
        $context = new ColorModuleContext(
            errorCtx: static fn(string $function): string => $function,
            isGlobalBuiltinCall: static fn(): bool => false,
            warn: static function (?BuiltinCallContext $callContext, string $message): void {},
        );

        $this->constructors = (new ColorModuleFactory())->create($context)->constructors;
    });

    describe('rgbaFunction', function () {
        it('builds rgb from named red, green and blue channels', function () {
            $result = $this->constructors->rgbaFunction([], [
                'red'   => new NumberNode(255),
                'green' => new NumberNode(0),
                'blue'  => new NumberNode(0),
            ]);

            expect($result)->toBeInstanceOf(FunctionNode::class);

            /** @var FunctionNode $result */
            expect($result->name)->toBe('rgb')
                ->and($result->arguments)->toHaveCount(3)
                ->and($result->arguments[0])->toBeInstanceOf(NumberNode::class)
                ->and($result->arguments[0]->value)->toBe(255.0)
                ->and($result->arguments[1]->value)->toBe(0.0)
                ->and($result->arguments[2]->value)->toBe(0.0);
        });

        it('keeps a named alpha alongside named rgb channels', function () {
            $result = $this->constructors->rgbaFunction([], [
                'red'   => new NumberNode(255),
                'green' => new NumberNode(0),
                'blue'  => new NumberNode(0),
                'alpha' => new NumberNode(0.5),
            ]);

            expect($result)->toBeInstanceOf(FunctionNode::class);

            /** @var FunctionNode $result */
            expect($result->name)->toBe('rgba')
                ->and($result->arguments)->toHaveCount(4)
                ->and($result->arguments[3])->toBeInstanceOf(NumberNode::class)
                ->and($result->arguments[3]->value)->toBe(0.5);
        });

        it('defers rgba with an unresolvable color and numeric alpha to CSS', function () {
            $var = new FunctionNode('var', [new StringNode('--x')]);

            $result = $this->constructors->rgbaFunction([$var, new NumberNode(0.5)]);

            expect($result)->toBeInstanceOf(FunctionNode::class);

            /** @var FunctionNode $result */
            expect($result->name)->toBe('rgba')
                ->and($result->arguments)->toHaveCount(2)
                ->and($result->arguments[0])->toBe($var)
                ->and($result->arguments[1])->toBeInstanceOf(NumberNode::class)
                ->and($result->arguments[1]->value)->toBe(0.5);
        });

        it('keeps a non-numeric alpha node when rebuilding a two-argument rgba', function () {
            $alpha = new FunctionNode('var', [new StringNode('--a')]);

            $result = $this->constructors->rgbaFunction([new ColorNode('red'), $alpha]);

            expect($result)->toBeInstanceOf(FunctionNode::class);

            /** @var FunctionNode $result */
            expect($result->name)->toBe('rgba')
                ->and($result->arguments)->toHaveCount(4)
                ->and($result->arguments[0])->toBeInstanceOf(NumberNode::class)
                ->and($result->arguments[0]->value)->toBe(255.0)
                ->and($result->arguments[1]->value)->toBe(0.0)
                ->and($result->arguments[2]->value)->toBe(0.0)
                ->and($result->arguments[3])->toBe($alpha);
        });

        it('defers when a two-argument rgba color cannot be resolved', function () {
            $var = new FunctionNode('var', [new StringNode('--x')]);

            expect(fn() => $this->constructors->rgbaFunction([$var, new StringNode('q')]))
                ->toThrow(DeferToCssFunctionException::class, 'rgba() should be emitted as a CSS function.');
        });

        it('rethrows when the color argument cannot be resolved at all', function () {
            expect(fn() => $this->constructors->rgbaFunction([new NumberNode(1, 'px'), new NumberNode(0.5)]))
                ->toThrow(MissingFunctionArgumentsException::class, 'rgba() expects color arguments.');
        });
    });

    describe('legacyRgbaFunction', function () {
        it('passes relative color syntax through verbatim', function () {
            $positional = [
                new StringNode('from'),
                new ColorNode('red'),
                new NumberNode(255),
                new NumberNode(0),
                new NumberNode(0),
            ];

            $result = $this->constructors->legacyRgbaFunction($positional);

            expect($result)->toBeInstanceOf(FunctionNode::class);

            /** @var FunctionNode $result */
            expect($result->name)->toBe('rgba')
                ->and($result->arguments)->toHaveCount(5)
                ->and($result->arguments[0])->toBe($positional[0])
                ->and($result->arguments[1])->toBe($positional[1]);
        });
    });

    describe('hwbFunction', function () {
        it('emits a modern hwb function when the alpha is missing', function () {
            $hueZero = $this->constructors->hwbFunction([
                new NumberNode(0),
                new NumberNode(30, '%'),
                new ListNode([new NumberNode(50, '%'), new StringNode('/'), new StringNode('none')], 'space'),
            ]);

            expect($hueZero)->toBeInstanceOf(FunctionNode::class);

            /** @var FunctionNode $hueZero */
            expect($hueZero->name)->toBe('hwb')
                ->and($hueZero->arguments)->toHaveCount(1)
                ->and($hueZero->arguments[0])->toBeInstanceOf(ListNode::class);

            /** @var ListNode $list */
            $list = $hueZero->arguments[0];

            expect($list->separator)->toBe('space')
                ->and($list->items[0])->toBeInstanceOf(StringNode::class)
                ->and($list->items[0]->value)->toBe('0deg')
                ->and($list->items[1]->value)->toBe(30)
                ->and($list->items[2]->value)->toBe(50)
                ->and($list->items[3]->value)->toBe('/')
                ->and($list->items[4]->value)->toBe('none');

            $hueKept = $this->constructors->hwbFunction([
                new NumberNode(120),
                new NumberNode(30, '%'),
                new ListNode([new NumberNode(50, '%'), new StringNode('/'), new StringNode('none')], 'space'),
            ]);

            expect($hueKept)->toBeInstanceOf(FunctionNode::class);

            /** @var FunctionNode $hueKept */
            expect($hueKept->name)->toBe('hwb')
                ->and($hueKept->arguments[0])->toBeInstanceOf(ListNode::class);

            /** @var ListNode $keptList */
            $keptList = $hueKept->arguments[0];

            expect($keptList->items[0])->toBeInstanceOf(NumberNode::class)
                ->and($keptList->items[0]->value)->toBe(120)
                ->and($keptList->items[4]->value)->toBe('none');
        });

        it('emits a modern hwb function when a channel is missing and alpha is present', function () {
            $result = $this->constructors->hwbFunction([
                new NumberNode(30, '%'),
                new ListNode([new StringNode('none'), new StringNode('/'), new NumberNode(120, 'deg')], 'space'),
                new NumberNode(50, '%'),
            ]);

            expect($result)->toBeInstanceOf(FunctionNode::class);

            /** @var FunctionNode $result */
            expect($result->name)->toBe('hwb')
                ->and($result->arguments)->toHaveCount(1)
                ->and($result->arguments[0])->toBeInstanceOf(ListNode::class);

            /** @var ListNode $list */
            $list = $result->arguments[0];

            expect($list->separator)->toBe('space')
                ->and($list->items)->toHaveCount(5)
                ->and($list->items[0]->value)->toBe(30)
                ->and($list->items[1]->value)->toBe('none')
                ->and($list->items[2]->value)->toBe(120)
                ->and($list->items[3]->value)->toBe('/')
                ->and($list->items[4]->value)->toBe(50);
        });

        it('replaces a zero hue with 0deg when a channel is missing', function () {
            $result = $this->constructors->hwbFunction([
                new NumberNode(0),
                new ListNode([new StringNode('none'), new StringNode('/'), new NumberNode(120, 'deg')], 'space'),
                new NumberNode(50, '%'),
            ]);

            expect($result)->toBeInstanceOf(FunctionNode::class);

            /** @var FunctionNode $result */
            expect($result->arguments[0])->toBeInstanceOf(ListNode::class);

            /** @var ListNode $list */
            $list = $result->arguments[0];

            expect($list->items[0])->toBeInstanceOf(StringNode::class)
                ->and($list->items[0]->value)->toBe('0deg')
                ->and($list->items[1]->value)->toBe('none');
        });

        it('treats a missing hue as 0 when extracting slash separated channels', function () {
            $result = $this->constructors->hwbFunction([
                new ListNode([new StringNode('none'), new StringNode('/'), new NumberNode(0, 'deg')], 'comma'),
                new NumberNode(30, '%'),
                new NumberNode(50, '%'),
            ]);

            expect($result)->toBeInstanceOf(FunctionNode::class);

            /** @var FunctionNode $result */
            expect($result->arguments[0])->toBeInstanceOf(ListNode::class);

            /** @var ListNode $list */
            $list = $result->arguments[0];

            expect($list->items[0]->value)->toBe('none')
                ->and($list->items[1]->value)->toBe(0)
                ->and($list->items[2]->value)->toBe(30)
                ->and($list->items[3]->value)->toBe('/')
                ->and($list->items[4]->value)->toBe(50);
        });
    });
});
