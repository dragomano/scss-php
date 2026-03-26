<?php

declare(strict_types=1);

use Bugo\SCSS\Style;

describe('Style', function () {
    it('has EXPANDED case', function () {
        expect(Style::EXPANDED)->toBeInstanceOf(Style::class);
    });

    it('has COMPRESSED case', function () {
        expect(Style::COMPRESSED)->toBeInstanceOf(Style::class);
    });

    it('EXPANDED and COMPRESSED are distinct', function () {
        expect(Style::EXPANDED)->not->toBe(Style::COMPRESSED);
    });

    it('cases() returns both styles', function () {
        $cases = Style::cases();
        expect($cases)->toHaveCount(2);

        $names = array_map(fn(Style $s) => $s->name, $cases);
        expect($names)->toContain('EXPANDED')
            ->and($names)->toContain('COMPRESSED');
    });
});
