<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\SassColorModule;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnknownSassFunctionException;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Tests\ReflectionAccessor;

describe('SassColorModule', function () {
    beforeEach(function () {
        $this->module = new SassColorModule();
        $this->accessor = new ReflectionAccessor($this->module);
    });

    it('exposes metadata', function () {
        expect($this->module->getName())->toBe('color')
            ->and($this->module->getFunctions())->toBe([
                'adjust',
                'alpha',
                'blackness',
                'blue',
                'change',
                'channel',
                'complement',
                'grayscale',
                'green',
                'hue',
                'hwb',
                'ie-hex-str',
                'invert',
                'is-in-gamut',
                'is-legacy',
                'is-missing',
                'is-powerless',
                'lightness',
                'mix',
                'opacity',
                'red',
                'same',
                'saturation',
                'scale',
                'space',
                'to-gamut',
                'to-space',
                'whiteness',
            ])
            ->and($this->module->getGlobalAliases())->toHaveKeys([
                'mix',
                'lighten',
                'darken',
                'grayscale',
                'ie-hex-str',
                'fade-in',
                'fade-out',
            ]);
    });

    it('evaluates adjust-hue', function () {
        $result = $this->module->call('adjust-hue', [new ColorNode('#ff0000'), new NumberNode(120, 'deg')], []);
        expect($result->value)->toBe('#00ff00');
    });

    it('evaluates adjust-color', function () {
        $result = $this->module->call('adjust-color', [new ColorNode('#112233')], ['blue' => new NumberNode(10)]);
        expect($result->value)->toBe('#11223d');
    });

    it('evaluates adjust', function () {
        $result = $this->module->call('adjust', [new ColorNode('#112233')], ['blue' => new NumberNode(10)]);
        expect($result->value)->toBe('#11223d');
    });

    it('evaluates alpha', function () {
        $result = $this->module->call('alpha', [new ColorNode('#33669980')], []);
        expect($result->value)->toBeCloseTo(0.501961, 0.00001);
    });

    it('evaluates opacity', function () {
        $result = $this->module->call('opacity', [new ColorNode('#33669980')], []);
        expect($result->value)->toBeCloseTo(0.501961, 0.00001);
    });

    it('evaluates blackness', function () {
        $result = $this->module->call('blackness', [new ColorNode('#336699')], []);
        expect($result->value)->toBeCloseTo(40.0, 0.001)->and($result->unit)->toBe('%');
    });

    it('evaluates blue', function () {
        $result = $this->module->call('blue', [new ColorNode('#336699')], []);
        expect($result->value)->toBe(153.0);
    });

    it('evaluates change-color', function () {
        $result = $this->module->call('change-color', [new ColorNode('#112233')], ['red' => new NumberNode(255)]);
        expect($result->value)->toBe('#ff2233');
    });

    it('evaluates change', function () {
        $result = $this->module->call('change', [new ColorNode('#112233')], ['red' => new NumberNode(255)]);
        expect($result->value)->toBe('#ff2233');
    });

    it('evaluates channel', function () {
        $result = $this->module->call('channel', [new ColorNode('#336699'), new StringNode('red')], []);
        expect($result)->toBeInstanceOf(NumberNode::class)->and($result->value)->toBe(51.0);
    });

    it('evaluates channel with srgb space', function () {
        $result = $this->module->call('channel', [new ColorNode('#ff0000'), new StringNode('red')], ['space' => new StringNode('srgb')]);
        expect($result)->toBeInstanceOf(NumberNode::class)->and($result->value)->toBe(1.0);

        $result = $this->module->call('channel', [new ColorNode('#ff0000'), new StringNode('red')], ['space' => new StringNode('rgb')]);
        expect($result)->toBeInstanceOf(NumberNode::class)->and($result->value)->toBe(255.0);
    });

    it('evaluates complement', function () {
        $result = $this->module->call('complement', [new ColorNode('#ff0000')], []);
        expect($result->value)->toBe('#00ffff');
    });

    it('evaluates darken', function () {
        $result = $this->module->call('darken', [new ColorNode('#ffffff'), new NumberNode(20, '%')], []);
        expect($result->value)->toBe('#cccccc');
    });

    it('evaluates desaturate', function () {
        $result = $this->module->call('desaturate', [new ColorNode('#ff0000'), new NumberNode(100, '%')], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb')
            ->and($result->arguments[0]->value)->toBe(127.5)
            ->and($result->arguments[1]->value)->toBe(127.5)
            ->and($result->arguments[2]->value)->toBe(127.5);
    });

    it('evaluates grayscale', function () {
        $result = $this->module->call('grayscale', [new ColorNode('#ff0000')], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb')
            ->and($result->arguments[0]->value)->toBe(127.5)
            ->and($result->arguments[1]->value)->toBe(127.5)
            ->and($result->arguments[2]->value)->toBe(127.5);
    });

    it('evaluates green', function () {
        $result = $this->module->call('green', [new ColorNode('#336699')], []);
        expect($result->value)->toBe(102.0);
    });

    it('evaluates hsl', function () {
        $result = $this->module->call('hsl', [new NumberNode(120), new NumberNode(100, '%'), new NumberNode(50, '%')], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)->and($result->name)->toBe('hsl');
    });

    it('evaluates hsla', function () {
        $result = $this->module->call('hsla', [new NumberNode(240), new NumberNode(100, '%'), new NumberNode(50, '%'), new NumberNode(0.5)], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)->and($result->name)->toBe('hsla');
    });

    it('evaluates hue', function () {
        $result = $this->module->call('hue', [new ColorNode('#ff0000')], []);
        expect($result->value)->toBe(0.0)->and($result->unit)->toBe('deg');
    });

    it('evaluates ie-hex-str', function () {
        $result = $this->module->call('ie-hex-str', [new ColorNode('#33669980')], []);
        expect($result)->toBeInstanceOf(StringNode::class)->and($result->value)->toBe('#80336699');
    });

    it('evaluates ie-hex-str with fully opaque color', function () {
        $result = $this->module->call('ie-hex-str', [new ColorNode('#ff0000')], []);
        expect($result)->toBeInstanceOf(StringNode::class)->and($result->value)->toBe('#FFFF0000');
    });

    it('evaluates ie-hex-str with fully transparent color', function () {
        $result = $this->module->call('ie-hex-str', [new ColorNode('#ff000000')], []);
        expect($result)->toBeInstanceOf(StringNode::class)->and($result->value)->toBe('#00FF0000');
    });

    it('evaluates invert', function () {
        $result = $this->module->call('invert', [new ColorNode('#123456')], []);
        expect($result->value)->toBe('#edcba9');
    });

    it('evaluates invert in display-p3 space', function () {
        $result = $this->module->call('invert', [new ColorNode('#550e0c'), new NumberNode(20, '%')], ['space' => new StringNode('display-p3')]);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb')
            ->and($result->arguments[0]->value)->toBeCloseTo(103.4937692017, 0.000000001)
            ->and($result->arguments[1]->value)->toBeCloseTo(61.3720912206, 0.000000001)
            ->and($result->arguments[2]->value)->toBeCloseTo(59.4306413380, 0.000000001);
    });

    it('evaluates is-in-gamut', function () {
        $result = $this->module->call('is-in-gamut', [new ColorNode('#b37399')], []);
        expect($result)->toBeInstanceOf(BooleanNode::class)->and($result->value)->toBeTrue();

        $outOfGamut = new FunctionNode('color', [
            new StringNode('srgb'),
            new NumberNode(1.2),
            new NumberNode(0),
            new NumberNode(0),
        ]);
        $result2 = $this->module->call('is-in-gamut', [$outOfGamut], []);
        expect($result2)->toBeInstanceOf(BooleanNode::class)->and($result2->value)->toBeFalse();
    });

    it('evaluates is-legacy', function () {
        $result = $this->module->call('is-legacy', [new ColorNode('#336699')], []);
        expect($result)->toBeInstanceOf(BooleanNode::class)->and($result->value)->toBeTrue();
    });

    it('evaluates is-missing', function () {
        $result = $this->module->call('is-missing', [new ColorNode('#336699'), new StringNode('red')], []);
        expect($result)->toBeInstanceOf(BooleanNode::class)->and($result->value)->toBeFalse();
    });

    it('evaluates is-missing for hue after to-space lch string conversion', function () {
        $lch = $this->module->call('to-space', [new ColorNode('grey'), new StringNode('lch')], []);
        $result = $this->module->call('is-missing', [$lch, new StringNode('hue')], []);
        expect($result)->toBeInstanceOf(BooleanNode::class)->and($result->value)->toBeTrue();
    });

    it('evaluates is-powerless', function () {
        $result = $this->module->call('is-powerless', [new ColorNode('#808080'), new StringNode('hue')], []);
        expect($result)->toBeInstanceOf(BooleanNode::class)->and($result->value)->toBeTrue();
    });

    it('evaluates lighten', function () {
        $result = $this->module->call('lighten', [new ColorNode('#000000'), new NumberNode(20, '%')], []);
        expect($result->value)->toBe('#333333');
    });

    it('evaluates lightness', function () {
        $result = $this->module->call('lightness', [new ColorNode('#000000')], []);
        expect($result->value)->toBe(0.0)->and($result->unit)->toBe('%');
    });

    it('evaluates mix', function () {
        $result = $this->module->call('mix', [new ColorNode('#000000'), new ColorNode('#ffffff'), new NumberNode(50, '%')], []);
        expect($result)->toBeInstanceOf(ColorNode::class)->and($result->value)->toBe('#808080');
    });

    it('evaluates mix in rgb with float channel result', function () {
        $result = $this->module->call('mix', [new ColorNode('#036'), new ColorNode('#d2e1dd')], ['method' => new StringNode('rgb')]);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb')
            ->and($result->arguments[0]->value)->toBe(105.0)
            ->and($result->arguments[1]->value)->toBe(138.0)
            ->and($result->arguments[2]->value)->toBe(161.5);
    });

    it('evaluates mix in rec2020 with missing channels preserved', function () {
        $result = $this->module->call('mix', [
            new FunctionNode('color', [new ListNode([
                new StringNode('rec2020'),
                new NumberNode(1),
                new NumberNode(0.7),
                new NumberNode(0.1),
            ], 'space')]),
            new FunctionNode('color', [new ListNode([
                new StringNode('rec2020'),
                new NumberNode(0.8),
                new StringNode('none'),
                new NumberNode(0.3),
            ], 'space')]),
        ], [
            'weight' => new NumberNode(75, '%'),
            'method' => new StringNode('rec2020'),
        ]);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color');
    });

    it('evaluates mix in oklch with longer hue interpolation', function () {
        $result = $this->module->call('mix', [
            new FunctionNode('oklch', [
                new NumberNode(80, '%'),
                new NumberNode(20, '%'),
                new NumberNode(0, 'deg'),
            ]),
            new FunctionNode('oklch', [
                new NumberNode(50, '%'),
                new NumberNode(10, '%'),
                new NumberNode(120, 'deg'),
            ]),
        ], [
            'method' => new StringNode('oklch longer hue'),
        ]);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('oklch');
    });

    it('evaluates mix in oklch with increasing and decreasing hue interpolation', function () {
        $increasing = $this->module->call('mix', [
            new FunctionNode('oklch', [
                new NumberNode(80, '%'),
                new NumberNode(20, '%'),
                new NumberNode(300, 'deg'),
            ]),
            new FunctionNode('oklch', [
                new NumberNode(50, '%'),
                new NumberNode(10, '%'),
                new NumberNode(120, 'deg'),
            ]),
        ], [
            'method' => new StringNode('oklch increasing hue'),
        ]);

        $decreasing = $this->module->call('mix', [
            new FunctionNode('oklch', [
                new NumberNode(80, '%'),
                new NumberNode(20, '%'),
                new NumberNode(300, 'deg'),
            ]),
            new FunctionNode('oklch', [
                new NumberNode(50, '%'),
                new NumberNode(10, '%'),
                new NumberNode(120, 'deg'),
            ]),
        ], [
            'method' => new StringNode('oklch decreasing hue'),
        ]);

        expect($increasing)->toBeInstanceOf(FunctionNode::class)
            ->and($increasing->name)->toBe('oklch')
            ->and($increasing->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($increasing->arguments[0]->items[2]->value)->toBe(30.0)
            ->and($decreasing)->toBeInstanceOf(FunctionNode::class)
            ->and($decreasing->name)->toBe('oklch')
            ->and($decreasing->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($decreasing->arguments[0]->items[2]->value)->toBe(210.0);
    });

    it('evaluates mix in oklch with missing channels preserved', function () {
        $missingHue = $this->module->call('mix', [
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(80, '%'),
                new NumberNode(20, '%'),
                new StringNode('none'),
            ], 'space')]),
            new FunctionNode('oklch', [
                new NumberNode(50, '%'),
                new NumberNode(10, '%'),
                new NumberNode(120, 'deg'),
            ]),
        ], ['method' => new StringNode('oklch')]);

        $missingChroma = $this->module->call('mix', [
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(80, '%'),
                new StringNode('none'),
                new NumberNode(0, 'deg'),
            ], 'space')]),
            new FunctionNode('oklch', [
                new NumberNode(50, '%'),
                new NumberNode(10, '%'),
                new NumberNode(120, 'deg'),
            ]),
        ], ['method' => new StringNode('oklch')]);

        expect($missingHue)->toBeInstanceOf(FunctionNode::class)->and($missingHue->name)->toBe('oklch')
            ->and($missingChroma)->toBeInstanceOf(FunctionNode::class)->and($missingChroma->name)->toBe('oklch');
    });

    it('evaluates opacify', function () {
        $result = $this->module->call('opacify', [new ColorNode('#11223380'), new NumberNode(0.2)], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgba')
            ->and($result->arguments[0]->value)->toBe(17.0)
            ->and($result->arguments[1]->value)->toBe(34.0)
            ->and($result->arguments[2]->value)->toBe(51.0)
            ->and($result->arguments[3]->value)->toBeCloseTo(0.7019607843, 0.0000000001);
    });

    it('evaluates fade-in alias', function () {
        $result = $this->module->call('fade-in', [new ColorNode('#11223380'), new NumberNode(0.2)], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgba')
            ->and($result->arguments[0]->value)->toBe(17.0)
            ->and($result->arguments[1]->value)->toBe(34.0)
            ->and($result->arguments[2]->value)->toBe(51.0)
            ->and($result->arguments[3]->value)->toBeCloseTo(0.7019607843, 0.0000000001);
    });

    it('throws for unknown legacy alpha adjustments', function () {
        expect(fn() => $this->accessor->callMethod('legacyAlphaAdjustment', ['unknown', [], null]))
            ->toThrow(UnknownSassFunctionException::class);
    });

    it('evaluates red', function () {
        $result = $this->module->call('red', [new ColorNode('#336699')], []);
        expect($result)->toBeInstanceOf(NumberNode::class)->and($result->value)->toBe(51.0);
    });

    it('evaluates rgb', function () {
        $result = $this->module->call('rgb', [new NumberNode(255), new NumberNode(0), new NumberNode(0)], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)->and($result->name)->toBe('rgb');
    });

    it('evaluates rgba', function () {
        $result = $this->module->call('rgba', [new ColorNode('#ff0000'), new NumberNode(0.5)], []);
        expect($result)->toBeInstanceOf(ColorNode::class)->and($result->value)->toBe('#ff000080');
    });

    it('uses global display name with color module suffix in rgba signature errors', function () {
        expect(fn() => $this->module->call('rgba', [new NumberNode(255), new NumberNode(0), new NumberNode(0)], []))
            ->toThrow(MissingFunctionArgumentsException::class, 'rgba() (color module) expects 2 or 4 arguments.');
    });

    it('evaluates same', function () {
        $result = $this->module->call('same', [new ColorNode('#ff0000'), new ColorNode('#ff0000')], []);
        expect($result)->toBeInstanceOf(BooleanNode::class)->and($result->value)->toBeTrue();
    });

    it('evaluates same with color.to-space() oklch result', function () {
        $oklch = $this->module->call('to-space', [new ColorNode('#036'), new StringNode('oklch')], []);
        $result = $this->module->call('same', [new ColorNode('#036'), $oklch], []);
        expect($result)->toBeInstanceOf(BooleanNode::class)->and($result->value)->toBeTrue();
    });

    it('evaluates saturation', function () {
        $result = $this->module->call('saturation', [new ColorNode('#ff0000')], []);
        expect($result->value)->toBe(100.0)->and($result->unit)->toBe('%');
    });

    it('evaluates scale-color', function () {
        $result = $this->module->call('scale-color', [new ColorNode('#000000')], ['red' => new NumberNode(50, '%')]);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb')
            ->and($result->arguments[0]->value)->toBe(127.5)
            ->and($result->arguments[1]->value)->toBe(0.0)
            ->and($result->arguments[2]->value)->toBe(0.0);
    });

    it('evaluates scale', function () {
        $result = $this->module->call('scale', [new ColorNode('#000000')], ['red' => new NumberNode(50, '%')]);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb')
            ->and($result->arguments[0]->value)->toBe(127.5)
            ->and($result->arguments[1]->value)->toBe(0.0)
            ->and($result->arguments[2]->value)->toBe(0.0);
    });

    it('evaluates scale with float rgb channels', function () {
        $result = $this->module->call('scale', [new ColorNode('#6b717f')], ['red' => new NumberNode(15, '%')]);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb')
            ->and($result->arguments[0]->value)->toBe(129.2)
            ->and($result->arguments[1]->value)->toBe(113.0)
            ->and($result->arguments[2]->value)->toBe(127.0);
    });

    it('evaluates scale in oklch space and returns oklch for native color', function () {
        $result = $this->module->call('scale', [
            new FunctionNode('oklch', [
                new NumberNode(80, '%'),
                new NumberNode(20, '%'),
                new NumberNode(120, 'deg'),
            ]),
        ], [
            'chroma' => new NumberNode(50, '%'),
            'alpha' => new NumberNode(-40, '%'),
        ]);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('oklch')
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($result->arguments[0]->items[0]->value)->toBe(80.0)
            ->and($result->arguments[0]->items[0]->unit)->toBe('%')
            ->and($result->arguments[0]->items[1]->value)->toBeCloseTo(0.24, 0.000000001)
            ->and($result->arguments[0]->items[2]->value)->toBe(120.0)
            ->and($result->arguments[0]->items[2]->unit)->toBe('deg')
            ->and($result->arguments[0]->items[3])->toBeInstanceOf(StringNode::class)
            ->and($result->arguments[0]->items[3]->value)->toBe('/')
            ->and($result->arguments[0]->items[4]->value)->toBe(0.6);
    });

    it('evaluates space for rgb hsl and xyz generic colors', function () {
        expect($this->module->call('space', [new ColorNode('#036')], [])->value)->toBe('rgb')
            ->and($this->module->call('space', [
                new FunctionNode('hsl', [new NumberNode(120, 'deg'), new NumberNode(40, '%'), new NumberNode(50, '%')]),
            ], [])->value)->toBe('hsl')
            ->and($this->module->call('space', [
                new FunctionNode('color', [new ListNode([
                    new StringNode('xyz-d65'),
                    new NumberNode(0.1),
                    new NumberNode(0.2),
                    new NumberNode(0.3),
                ], 'space')]),
            ], [])->value)->toBe('xyz');
    });

    it('returns local-minde gamut mapping in original oklch space', function () {
        $result = $this->module->call('to-gamut', [
            new FunctionNode('oklch', [
                new NumberNode(60, '%'),
                new NumberNode(70, '%'),
                new NumberNode(20, 'deg'),
            ]),
            new StringNode('rgb'),
            new StringNode('local-minde'),
        ], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('oklch');
    });

    it('returns unchanged color when already in rgb gamut', function () {
        $result = $this->module->call('to-gamut', [new ColorNode('#036')], ['method' => new StringNode('local-minde')]);
        expect($result)->toBeInstanceOf(ColorNode::class)
            ->and($result->value)->toBe('#036');
    });

    it('returns clip gamut mapping in original oklch space', function () {
        $result = $this->module->call('to-gamut', [
            new FunctionNode('oklch', [
                new NumberNode(60, '%'),
                new NumberNode(70, '%'),
                new NumberNode(20, 'deg'),
            ]),
            new StringNode('rgb'),
            new StringNode('clip'),
        ], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('oklch');
    });

    it('evaluates to-space', function () {
        $result = $this->module->call('to-space', [new ColorNode('#336699'), new StringNode('hsl')], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('hsl')
            ->and($result->arguments[0]->value)->toBe(210.0)
            ->and($result->arguments[1]->unit)->toBe('%')
            ->and($result->arguments[1]->value)->toBe(50.0)
            ->and($result->arguments[2]->unit)->toBe('%')
            ->and($result->arguments[2]->value)->toBe(40.0);
    });

    it('evaluates to-space for display-p3', function () {
        $result = $this->module->call('to-space', [new ColorNode('#036'), new StringNode('display-p3')], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color');
    });

    it('evaluates to-space for srgb-linear', function () {
        $result = $this->module->call('to-space', [new ColorNode('#336699'), new StringNode('srgb-linear')], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color')
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($result->arguments[0]->items[0]->value)->toBe('srgb-linear');
    });

    it('evaluates to-space for srgb', function () {
        $result = $this->module->call('to-space', [new ColorNode('#336699'), new StringNode('srgb')], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color')
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($result->arguments[0]->items[0]->value)->toBe('srgb');
    });

    it('evaluates to-space from oklab to rgb with float channels', function () {
        $result = $this->module->call('to-space', [
            new FunctionNode('oklab', [
                new NumberNode(44, '%'),
                new NumberNode(0.09),
                new NumberNode(-0.13),
            ]),
            new StringNode('rgb'),
        ], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb')
            ->and($result->arguments[0]->value)->toBeCloseTo(103.1328905413, 0.000000001)
            ->and($result->arguments[1]->value)->toBeCloseTo(50.9728129811, 0.000000001)
            ->and($result->arguments[2]->value)->toBeCloseTo(150.8382222315, 0.000000001);
    });

    it('evaluates to-space to oklab with percentage lightness', function () {
        $result = $this->module->call('to-space', [new ColorNode('#336699'), new StringNode('oklab')], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('oklab')
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($result->arguments[0]->items[0]->unit)->toBe('%')
            ->and($result->arguments[0]->items[0]->value)->toBeCloseTo(49.9314455845, 0.000000001);
    });

    it('preserves missing lightness when converting lch to oklch', function () {
        $result = $this->module->call('to-space', [
            new FunctionNode('lch', [new ListNode([
                new StringNode('none'),
                new NumberNode(10, '%'),
                new NumberNode(30, 'deg'),
            ], 'space')]),
            new StringNode('oklch'),
        ], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('oklch');
    });

    it('preserves missing lightness when converting oklch to lch', function () {
        $result = $this->module->call('to-space', [
            new FunctionNode('oklch', [new ListNode([
                new StringNode('none'),
                new NumberNode(0.2),
                new NumberNode(120, 'deg'),
            ], 'space')]),
            new StringNode('lch'),
        ], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)->and($result->name)->toBe('lch');
    });

    it('returns same generic color space unchanged to preserve missing channels', function () {
        $result = $this->module->call('to-space', [
            new FunctionNode('color', [new ListNode([
                new StringNode('rec2020'),
                new NumberNode(1),
                new StringNode('none'),
                new NumberNode(0.3),
            ], 'space')]),
            new StringNode('rec2020'),
        ], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)->and($result->name)->toBe('color');
    });

    it('evaluates transparentize', function () {
        $result = $this->module->call('transparentize', [new ColorNode('#112233cc'), new NumberNode(0.2)], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgba')
            ->and($result->arguments[0]->value)->toBe(17.0)
            ->and($result->arguments[1]->value)->toBe(34.0)
            ->and($result->arguments[2]->value)->toBe(51.0)
            ->and($result->arguments[3]->value)->toBe(0.6);
    });

    it('evaluates fade-out alias', function () {
        $result = $this->module->call('fade-out', [new ColorNode('#112233cc'), new NumberNode(0.2)], []);
        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgba')
            ->and($result->arguments[0]->value)->toBe(17.0)
            ->and($result->arguments[1]->value)->toBe(34.0)
            ->and($result->arguments[2]->value)->toBe(51.0)
            ->and($result->arguments[3]->value)->toBe(0.6);
    });

    it('evaluates whiteness', function () {
        $result = $this->module->call('whiteness', [new ColorNode('#336699')], []);
        expect($result->value)->toBeCloseTo(20.0, 0.001)->and($result->unit)->toBe('%');
    });

    describe('non-legacy color space support', function () {
        it('adjusts lab() channels natively', function () {
            $lab = new FunctionNode('lab', [new NumberNode(40, '%'), new NumberNode(30), new NumberNode(40)]);
            $result = $this->module->call('adjust', [$lab], [
                'lightness' => new NumberNode(10, '%'),
                'a'         => new NumberNode(-20),
            ]);
            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('lab')
                ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
                ->and($result->arguments[0]->items[0]->value)->toBe(50.0)
                ->and($result->arguments[0]->items[0]->unit)->toBe('%')
                ->and($result->arguments[0]->items[1]->value)->toBe(10.0)
                ->and($result->arguments[0]->items[2]->value)->toBe(40.0);
        });

        it('changes color(srgb) channels in 0-1 range', function () {
            $srgb = new FunctionNode('color', [
                new StringNode('srgb'),
                new NumberNode(0),
                new NumberNode(0.2),
                new NumberNode(0.4),
            ]);
            $result = $this->module->call('change', [$srgb], [
                'red'  => new NumberNode(0.8),
                'blue' => new NumberNode(0.1),
            ]);
            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('color')
                ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
                ->and($result->arguments[0]->items[0]->value)->toBe('srgb')
                ->and($result->arguments[0]->items[1]->value)->toBe(0.8)
                ->and($result->arguments[0]->items[2]->value)->toBe(0.2)
                ->and($result->arguments[0]->items[3]->value)->toBe(0.1);
        });

        it('computes complement of oklch() natively', function () {
            $oklch = new FunctionNode('oklch', [
                new NumberNode(50, '%'),
                new NumberNode(0.12),
                new NumberNode(70, 'deg'),
            ]);
            $result = $this->module->call('complement', [$oklch, new StringNode('oklch')], []);
            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('oklch')
                ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
                ->and($result->arguments[0]->items[0]->value)->toBe(50.0)
                ->and($result->arguments[0]->items[0]->unit)->toBe('%')
                ->and($result->arguments[0]->items[1]->value)->toBe(0.12)
                ->and($result->arguments[0]->items[2]->value)->toBe(250.0)
                ->and($result->arguments[0]->items[2]->unit)->toBe('deg');
        });

        it('grayscales oklch() by zeroing chroma', function () {
            $oklch = new FunctionNode('oklch', [
                new NumberNode(50, '%'),
                new NumberNode(80, '%'),
                new NumberNode(270, 'deg'),
            ]);
            $result = $this->module->call('grayscale', [$oklch], []);
            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('oklch')
                ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
                ->and($result->arguments[0]->items[0]->value)->toBe(50.0)
                ->and($result->arguments[0]->items[0]->unit)->toBe('%')
                ->and($result->arguments[0]->items[1]->value)->toBe(0)
                ->and($result->arguments[0]->items[2]->value)->toBe(270.0)
                ->and($result->arguments[0]->items[2]->unit)->toBe('deg');
        });
    });
});
