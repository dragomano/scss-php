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
            expect(StringEscapeDecoder::decodeLiteral('a\41 b'))->toBe('aAb')
                ->and(StringEscapeDecoder::decodeLiteral('a\41' . "\tb"))->toBe('aAb');
        });

        it('does not consume non-whitespace after hex escape', function () {
            expect(StringEscapeDecoder::decodeLiteral('\41x'))->toBe('Ax');
        });

        it('keeps extra whitespace after the terminator', function () {
            expect(StringEscapeDecoder::decodeLiteral('\41  x'))->toBe('A x');
        });

        it('caps hex parsing at six digits', function () {
            expect(StringEscapeDecoder::decodeLiteral('\000041'))->toBe('A')
                ->and(StringEscapeDecoder::decodeLiteral('\0000041'))->toBe("\u{4}1");
        });

        it('replaces invalid code points with replacement character', function () {
            expect(StringEscapeDecoder::decodeLiteral('\0z'))->toBe("\u{FFFD}z")
                ->and(StringEscapeDecoder::decodeLiteral('\D800'))->toBe("\u{FFFD}")
                ->and(StringEscapeDecoder::decodeLiteral('\2000000'))->toBe("\u{FFFD}0");
        });

        it('decodes astral plane code points to UTF-8', function () {
            expect(StringEscapeDecoder::decodeLiteral('\1F600'))->toBe("\u{1F600}")
                ->and(StringEscapeDecoder::decodeLiteral('\f112'))->toBe("\u{F112}");
        });

        it('drops the backslash before plain characters', function () {
            expect(StringEscapeDecoder::decodeLiteral('l\ite\ral'))->toBe('literal')
                ->and(StringEscapeDecoder::decodeLiteral('\"'))->toBe('"');
        });

        it('removes line continuations', function () {
            expect(StringEscapeDecoder::decodeLiteral("a\\\nb"))->toBe('ab')
                ->and(StringEscapeDecoder::decodeLiteral("a\\\r\nb"))->toBe('ab')
                ->and(StringEscapeDecoder::decodeLiteral("a\\\nb\\\nc"))->toBe('abc');
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

        it('copies a bare hash verbatim', function () {
            expect(StringEscapeDecoder::decodeLiteral('a#b'))->toBe('a#b');
        });
    });

    describe('protectHashes()', function () {
        it('replaces a backslash-protected hash with the sentinel', function () {
            expect(StringEscapeDecoder::protectHashes('\#{x}'))
                ->toBe(StringEscapeDecoder::PROTECTED_HASH . '{x}');
        });

        it('keeps live interpolation regions untouched', function () {
            expect(StringEscapeDecoder::protectHashes('#{x}'))->toBe('#{x}');
        });

        it('treats an even backslash run as escaped backslash before a live region', function () {
            $raw = str_repeat('\\', 2) . '#{x}';

            expect(StringEscapeDecoder::protectHashes($raw))->toBe($raw);
        });

        it('protects a hash after an odd backslash run longer than one', function () {
            $raw     = str_repeat('\\', 3) . '#{x}';
            $escaped = str_repeat('\\', 2) . StringEscapeDecoder::PROTECTED_HASH;

            expect(StringEscapeDecoder::protectHashes($raw))->toBe($escaped . '{x}');
        });

        it('leaves hashes without a following brace untouched', function () {
            expect(StringEscapeDecoder::protectHashes('a\#b'))->toBe('a\#b');
        });

        it('is idempotent for text that already contains sentinels', function () {
            $protected = StringEscapeDecoder::protectHashes('\#{x}');

            expect(StringEscapeDecoder::protectHashes($protected))->toBe($protected);
        });

        it('protects every occurrence independently', function () {
            $sentinel = StringEscapeDecoder::PROTECTED_HASH;

            expect(StringEscapeDecoder::protectHashes('\#{a}\#{b}'))
                ->toBe($sentinel . '{a}' . $sentinel . '{b}');
        });
    });

    describe('restoreHashes()', function () {
        it('turns every sentinel back into a literal hash', function () {
            $sentinel = StringEscapeDecoder::PROTECTED_HASH;

            expect(StringEscapeDecoder::restoreHashes($sentinel . '{a}' . $sentinel . '{b}'))
                ->toBe('#{a}#{b}');
        });

        it('leaves text without sentinels untouched', function () {
            expect(StringEscapeDecoder::restoreHashes('.a{color:red}'))->toBe('.a{color:red}');
        });
    });

    describe('encodeQuotedContent()', function () {
        it('passes the protected hash through verbatim', function () {
            expect(StringEscapeDecoder::encodeQuotedContent(StringEscapeDecoder::PROTECTED_HASH . 'x', '"'))
                ->toBe(StringEscapeDecoder::PROTECTED_HASH . 'x');
        });
    });

    describe('skipInterpolation()', function () {
        it('treats a bare opening brace as nesting depth', function () {
            expect(StringEscapeDecoder::skipInterpolation('#{ { } }', 1))->toBe(8);
        });
    });

    describe('encodeUnquotedContent()', function () {
        it('writes plain text verbatim', function () {
            expect(StringEscapeDecoder::encodeUnquotedContent('q\\w'))->toBe('q\\w');
        });

        it('collapses newlines to spaces', function () {
            expect(StringEscapeDecoder::encodeUnquotedContent("a\nb\n"))->toBe('a b ');
        });

        it('keeps other control characters verbatim', function () {
            expect(StringEscapeDecoder::encodeUnquotedContent("a\tb\v c\rd\x7F"))
                ->toBe("a\tb\v c\rd\x7F");
        });

        it('escapes BMP private-use code points as hex', function () {
            expect(StringEscapeDecoder::encodeUnquotedContent("\u{E000}x"))->toBe('\\e000x');
        });

        it('separates hex escapes from a following hex digit', function () {
            expect(StringEscapeDecoder::encodeUnquotedContent("\u{E000}1"))->toBe('\\e000 1')
                ->and(StringEscapeDecoder::encodeUnquotedContent("\u{100000}1"))->toBe('\\100000 1');
        });

        it('omits the separator when the next character is not a hex digit', function () {
            expect(StringEscapeDecoder::encodeUnquotedContent("\u{E000}q"))->toBe('\\e000q')
                ->and(StringEscapeDecoder::encodeUnquotedContent("\u{F000}z"))->toBe('\\f000z');
        });

        it('escapes supplementary private-use code points', function () {
            expect(StringEscapeDecoder::encodeUnquotedContent("\u{100000}q"))->toBe('\\100000q');
        });

        it('leaves non-private astral code points unescaped', function () {
            expect(StringEscapeDecoder::encodeUnquotedContent("\u{1F600}"))->toBe("\u{1F600}");
        });

        it('keeps malformed UTF-8 sequences verbatim', function () {
            expect(StringEscapeDecoder::encodeUnquotedContent("\xF0"))->toBe("\xF0")
                ->and(StringEscapeDecoder::encodeUnquotedContent("\xE0"))->toBe("\xE0")
                ->and(StringEscapeDecoder::encodeUnquotedContent("\xC3"))->toBe("\xC3")
                ->and(StringEscapeDecoder::encodeUnquotedContent("\xF0\x9F"))->toBe("\xF0\x9F");
        });

        it('passes the protected hash through verbatim', function () {
            expect(StringEscapeDecoder::encodeUnquotedContent(StringEscapeDecoder::PROTECTED_HASH))
                ->toBe(StringEscapeDecoder::PROTECTED_HASH);
        });
    });

    describe('hexToUtf8()', function () {
        it('encodes ASCII range', function () {
            expect(StringEscapeDecoder::hexToUtf8('41'))->toBe('A');
        });

        it('encodes BMP and astral ranges', function () {
            expect(StringEscapeDecoder::hexToUtf8('F112'))->toBe("\u{F112}")
                ->and(StringEscapeDecoder::hexToUtf8('10FFFF'))->toBe("\u{10FFFF}");
        });

        it('falls back to replacement character for invalid code points', function () {
            expect(StringEscapeDecoder::hexToUtf8('0'))->toBe("\u{FFFD}")
                ->and(StringEscapeDecoder::hexToUtf8('D800'))->toBe("\u{FFFD}")
                ->and(StringEscapeDecoder::hexToUtf8('DFFF'))->toBe("\u{FFFD}")
                ->and(StringEscapeDecoder::hexToUtf8('110000'))->toBe("\u{FFFD}");
        });
    });
});
