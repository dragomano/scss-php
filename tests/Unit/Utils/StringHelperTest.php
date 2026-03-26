<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\StringHelper;

describe('StringHelper', function () {
    describe('isQuoted()', function () {
        it('detects double-quoted string', function () {
            expect(StringHelper::isQuoted('"hello"'))->toBeTrue();
        });

        it('detects single-quoted string', function () {
            expect(StringHelper::isQuoted("'hello'"))->toBeTrue();
        });

        it('returns false for unquoted string', function () {
            expect(StringHelper::isQuoted('value'))->toBeFalse();
        });

        it('returns false for single character', function () {
            expect(StringHelper::isQuoted('"'))->toBeFalse();
        });

        it('returns false for mismatched quotes', function () {
            expect(StringHelper::isQuoted('"value\''))->toBeFalse();
        });

        it('returns false for empty string', function () {
            expect(StringHelper::isQuoted(''))->toBeFalse();
        });

        it('returns true for empty double-quoted string', function () {
            expect(StringHelper::isQuoted('""'))->toBeTrue();
        });
    });

    describe('hasMatchingQuotes()', function () {
        it('returns true for matching double quotes', function () {
            expect(StringHelper::hasMatchingQuotes('"text"'))->toBeTrue();
        });

        it('returns true for matching single quotes', function () {
            expect(StringHelper::hasMatchingQuotes("'text'"))->toBeTrue();
        });

        it('returns false for string shorter than 2 chars', function () {
            expect(StringHelper::hasMatchingQuotes("'"))->toBeFalse();
        });

        it('returns false for mismatched quotes', function () {
            expect(StringHelper::hasMatchingQuotes('"text\''))->toBeFalse();
        });

        it('returns false when no quotes at all', function () {
            expect(StringHelper::hasMatchingQuotes('hello'))->toBeFalse();
        });

        it('returns false for empty string', function () {
            expect(StringHelper::hasMatchingQuotes(''))->toBeFalse();
        });
    });

    describe('unquote()', function () {
        it('removes double quotes', function () {
            expect(StringHelper::unquote('"hello"'))->toBe('hello');
        });

        it('removes single quotes', function () {
            expect(StringHelper::unquote("'world'"))->toBe('world');
        });

        it('returns unquoted string unchanged', function () {
            expect(StringHelper::unquote('plain'))->toBe('plain');
        });

        it('returns single quote char unchanged (too short)', function () {
            expect(StringHelper::unquote('"'))->toBe('"');
        });

        it('returns empty double-quoted string as empty string', function () {
            expect(StringHelper::unquote('""'))->toBe('');
        });

        it('returns mismatched-quote string unchanged', function () {
            expect(StringHelper::unquote('"value\''))->toBe('"value\'');
        });
    });

    describe('unescapeQuotedContent()', function () {
        it('unescapes escaped backslash', function () {
            expect(StringHelper::unescapeQuotedContent('\\\\'))->toBe('\\');
        });

        it('unescapes escaped double quote', function () {
            expect(StringHelper::unescapeQuotedContent('\\"'))->toBe('"');
        });

        it('unescapes escaped single quote', function () {
            expect(StringHelper::unescapeQuotedContent("\\'"))->toBe("'");
        });

        it('keeps unsupported escape sequence unchanged', function () {
            expect(StringHelper::unescapeQuotedContent('\n\q'))->toBe('\n\q');
        });

        it('returns plain text unchanged', function () {
            expect(StringHelper::unescapeQuotedContent('hello'))->toBe('hello');
        });

        it('handles trailing backslash at end of string unchanged', function () {
            expect(StringHelper::unescapeQuotedContent('a\\'))->toBe('a\\');
        });
    });
});
