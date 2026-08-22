<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\StringEscapeDecoder;

describe('StringEscapeDecoder', function () {
    describe('decodeLiteral()', function () {
        it('passes plain text through', function () {
            expect(StringEscapeDecoder::decodeLiteral('hello world'))->toBe('hello world');
        });

        it('decodes a short hex escape', function () {
            expect(StringEscapeDecoder::decodeLiteral('\41'))->toBe('A');
        });

        it('decodes hex escape and consumes one whitespace terminator', function () {
            expect(StringEscapeDecoder::decodeLiteral('a\41 b'))->toBe('aAb');
            expect(StringEscapeDecoder::decodeLiteral('a\41' . "\tb"))->toBe('aAb');
        });

        it('does not consume non-whitespace after hex escape', function () {
            expect(StringEscapeDecoder::decodeLiteral('\41x'))->toBe('Ax');
        });

        it('keeps extra whitespace after the terminator', function () {
            expect(StringEscapeDecoder::decodeLiteral('\41  x'))->toBe('A x');
        });

        it('caps hex parsing at six digits', function () {
            expect(StringEscapeDecoder::decodeLiteral('\000041'))->toBe('A');
            expect(StringEscapeDecoder::decodeLiteral('\0000041'))->toBe("\u{4}1");
        });

        it('replaces invalid code points with replacement character', function () {
            expect(StringEscapeDecoder::decodeLiteral('\0z'))->toBe("\u{FFFD}z");
            expect(StringEscapeDecoder::decodeLiteral('\D800'))->toBe("\u{FFFD}");
            expect(StringEscapeDecoder::decodeLiteral('\2000000'))->toBe("\u{FFFD}0");
        });

        it('decodes astral plane code points to UTF-8', function () {
            expect(StringEscapeDecoder::decodeLiteral('\1F600'))->toBe("\u{1F600}");
            expect(StringEscapeDecoder::decodeLiteral('\f112'))->toBe("\u{F112}");
        });

        it('drops the backslash before plain characters', function () {
            expect(StringEscapeDecoder::decodeLiteral('l\ite\ral'))->toBe('literal');
            expect(StringEscapeDecoder::decodeLiteral('\"'))->toBe('"');
        });

        it('removes line continuations', function () {
            expect(StringEscapeDecoder::decodeLiteral("a\\\nb"))->toBe('ab');
            expect(StringEscapeDecoder::decodeLiteral("a\\\r\nb"))->toBe('ab');
            expect(StringEscapeDecoder::decodeLiteral("a\\\nb\\\nc"))->toBe('abc');
        });

        it('keeps trailing lone backslash at end of input', function () {
            expect(StringEscapeDecoder::decodeLiteral('a\\'))->toBe('a\\');
        });

        it('preserves interpolation regions verbatim', function () {
            $raw = '#{$name}-#{"a}b"}';

            expect(StringEscapeDecoder::decodeLiteral($raw))->toBe($raw);
        });

        it('does not decode escapes inside interpolation regions', function () {
            expect(StringEscapeDecoder::decodeLiteral('#{"\41"}'))->toBe('#{"\41"}');
        });
    });

    describe('hexToUtf8()', function () {
        it('encodes ASCII range', function () {
            expect(StringEscapeDecoder::hexToUtf8('41'))->toBe('A');
        });

        it('encodes BMP and astral ranges', function () {
            expect(StringEscapeDecoder::hexToUtf8('F112'))->toBe("\u{F112}");
            expect(StringEscapeDecoder::hexToUtf8('10FFFF'))->toBe("\u{10FFFF}");
        });

        it('falls back to replacement character for invalid code points', function () {
            expect(StringEscapeDecoder::hexToUtf8('0'))->toBe("\u{FFFD}");
            expect(StringEscapeDecoder::hexToUtf8('D800'))->toBe("\u{FFFD}");
            expect(StringEscapeDecoder::hexToUtf8('DFFF'))->toBe("\u{FFFD}");
            expect(StringEscapeDecoder::hexToUtf8('110000'))->toBe("\u{FFFD}");
        });
    });
});
