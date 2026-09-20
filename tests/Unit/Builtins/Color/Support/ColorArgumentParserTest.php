<?php

declare(strict_types=1);

use Bugo\Iris\Converters\SpaceConverter;
use Bugo\SCSS\Builtins\Color\Support\ColorArgumentParser;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
use Bugo\SCSS\Exceptions\InvalidColorChannelsException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
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

    it('defers css functions when color arguments are missing', function () {
        expect(fn() => $this->parser->requireColorOrDefer([new NumberNode(1)], 'adjust-hue'))
            ->toThrow(DeferToCssFunctionException::class);
    });

    it('does not defer when the exception is not about color arguments', function () {
        $exception = MissingFunctionArgumentsException::count('saturate', 2);

        expect($this->parser->shouldDeferToCss($exception))->toBeFalse();
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
            ->and($this->parser->asHueAngle(new NumberNode(INF), 'spin'))->toBe(0.0)
            ->and($this->parser->asHueAngle(new NumberNode(-INF), 'spin'))->toBe(0.0)
            ->and($this->parser->asHueAngle(new NumberNode(NAN), 'spin'))->toBe(0.0)
            ->and($this->parser->asHueAngle(new NumberNode(1, 'rad'), 'spin'))->toBe(180.0 / M_PI)
            ->and($this->parser->asHueAngle(new NumberNode(100, 'grad'), 'spin'))->toBe(90.0);
    });

    it('validates absolute and generic color channels', function () {
        expect(fn() => $this->parser->asAbsoluteChannel(new StringNode('10'), 'lab', 125.0))
            ->toThrow(MissingFunctionArgumentsException::class, 'lab() expects number arguments.')
            ->and($this->parser->asAbsoluteChannel(new NumberNode(INF), 'lab', 125.0))->toBe(INF)
            ->and(fn() => $this->parser->asColorChannel(new StringNode('10')))
            ->toThrow(MissingFunctionArgumentsException::class, 'color() expects number arguments.')
            ->and(fn() => $this->parser->asColorChannel(new NumberNode(INF)))
            ->toThrow(DeferToCssFunctionException::class);
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

    it('drops the sign of negative zero channels', function () {
        $isNegativeZero = static fn(float $value): bool => $value === 0.0 && fdiv(1.0, $value) < 0;

        expect($isNegativeZero($this->parser->asNumber(new NumberNode(-0.0), 'scale')))->toBeFalse()
            ->and($isNegativeZero($this->parser->asHueAngle(new NumberNode(-0.0, 'rad'), 'spin')))->toBeFalse()
            ->and($isNegativeZero($this->parser->asAbsoluteChannel(new NumberNode(-0.0, '%'), 'lab', 125.0)))->toBeFalse()
            ->and($isNegativeZero($this->parser->asColorChannel(new NumberNode(-0.0, '%'))))->toBeFalse()
            ->and($isNegativeZero($this->parser->asPercentage(new NumberNode(-0.0, '%'), 'mix')))->toBeFalse()
            ->and($isNegativeZero($this->parser->asLenientPercentage(new NumberNode(-0.0, '%'), 'hsl')))->toBeFalse()
            ->and($isNegativeZero($this->parser->normalizeHue(-360.0)))->toBeFalse();
    });

    describe('edge branches', function () {
        it('rethrows missing color arguments without deferring', function () {
            expect(fn() => $this->parser->requireColorOrDefer([], 'saturate'))
                ->toThrow(MissingFunctionArgumentsException::class, "saturate() expects required argument 'color'.");
        });

        it('throws when functional color arguments are below the minimum', function () {
            expect(fn() => $this->parser->parseFunctionalColorArguments([], 'color', 2))
                ->toThrow(MissingFunctionArgumentsException::class, 'color() expects 2 arguments.');
        });

        it('returns null for ambiguous multi-slash channel strings', function () {
            $input = new ListNode([new NumberNode(1), new StringNode('a/b/c')], 'space');

            expect($this->parser->parseChannelList($input))->toBeNull()
                ->and(fn() => $this->parser->parseChannels('hsl', 'channels', 'hsl', ['hue', 'saturation', 'lightness'], $input))
                ->toThrow(DeferToCssFunctionException::class, 'hsl() should be emitted as a CSS function.');
        });

        it('rejects empty and bracketed channel lists', function () {
            expect(fn() => $this->parser->parseChannels('hsl', 'channels', 'hsl', ['hue', 'saturation', 'lightness'], new ListNode([], 'space')))
                ->toThrow(InvalidColorChannelsException::class, '$channels: Color component list may not be empty.')
                ->and(fn() => $this->parser->parseChannels('hsl', 'channels', 'hsl', ['hue', 'saturation', 'lightness'], new ListNode([new NumberNode(1), new NumberNode(2), new NumberNode(3)], 'space', true)))
                ->toThrow(InvalidColorChannelsException::class, '$channels: Expected an unbracketed list, was [1 2 3]');
        });

        it('defers special alpha channels outside three-channel colors', function () {
            $input = new ListNode([
                new NumberNode(1),
                new NumberNode(2),
                new StringNode('/'),
                new FunctionNode('calc', [new NumberNode(0.5)]),
            ], 'space');

            expect(fn() => $this->parser->parseChannels('hsl', 'channels', 'hsl', ['hue', 'saturation', 'lightness'], $input))
                ->toThrow(DeferToCssFunctionException::class, 'hsl() should be emitted as a CSS function.');
        });

        it('validates channel counts and slash-separated element counts', function () {
            expect(fn() => $this->parser->parseChannels('hsl', 'channels', 'hsl', ['hue', 'saturation', 'lightness'], new NumberNode(1)))
                ->toThrow(InvalidColorChannelsException::class, '$channels: The hsl color space has 3 channels but 1 has 1.')
                ->and(fn() => $this->parser->parseChannels('hsl', 'channels', 'hsl', ['hue', 'saturation', 'lightness'], new ListNode([new NumberNode(1), new StringNode('/'), new NumberNode(2)], 'slash')))
                ->toThrow(InvalidColorChannelsException::class, '$channels: Only 2 slash-separated elements allowed, but 3 were passed.');
        });

        it('rejects non-numeric alpha channels', function () {
            $input = new ListNode([
                new ListNode([new NumberNode(1), new NumberNode(2), new NumberNode(3)], 'space'),
                new StringNode('red', true),
            ], 'slash');

            expect(fn() => $this->parser->parseChannels('hsl', 'channels', 'hsl', ['hue', 'saturation', 'lightness'], $input))
                ->toThrow(InvalidColorChannelsException::class, '$channels: Expected alpha to be a number, was "red".');
        });

        it('splits trailing slash fractions embedded in channel strings', function () {
            $plain = $this->parser->parseChannelList(new ListNode([new NumberNode(1), new StringNode('2/0.5')], 'space'));

            $unit = $this->parser->parseChannelList(new ListNode([new StringNode('-3px/4')], 'space'));

            $words = $this->parser->parseChannelList(new ListNode([new StringNode('foo/var(--x)')], 'space'));

            expect($plain['components'] ?? null)->toEqual(new ListNode([new NumberNode(1), new NumberNode(2)], 'space'))
                ->and($plain['alpha'] ?? null)->toEqual(new NumberNode(0.5))
                ->and($unit['components'] ?? null)->toEqual(new ListNode([new NumberNode(-3, 'px')], 'space'))
                ->and($unit['alpha'] ?? null)->toEqual(new NumberNode(4))
                ->and($words['components'] ?? null)->toEqual(new ListNode([new StringNode('foo')], 'space'))
                ->and($words['alpha'] ?? null)->toEqual(new StringNode('var(--x)'));
        });

        it('throws lenient percentage errors for non-number values', function () {
            expect(fn() => $this->parser->asLenientPercentage(new StringNode('x'), 'hsl'))
                ->toThrow(MissingFunctionArgumentsException::class, 'hsl() expects number arguments.');
        });

        it('maps NaN channel values to zero when clamping', function () {
            expect($this->parser->clamp(NAN, 255.0))->toBe(0.0);
        });

        it('keeps slash lists whose channels are not space lists', function () {
            $list = new ListNode([new NumberNode(1), new NumberNode(2)], 'slash');

            expect($this->parser->parseFunctionalColorArguments([$list], 'rgb', 1))->toBe([$list]);
        });

        it('keeps malformed color-mix arguments for argument validation', function () {
            expect(fn() => $this->parser->requireColor([new FunctionNode('color-mix', [new StringNode('red')])], 0, 'mix'))
                ->toThrow(MissingFunctionArgumentsException::class, 'mix() expects color arguments.');
        });

        it('renders lists, strings, functions and fallback nodes for errors', function () {
            expect($this->parser->renderForError(new ListNode([new StringNode('a'), new StringNode('b')], 'slash')))->toBe('(a / b)')
                ->and($this->parser->renderForError(new ListNode([new NumberNode(1), new NumberNode(2)], 'space')))->toBe('(1 2)')
                ->and($this->parser->renderForError(new StringNode('red', true)))->toBe('"red"')
                ->and($this->parser->renderForError(new FunctionNode('var', [new StringNode('--x')])))->toBe('var(--x)')
                ->and($this->parser->renderForError(new MapNode()))->toBe('');
        });
    });
});
