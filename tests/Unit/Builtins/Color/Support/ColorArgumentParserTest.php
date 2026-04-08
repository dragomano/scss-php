<?php

declare(strict_types=1);

use Bugo\Iris\Converters\SpaceConverter;
use Bugo\SCSS\Builtins\Color\Support\ColorArgumentParser;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\NonFiniteNumberException;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('ColorArgumentParser', function () {
    beforeEach(function () {
        $this->parser = new ColorArgumentParser(
            new SpaceConverter(),
            new ColorModuleContext(
                errorCtx: static fn(string $name): string => $name,
                isGlobalBuiltinCall: static fn(): bool => false,
                warn: static function (): void {},
            ),
        );
    });

    it('rethrows missing color argument errors when css defer is not allowed', function () {
        expect(fn() => $this->parser->requireColorOrDefer([new NumberNode(1)], 'adjust-hue'))
            ->toThrow(MissingFunctionArgumentsException::class, 'adjust-hue() expects color arguments.');
    });

    it('does not defer when the exception is not about color arguments', function () {
        $exception = MissingFunctionArgumentsException::count('saturate', 2);

        expect($this->parser->shouldDeferToCss('saturate', $exception))->toBeFalse();
    });

    it('defers supported css functions with invalid color arguments', function () {
        expect(fn() => $this->parser->requireColorOrDefer([new NumberNode(1)], 'saturate'))
            ->toThrow(DeferToCssFunctionException::class, 'saturate() expects color arguments.');
    });

    it('validates numeric conversions and hue units', function () {
        expect(fn() => $this->parser->asNumber(new StringNode('10'), 'scale'))
            ->toThrow(MissingFunctionArgumentsException::class, 'scale() expects number arguments.')
            ->and(fn() => $this->parser->asHueAngle(new StringNode('10'), 'spin'))
            ->toThrow(MissingFunctionArgumentsException::class, 'spin() expects number arguments.')
            ->and(fn() => $this->parser->asHueAngle(new NumberNode(INF), 'spin'))
            ->toThrow(NonFiniteNumberException::class, 'spin received a non-finite number.')
            ->and($this->parser->asHueAngle(new NumberNode(1, 'rad'), 'spin'))->toBe((float) (180.0 / M_PI))
            ->and($this->parser->asHueAngle(new NumberNode(100, 'grad'), 'spin'))->toBe(90.0);
    });

    it('validates absolute and generic color channels', function () {
        expect(fn() => $this->parser->asAbsoluteChannel(new StringNode('10'), 'lab', 125.0))
            ->toThrow(MissingFunctionArgumentsException::class, 'lab() expects number arguments.')
            ->and(fn() => $this->parser->asAbsoluteChannel(new NumberNode(INF), 'lab', 125.0))
            ->toThrow(NonFiniteNumberException::class, 'lab received a non-finite number.')
            ->and(fn() => $this->parser->asColorChannel(new StringNode('10')))
            ->toThrow(MissingFunctionArgumentsException::class, 'color() expects number arguments.')
            ->and(fn() => $this->parser->asColorChannel(new NumberNode(INF)))
            ->toThrow(NonFiniteNumberException::class, 'color received a non-finite number.');
    });

    it('validates string and percentage arguments', function () {
        expect(fn() => $this->parser->asString(new ColorNode('#fff'), 'color'))
            ->toThrow(MissingFunctionArgumentsException::class, 'color() expects string arguments.')
            ->and(fn() => $this->parser->asPercentage(new StringNode('50%'), 'mix'))
            ->toThrow(MissingFunctionArgumentsException::class, 'mix() expects a percentage number.')
            ->and(fn() => $this->parser->asPercentage(new NumberNode(50, 'px'), 'mix'))
            ->toThrow(MissingFunctionArgumentsException::class, 'mix() expects percentage values.');
    });

    it('unwraps calc numbers only for supported calc shapes', function () {
        $direct         = new FunctionNode('calc', [new NumberNode(25, '%')]);
        $nested         = new FunctionNode('calc', [new ListNode([new NumberNode(10)])]);
        $twoArguments   = new FunctionNode('calc', [new NumberNode(1), new NumberNode(2)]);
        $nonNumericList = new FunctionNode('calc', [new ListNode([new StringNode('foo')])]);

        $directResult = $this->parser->unwrapCalcNumber($direct);
        $nestedResult = $this->parser->unwrapCalcNumber($nested);

        expect($this->parser->unwrapCalcNumber($twoArguments))->toBeNull()
            ->and($directResult)->toBeInstanceOf(NumberNode::class)
            ->and($directResult?->value)->toBe(25)
            ->and($nestedResult)->toBeInstanceOf(NumberNode::class)
            ->and($nestedResult?->value)->toBe(10)
            ->and($this->parser->unwrapCalcNumber($nonNumericList))->toBeNull()
            ->and($this->parser->unwrapCalcNumber(new StringNode('calc(10%)')))->toBeNull();
    });
});
