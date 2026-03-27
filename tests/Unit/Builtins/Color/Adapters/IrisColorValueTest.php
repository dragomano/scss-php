<?php

declare(strict_types=1);

use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Adapters\IrisColorValue;

describe('IrisColorValue', function () {
    it('delegates color metadata access to the wrapped iris color', function () {
        $inner = new RgbColor(255.0, 128.0, 64.0, 0.5);
        $value = new IrisColorValue($inner);

        expect($value->getSpace())->toBe('rgb')
            ->and($value->getChannels())->toBe([255.0, 128.0, 64.0])
            ->and($value->getAlpha())->toBe(0.5)
            ->and($value->getInner())->toBe($inner);
    });
});
