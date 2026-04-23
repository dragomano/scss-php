<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\ColorModuleFactory;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;

describe('ColorFunctionEvaluator', function () {
    beforeEach(function () {
        $context = new ColorModuleContext(
            errorCtx: static fn(string $function): string => $function,
            isGlobalBuiltinCall: static fn(): bool => false,
            warn: static function (?BuiltinCallContext $callContext, string $message): void {
                $callContext?->warn($message);
            },
        );

        $this->evaluator = (new ColorModuleFactory())->create($context)->functions;
    });

    it('falls back to adjust-color for non-legacy hue and alpha adjustments', function () {
        $oklch     = new FunctionNode('oklch', [new NumberNode(50, '%'), new NumberNode(0.12), new NumberNode(70, 'deg')]);
        $withAlpha = new FunctionNode('oklch', [new ListNode([
            new NumberNode(50, '%'),
            new NumberNode(0.12),
            new NumberNode(70, 'deg'),
            new StringNode('/'),
            new NumberNode(0.5),
        ], 'space')]);

        $adjustedHue   = $this->evaluator->adjustHue([$oklch, new NumberNode(45, 'deg')], null);
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
            'whiteness',
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

    it('falls back from hsl-space mixing when colors cannot expose hsl channels with missing values', function () {
        $result = $this->evaluator->mix([
            new FunctionNode('color', [new ListNode([
                new StringNode('display-p3'),
                new NumberNode(0.4),
                new NumberNode(0.2),
                new NumberNode(0.6),
            ], 'space')]),
            new ColorNode('#000'),
        ], [
            'method' => new StringNode('hsl'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb');
    });

    it('changes native lab colors through the public changeColor api', function () {
        $lab = new FunctionNode('lab', [new NumberNode(40, '%'), new NumberNode(30), new NumberNode(40)]);

        $changed = $this->evaluator->changeColor([$lab], ['lightness' => new NumberNode(10, '%')]);

        expect($changed)->toBeInstanceOf(FunctionNode::class)
            ->and($changed->name)->toBe('lab');
    });

    it('applies lab modifications to legacy and non-legacy colors through public changeColor calls', function () {
        $legacyRgb = $this->evaluator->changeColor([new ColorNode('#336699')], [
            'space' => new StringNode('lab'),
            'lightness' => new NumberNode(10, '%'),
        ]);

        $floatRgb = $this->evaluator->changeColor([
            new FunctionNode('color', [new ListNode([
                new StringNode('display-p3'),
                new NumberNode(0.4),
                new NumberNode(0.2),
                new NumberNode(0.6),
            ], 'space')]),
        ], [
            'space' => new StringNode('lab'),
            'lightness' => new NumberNode(10, '%'),
        ]);

        expect($floatRgb)->toBeInstanceOf(FunctionNode::class)
            ->and($floatRgb->name)->toBe('rgb')
            ->and($legacyRgb)->toBeInstanceOf(ColorNode::class);
    });

    it('adjusts lab modifications for legacy colors through public adjustColor calls', function () {
        $result = $this->evaluator->adjustColor([
            new ColorNode('#336699'),
        ], [
            'space' => new StringNode('lab'),
            'lightness' => new NumberNode(10, '%'),
        ]);

        expect($result)->toBeInstanceOf(ColorNode::class);
    });

    it('emits zero scale suggestions when no alpha range remains', function () {
        $warnings = [];
        $context  = new BuiltinCallContext(
            logWarning: static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
        );

        $this->evaluator->adjustAlphaChannel(
            [new ColorNode('#112233'), new NumberNode(0.2)],
            1,
            'fade-in',
            $context,
        );

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('color.scale(')
            ->and($warnings[0])->toContain('$alpha: 0%');
    });

    it('falls back to rgb mixing for blank mix methods', function () {
        $result = $this->evaluator->mix([
            new ColorNode('#000'),
            new ColorNode('#fff'),
            new NumberNode(50, '%'),
            new StringNode('   '),
        ], []);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb');
    });

    it('handles missing oklch channels and hues through public mix calls', function () {
        $result = $this->evaluator->mix([
            new FunctionNode('oklch', [new ListNode([
                new StringNode('none'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(50, '%'),
                new NumberNode(0.2),
                new StringNode('none'),
            ], 'space')]),
        ], [
            'method' => new StringNode('oklch'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('oklch');
    });

    it('preserves missing oklch channels and reuses present channels during mixing', function () {
        $bothMissing = $this->evaluator->mix([
            new FunctionNode('oklch', [new ListNode([
                new StringNode('none'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
            new FunctionNode('oklch', [new ListNode([
                new StringNode('none'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
        ], [
            'method' => new StringNode('oklch'),
        ]);

        $rightMissingChannel = $this->evaluator->mix([
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(50, '%'),
                new NumberNode(0.2),
                new NumberNode(80, 'deg'),
            ], 'space')]),
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(20, '%'),
                new StringNode('none'),
                new NumberNode(10, 'deg'),
            ], 'space')]),
        ], [
            'method' => new StringNode('oklch'),
        ]);

        $rightMissingHue = $this->evaluator->mix([
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(50, '%'),
                new NumberNode(0.2),
                new NumberNode(80, 'deg'),
            ], 'space')]),
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(20, '%'),
                new NumberNode(0.1),
                new StringNode('none'),
            ], 'space')]),
        ], [
            'method' => new StringNode('oklch'),
        ]);

        expect($bothMissing)->toBeInstanceOf(FunctionNode::class)
            ->and($bothMissing->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($bothMissing->arguments[0]->items[0])->toBeInstanceOf(StringNode::class)
            ->and($bothMissing->arguments[0]->items[0]->value)->toBe('none')
            ->and($bothMissing->arguments[0]->items[1])->toBeInstanceOf(StringNode::class)
            ->and($bothMissing->arguments[0]->items[1]->value)->toBe('none')
            ->and($bothMissing->arguments[0]->items[2])->toBeInstanceOf(StringNode::class)
            ->and($bothMissing->arguments[0]->items[2]->value)->toBe('none')
            ->and($rightMissingChannel)->toBeInstanceOf(FunctionNode::class)
            ->and($rightMissingChannel->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($rightMissingChannel->arguments[0]->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($rightMissingChannel->arguments[0]->items[1]->value)->toBe(0.2)
            ->and($rightMissingHue)->toBeInstanceOf(FunctionNode::class)
            ->and($rightMissingHue->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($rightMissingHue->arguments[0]->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($rightMissingHue->arguments[0]->items[2]->value)->toBe(80.0);
    });

    it('mixes rec2020 channels with missing values through the public mix api', function () {
        $mixed = $this->evaluator->mix([
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
        ], [
            'method' => new StringNode('rec2020'),
        ]);

        expect($mixed)->toBeInstanceOf(FunctionNode::class)
            ->and($mixed->name)->toBe('color')
            ->and($mixed->arguments)->toHaveCount(1);
    });

    it('mixes rec2020 channels from rgb fallbacks and percent channel inputs', function () {
        $fallback = $this->evaluator->mix([
            new ColorNode('#036'),
            new ColorNode('#369'),
        ], [
            'method' => new StringNode('rec2020'),
        ]);

        $percentChannel = $this->evaluator->mix([
            new FunctionNode('color', [new ListNode([
                new StringNode('rec2020'),
                new NumberNode(25, '%'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
            new FunctionNode('color', [new ListNode([
                new StringNode('rec2020'),
                new StringNode('none'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
        ], [
            'method' => new StringNode('rec2020'),
        ]);

        expect($fallback)->toBeInstanceOf(FunctionNode::class)
            ->and($fallback->name)->toBe('color')
            ->and($fallback->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($fallback->arguments[0]->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($fallback->arguments[0]->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($fallback->arguments[0]->items[3])->toBeInstanceOf(NumberNode::class)
            ->and($percentChannel)->toBeInstanceOf(FunctionNode::class)
            ->and($percentChannel->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($percentChannel->arguments[0]->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($percentChannel->arguments[0]->items[1]->value)->toBe(0.25);
    });
});
