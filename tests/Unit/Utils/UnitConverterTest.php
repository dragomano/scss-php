<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\UnitConverter;

it('checks compatible units', function () {
    expect(UnitConverter::compatible('in', 'px'))->toBeTrue()
        ->and(UnitConverter::compatible('s', 'ms'))->toBeTrue()
        ->and(UnitConverter::compatible('in', 's'))->toBeFalse()
        ->and(UnitConverter::compatible(null, 'px'))->toBeTrue()
        ->and(UnitConverter::compatible(null, null))->toBeTrue();
})->covers(UnitConverter::class);

it('converts values between compatible units', function () {
    expect(UnitConverter::convert(6.0, 'px', 'in'))->toEqualWithDelta(0.0625, 0.000001)
        ->and(UnitConverter::convert(250.0, 'ms', 's'))->toEqualWithDelta(0.25, 0.000001)
        ->and(UnitConverter::convert(10.0, 'px', null))->toBe(10.0)
        ->and(UnitConverter::convert(10.0, 'px', 's'))->toBe(10.0);
})->covers(UnitConverter::class);

it('combines units for multiplication', function () {
    expect(UnitConverter::multiply('px', 'px'))->toBe('px*px')
        ->and(UnitConverter::multiply('deg/s', 's'))->toBe('deg')
        ->and(UnitConverter::multiply(null, 'px'))->toBe('px');
})->covers(UnitConverter::class);

it('combines units for division', function () {
    expect(UnitConverter::divide('px', 's'))->toBe('px/s')
        ->and(UnitConverter::divide(null, 'deg/s'))->toBe('s/deg')
        ->and(UnitConverter::divide('deg/s', 'deg'))->toBe('/s');
})->covers(UnitConverter::class);
