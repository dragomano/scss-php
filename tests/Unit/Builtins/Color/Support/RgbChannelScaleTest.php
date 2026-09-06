<?php

declare(strict_types=1);

use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Support\RgbChannelScale;

describe('RgbChannelScale', function (): void {
    it('converts byte channels to the normalized range', function (): void {
        $normalized = RgbChannelScale::toNormalized(new RgbColor(r: 51.0, g: 102.0, b: 153.0, a: 0.5));

        expect($normalized->rValue())->toBeCloseTo(0.2, 10)
            ->and($normalized->gValue())->toBeCloseTo(0.4, 10)
            ->and($normalized->bValue())->toBeCloseTo(0.6, 10)
            ->and($normalized->a)->toBe(0.5);
    });

    it('converts normalized channels back to bytes', function (): void {
        $bytes = RgbChannelScale::toByte(new RgbColor(r: 0.2, g: 0.4, b: 0.6, a: 0.5));

        expect($bytes->rValue())->toBeCloseTo(51.0, 10)
            ->and($bytes->gValue())->toBeCloseTo(102.0, 10)
            ->and($bytes->bValue())->toBeCloseTo(153.0, 10)
            ->and($bytes->a)->toBe(0.5);
    });

    it('round-trips byte channels without loss', function (): void {
        $rgb = new RgbColor(r: 200.0, g: 10.0, b: 45.0, a: 1.0);

        $roundTrip = RgbChannelScale::toByte(RgbChannelScale::toNormalized($rgb));

        expect($roundTrip->rValue())->toBeCloseTo($rgb->rValue(), 10)
            ->and($roundTrip->gValue())->toBeCloseTo($rgb->gValue(), 10)
            ->and($roundTrip->bValue())->toBeCloseTo($rgb->bValue(), 10);
    });
});
