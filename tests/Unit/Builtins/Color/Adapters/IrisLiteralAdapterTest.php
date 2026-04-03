<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\Adapters\IrisLiteralAdapter;
use Bugo\SCSS\Contracts\Color\ColorValueInterface;

describe('IrisLiteralAdapter', function () {
    it('throws when serialize() receives a non-rgb wrapped value', function () {
        $adapter = new IrisLiteralAdapter();
        $color = new class implements ColorValueInterface {
            public function getSpace(): string
            {
                return 'fake';
            }

            public function getChannels(): array
            {
                return [];
            }

            public function getAlpha(): float
            {
                return 1.0;
            }

            public function getInner(): object
            {
                return new stdClass();
            }
        };

        expect(fn() => $adapter->serialize($color))->toThrow(
            LogicException::class,
            'Expected RgbColor inside IrisColorValue, got stdClass',
        );
    });
});
