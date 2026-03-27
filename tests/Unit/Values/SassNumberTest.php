<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassNumber;

describe(SassNumber::class, function () {
    it('removes leading zero for decimals', function () {
        $number = new SassNumber(0.75);

        expect($number->toCss())->toBe('.75');
    });

    it('removes leading zero for negative decimals', function () {
        $number = new SassNumber(-0.5);

        expect($number->toCss())->toBe('-.5');
    });

    it('drops safe units for zero values', function () {
        $number = new SassNumber(0.0, 'px');

        expect($number->toCss())->toBe('0');
    });

    it('keeps percent unit for zero values', function () {
        $number = new SassNumber(0.0, '%');

        expect($number->toCss())->toBe('0%');
    });

    it('keeps unit for non zero values', function () {
        $number = new SassNumber(2.5, 'rem');

        expect($number->toCss())->toBe('2.5rem');
    });

    it('keeps only first ten digits after decimal point', function () {
        $number = new SassNumber(0.012345678912345);

        expect($number->toCss())->toBe('.0123456789');
    });

    it('does not coerce near-integer values to integer in css output', function () {
        $nearUpperInteger = new SassNumber(1.00000000009);
        $nearLowerInteger = new SassNumber(0.99999999991);

        expect($nearUpperInteger->toCss())->toBe('1.0000000001')
            ->and($nearLowerInteger->toCss())->toBe('.9999999999');
    });

    it('drops compound units for zero values unless preservation is requested', function () {
        $number = new SassNumber(0.0, 'px/s');

        expect($number->toCss())->toBe('0');
    });

    it('returns zero when a float rounds down to all zero fractional digits', function () {
        $number = new SassNumber(0.00000000001);

        expect($number->toCss())->toBe('0');
    });

    it('normalizes negative zero after trimming fractional digits', function () {
        $number = new SassNumber(-0.00000000001);

        expect($number->toCss())->toBe('0');
    });

    it('formats negative infinity with compound units as calc expression', function () {
        $number = new SassNumber(-INF, 'px/s');

        expect($number->toCss())->toBe('calc(-infinity * 1px / 1s)');
    });

    it('handles malformed compound units without adding an empty suffix', function () {
        $number = new SassNumber(2, '*');

        expect($number->toCss())->toBe('calc(2)');
    });
})->covers(SassNumber::class);
