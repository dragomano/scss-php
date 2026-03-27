<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\Operations\ColorFunctionEvaluator;
use Bugo\SCSS\Builtins\SassColorModule;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Tests\ReflectionAccessor;

describe('ColorFunctionEvaluator', function () {
    beforeEach(function () {
        $module = new SassColorModule();
        $this->accessor = new ReflectionAccessor($module);
        $this->evaluator = $this->accessor->getProperty('functions');

        expect($this->evaluator)->toBeInstanceOf(ColorFunctionEvaluator::class);
        /** @var ColorFunctionEvaluator $evaluator */
        $evaluator = $this->evaluator;
        $this->evaluatorAccessor = new ReflectionAccessor($evaluator);
    });

    it('falls back to adjust-color for non-legacy hue and alpha adjustments', function () {
        $oklch = new FunctionNode('oklch', [new NumberNode(50, '%'), new NumberNode(0.12), new NumberNode(70, 'deg')]);
        $withAlpha = new FunctionNode('oklch', [new ListNode([
            new NumberNode(50, '%'),
            new NumberNode(0.12),
            new NumberNode(70, 'deg'),
            new StringNode('/'),
            new NumberNode(0.5),
        ], 'space')]);

        $adjustedHue = $this->evaluator->adjustHue([$oklch, new NumberNode(45, 'deg')], null);
        $adjustedAlpha = $this->evaluator->adjustAlphaChannel([$withAlpha, new NumberNode(0.2)], 1, 'fade-in', null);

        expect($adjustedHue)->toBeInstanceOf(FunctionNode::class)
            ->and($adjustedHue->name)->toBe('oklch')
            ->and($adjustedAlpha)->toBeInstanceOf(FunctionNode::class)
            ->and($adjustedAlpha->name)->toBe('oklch');
    });

    it('falls back to adjust-color when percent adjustment cannot be handled as a legacy shortcut', function () {
        $result = $this->evaluator->adjustColorChannelByPercent(
            [new ColorNode('#112233'), new NumberNode(10, '%')],
            'whiteness',
            1,
            'whiteness'
        );

        expect($result)->toBeInstanceOf(ColorNode::class)
            ->and($result->value)->toBe('#112233');
    });

    it('grayscales non-srgb colors through float rgb serialization', function () {
        $displayP3 = new FunctionNode('color', [new ListNode([
            new StringNode('display-p3'),
            new NumberNode(0.4),
            new NumberNode(0.2),
            new NumberNode(0.6),
        ], 'space')]);

        $result = $this->evaluator->grayscale([$displayP3]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb');
    });

    it('mixes hsl colors when the right hue is missing', function () {
        $result = $this->evaluator->mix([
            new FunctionNode('hsl', [
                new NumberNode(120, 'deg'),
                new NumberNode(40, '%'),
                new NumberNode(50, '%'),
            ]),
            new FunctionNode('hsl', [new ListNode([
                new StringNode('none'),
                new NumberNode(60, '%'),
                new NumberNode(40, '%'),
            ], 'space')]),
        ], [
            'method' => new StringNode('hsl'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('hsl');
    });

    it('changes native lab colors and can serialize them as float rgb for legacy callers', function () {
        $lab = new FunctionNode('lab', [new NumberNode(40, '%'), new NumberNode(30), new NumberNode(40)]);

        $changed = $this->evaluator->changeColor([$lab], ['lightness' => new NumberNode(10, '%')]);
        $legacySerialized = $this->evaluatorAccessor->callMethod('applyInLabSpace', [
            $lab,
            ['lightness' => new NumberNode(10, '%')],
            static fn(float $current, float $value): float => $value,
            true,
        ]);

        expect($changed)->toBeInstanceOf(FunctionNode::class)
            ->and($changed->name)->toBe('lab')
            ->and($legacySerialized)->toBeInstanceOf(FunctionNode::class)
            ->and($legacySerialized->name)->toBe('rgb');
    });

    it('applies lab modifications to non-native colors for both legacy and non-legacy outputs', function () {
        $named = ['lightness' => new NumberNode(10, '%')];

        $floatRgb = $this->evaluatorAccessor->callMethod('applyInLabSpace', [
            new ColorNode('#336699'),
            $named,
            static fn(float $current, float $value): float => $value,
            false,
        ]);

        $legacyRgb = $this->evaluatorAccessor->callMethod('applyInLabSpace', [
            new ColorNode('#336699'),
            $named,
            static fn(float $current, float $value): float => $value,
            true,
        ]);

        expect($floatRgb)->toBeInstanceOf(FunctionNode::class)
            ->and($floatRgb->name)->toBe('rgb')
            ->and($legacyRgb)->toBeInstanceOf(ColorNode::class);
    });

    it('returns zero scale suggestions when no channel range remains', function () {
        $hint = $this->evaluatorAccessor->callMethod('buildScaleSuggestion', [
            new ColorNode('#112233'),
            'alpha',
            1,
            0.2,
        ]);

        expect($hint)->toContain('color.scale(')
            ->and($hint)->toContain('$alpha: 0%');
    });

    it('falls back to rgb method resolution for blank mix methods', function () {
        $resolved = $this->evaluatorAccessor->callMethod('resolveMixMethod', [
            [],
            [
                new ColorNode('#000'),
                new ColorNode('#fff'),
                new NumberNode(50, '%'),
                new StringNode('   '),
            ],
        ]);

        expect($resolved)->toBe([
            'space' => 'rgb',
            'hue' => null,
        ]);
    });

    it('handles missing oklch channels and hues in mix helpers', function () {
        $bothMissingChannel = $this->evaluatorAccessor->callMethod('mixPossiblyMissingChannel', [1.0, 2.0, true, true, 0.5]);
        $rightMissingChannel = $this->evaluatorAccessor->callMethod('mixPossiblyMissingChannel', [1.0, 2.0, false, true, 0.5]);
        $bothMissingHue = $this->evaluatorAccessor->callMethod('mixPossiblyMissingHue', [10.0, 20.0, true, true, 0.5, null]);
        $rightMissingHue = $this->evaluatorAccessor->callMethod('mixPossiblyMissingHue', [10.0, 20.0, false, true, 0.5, null]);

        expect($bothMissingChannel)->toBe(['value' => 0.0, 'missing' => true])
            ->and($rightMissingChannel)->toBe(['value' => 1.0, 'missing' => false])
            ->and($bothMissingHue)->toBe(['value' => 0.0, 'missing' => true])
            ->and($rightMissingHue)->toBe(['value' => 10.0, 'missing' => false]);
    });

    it('mixes rec2020 channels with missing values and falls back to rgb conversions', function () {
        $mixed = $this->evaluatorAccessor->callMethod('mixColorSpaceChannels', [
            new FunctionNode('color', [new ListNode([
                new StringNode('rec2020'),
                new StringNode('none'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
            new FunctionNode('color', [new ListNode([
                new StringNode('rec2020'),
                new NumberNode(0.8),
                new NumberNode(0.2),
                new StringNode('none'),
            ], 'space')]),
            0.5,
        ]);

        $fallbackChannels = $this->evaluatorAccessor->callMethod('extractColorSpaceChannels', [new ColorNode('#036')]);
        $percentChannel = $this->evaluatorAccessor->callMethod('extractOptionalNumericChannel', [new NumberNode(25, '%')]);

        expect($mixed)->toBeInstanceOf(FunctionNode::class)
            ->and($mixed->name)->toBe('color')
            ->and($fallbackChannels)->toHaveCount(3)
            ->and($percentChannel)->toBe(0.25);
    });
});
