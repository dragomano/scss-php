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

it('caches compatible results for repeated calls', function () {
    expect(UnitConverter::compatible('in', 'px'))->toBeTrue()
        ->and(UnitConverter::compatible('in', 'px'))->toBeTrue();
})->covers(UnitConverter::class);

it('returns true for same units in compatible check', function () {
    expect(UnitConverter::compatible('px', 'px'))->toBeTrue();
})->covers(UnitConverter::class);

it('converts with null fromUnit returns value unchanged', function () {
    expect(UnitConverter::convert(10.0, null, 'px'))->toBe(10.0);
})->covers(UnitConverter::class);

it('converts with same from and to unit returns value unchanged', function () {
    expect(UnitConverter::convert(10.0, 'px', 'px'))->toBe(10.0);
})->covers(UnitConverter::class);

it('converts with incompatible groups returns value unchanged', function () {
    expect(UnitConverter::convert(10.0, 'px', 's'))->toBe(10.0);
})->covers(UnitConverter::class);

it('converts with unknown unit returns value unchanged', function () {
    expect(UnitConverter::convert(10.0, 'px', 'unknown'))->toBe(10.0)
        ->and(UnitConverter::convert(10.0, 'unknown', 'px'))->toBe(10.0);
})->covers(UnitConverter::class);

it('multiplies compound units with denominator', function () {
    expect(UnitConverter::multiply('px*px', 'px'))->toBe('px*px*px');
})->covers(UnitConverter::class);

it('divides compound units with denominator', function () {
    expect(UnitConverter::divide('px*px', 'px'))->toBe('px');
})->covers(UnitConverter::class);

it('parses units with multiplication operator', function () {
    $result = UnitConverter::parseParts('px*px');
    expect($result[0])->toBe(['px', 'px'])
        ->and($result[1])->toBe([]);
})->covers(UnitConverter::class);

it('parses units with division operator', function () {
    $result = UnitConverter::parseParts('px/s');
    expect($result[0])->toBe(['px'])
        ->and($result[1])->toBe(['s']);
})->covers(UnitConverter::class);

it('parses units with both operators', function () {
    $result = UnitConverter::parseParts('px*px/s');
    expect($result[0])->toBe(['px', 'px'])
        ->and($result[1])->toBe(['s']);
})->covers(UnitConverter::class);

it('caches parseParts results', function () {
    $a = UnitConverter::parseParts('px/s');
    $b = UnitConverter::parseParts('px/s');
    expect($a)->toBe($b);
})->covers(UnitConverter::class);

it('returns null when multiply cancels all parts', function () {
    expect(UnitConverter::multiply('px/px', 'px/px'))->toBeNull();
})->covers(UnitConverter::class);

it('returns null when divide cancels all parts', function () {
    expect(UnitConverter::divide('px/px', 'px/px'))->toBeNull();
})->covers(UnitConverter::class);

it('returns false when only one unit is known in compatible check', function () {
    expect(UnitConverter::compatible('unknown', 'px'))->toBeFalse();
})->covers(UnitConverter::class);

it('applies conversion factor when cancelling compatible units', function () {
    [$unit, $factor] = UnitConverter::multiplyWithConversion('px/in', 'cm');

    expect($unit)->toBe('cm')
        ->and($factor)->toEqualWithDelta(1.0 / 96.0, 0.000001);
})->covers(UnitConverter::class);
