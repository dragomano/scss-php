<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\SelectorHelper;

describe('SelectorHelper', function () {
    describe('splitList()', function () {
        it('splits comma-separated selectors', function () {
            expect(SelectorHelper::splitList('a, b, c'))->toBe(['a', 'b', 'c']);
        });

        it('trims whitespace from each part', function () {
            expect(SelectorHelper::splitList('  .foo  ,  .bar  '))->toBe(['.foo', '.bar']);
        });

        it('filters empty parts by default', function () {
            expect(SelectorHelper::splitList('a,,b'))->toBe(['a', 'b']);
        });

        it('keeps empty parts when filterEmpty is false', function () {
            expect(SelectorHelper::splitList('a,,b', filterEmpty: false))->toBe(['a', '', 'b']);
        });

        it('handles single selector without commas', function () {
            expect(SelectorHelper::splitList('.foo'))->toBe(['.foo']);
        });

        it('returns empty array for empty string', function () {
            expect(SelectorHelper::splitList(''))->toBe([]);
        });

        it('returns array with empty string when filterEmpty is false and input is empty', function () {
            expect(SelectorHelper::splitList('', filterEmpty: false))->toBe(['']);
        });

        it('reindexes array after filtering', function () {
            $result = SelectorHelper::splitList('a,,b');

            expect($result)->toBe(['a', 'b'])
                ->and(array_keys($result))->toBe([0, 1]);
        });
    });
});
