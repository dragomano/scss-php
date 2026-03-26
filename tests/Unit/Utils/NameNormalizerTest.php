<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\NameNormalizer;

describe('NameNormalizer', function () {
    describe('normalize()', function () {
        it('replaces underscores with hyphens', function () {
            expect(NameNormalizer::normalize('foo_bar'))->toBe('foo-bar');
        });

        it('replaces multiple underscores', function () {
            expect(NameNormalizer::normalize('foo_bar_baz'))->toBe('foo-bar-baz');
        });

        it('leaves hyphens unchanged', function () {
            expect(NameNormalizer::normalize('foo-bar'))->toBe('foo-bar');
        });

        it('returns empty string unchanged', function () {
            expect(NameNormalizer::normalize(''))->toBe('');
        });

        it('handles mixed underscores and hyphens', function () {
            expect(NameNormalizer::normalize('foo_bar-baz_qux'))->toBe('foo-bar-baz-qux');
        });

        it('normalizes name without underscores unchanged', function () {
            expect(NameNormalizer::normalize('primary'))->toBe('primary');
        });
    });

    describe('isPrivate()', function () {
        it('returns true for names starting with hyphen', function () {
            expect(NameNormalizer::isPrivate('-private'))->toBeTrue();
        });

        it('returns true for names starting with underscore (normalized to hyphen)', function () {
            expect(NameNormalizer::isPrivate('_private'))->toBeTrue();
        });

        it('returns false for regular names', function () {
            expect(NameNormalizer::isPrivate('public'))->toBeFalse();
        });

        it('returns false for empty string', function () {
            expect(NameNormalizer::isPrivate(''))->toBeFalse();
        });

        it('returns false for names starting with letter', function () {
            expect(NameNormalizer::isPrivate('my-var'))->toBeFalse();
        });
    });
});
