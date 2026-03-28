<?php

declare(strict_types=1);

namespace Bugo\SCSS\Lexer;

use function chr;
use function ctype_alnum;
use function ctype_alpha;
use function ctype_digit;
use function ctype_space;
use function ctype_xdigit;
use function dechex;
use function hexdec;
use function min;
use function ord;
use function str_replace;
use function strcspn;
use function strlen;
use function strpos;
use function strrpos;
use function strspn;
use function strtolower;
use function substr;
use function substr_count;

final class Tokenizer
{
    protected string $source = '';

    protected int $length = 0;

    protected int $position = 0;

    protected int $line = 1;

    protected int $column = 1;

    protected bool $trackPositions = true;

    public function setTrackPositions(bool $trackPositions): void
    {
        $this->trackPositions = $trackPositions;
    }

    /**
     * @return array<int, Token>
     */
    public function tokenize(string $source): array
    {
        $this->source   = str_replace(["\r\n", "\r"], "\n", $source);
        $this->length   = strlen($this->source);
        $this->position = 0;
        $this->line     = 1;
        $this->column   = 1;

        $tokens    = [];
        $lastToken = null;

        while ($this->position < $this->length) {
            $token = $this->nextToken($lastToken);

            if ($token !== null) {
                $tokens[]  = $token;
                $lastToken = $token;
            }
        }

        $tokens[] = new Token(TokenType::EOF, '', $this->line, $this->column);

        $this->source = '';
        $this->length = 0;

        return $tokens;
    }

    private function nextToken(?Token $lastToken = null): ?Token
    {
        // Direct array access instead of peek() — avoids function call overhead
        $char = $this->source[$this->position];

        // Inline whitespace check (faster than ctype_space for common ASCII chars)
        if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\f" || $char === "\v") {
            return $this->tokenizeWhitespace();
        }

        if ($char === '/') {
            $next = $this->peekChar();

            if ($next === '/' && $this->isSingleLineCommentStart()) {
                return $this->tokenizeSingleLineComment();
            }

            if ($next === '*') {
                return $this->tokenizeMultiLineComment();
            }

            return $this->makeToken(TokenType::SLASH, '/', 1);
        }

        if ($char === ':') {
            return $this->tokenizeOptionalDoubleCharOperator(':', TokenType::DOUBLE_COLON, TokenType::COLON);
        }

        $singleCharToken = $this->singleCharToken($char);

        if ($singleCharToken !== null) {
            return $singleCharToken;
        }

        if ($char === '#') {
            return $this->tokenizeHashOrColor();
        }

        if ($char === 'U' || $char === 'u') {
            if (
                $this->peekChar() === '+'
                && $this->isUnicodeRangePartChar($this->peekChar(2))
            ) {
                return $this->tokenizeUnicodeRange();
            }
        }

        if ($char === '.') {
            return $this->tokenizeNumberOrSingleChar(TokenType::DOT);
        }

        if ($char === '=') {
            return $this->tokenizeOptionalDoubleCharOperator('=', TokenType::EQUALS, TokenType::ASSIGN);
        }

        if ($char === '!') {
            return $this->tokenizeOptionalDoubleCharOperator('=', TokenType::NOT_EQUALS, TokenType::EXCLAMATION);
        }

        if ($char === '<') {
            return $this->tokenizeOptionalDoubleCharOperator('=', TokenType::LESS_THAN_EQUALS, TokenType::LESS_THAN);
        }

        if ($char === '>') {
            return $this->tokenizeOptionalDoubleCharOperator(
                '=',
                TokenType::GREATER_THAN_EQUALS,
                TokenType::GREATER_THAN
            );
        }

        if ($char === '+') {
            return $this->tokenizeNumberOrSingleChar(TokenType::PLUS);
        }

        if ($char === '-') {
            $next = $this->peekChar();

            if ($next === '-') {
                return $this->tokenizeCssVariable();
            }

            $shouldTokenizeAsNumber = ($next !== '' && (ctype_digit($next) || $next === '.'))
                && (
                    $lastToken === null
                    || $lastToken->type === TokenType::WHITESPACE
                    || $lastToken->type === TokenType::LPAREN
                    || $lastToken->type === TokenType::COMMA
                );

            if ($shouldTokenizeAsNumber) {
                return $this->tokenizeNumber();
            }

            if ($next !== '' && (ctype_alpha($next) || $next === '_' || $next === '\\')) {
                return $this->tokenizeIdentifier();
            }

            return $this->makeToken(TokenType::MINUS, '-', 1);
        }

        if ($char === '"' || $char === "'") {
            return $this->tokenizeString();
        }

        if (ctype_digit($char)) {
            return $this->tokenizeNumber();
        }

        if (ctype_alpha($char) || $char === '_') {
            return $this->tokenizeIdentifier();
        }

        if ($char === '\\') {
            return $this->tokenizeIdentifier();
        }

        $this->advance();

        return null;
    }

    private function tokenizeWhitespace(): Token
    {
        $line   = $this->line;
        $column = $this->column;

        // strspn finds the byte length of the whitespace span in one C-level call
        $len = strspn($this->source, " \t\n\r\f\v", $this->position);
        $this->advance($len);

        return new Token(TokenType::WHITESPACE, ' ', $line, $column);
    }

    private function tokenizeSingleLineComment(): Token
    {
        $line   = $this->line;
        $column = $this->column;

        $this->advance(2); // skip //

        $end = strpos($this->source, "\n", $this->position);
        if ($end === false) {
            $end = $this->length;
        }

        $len   = $end - $this->position;
        $value = substr($this->source, $this->position, $len);

        // Single-line comment content never contains newlines
        $this->column  += $len;
        $this->position = $end;

        return new Token(TokenType::COMMENT_SILENT, $value, $line, $column);
    }

    private function tokenizeMultiLineComment(): Token
    {
        $line   = $this->line;
        $column = $this->column;

        $this->advance(2); // skip /*

        $isPreserved = false;

        if ($this->position < $this->length && $this->source[$this->position] === '!') {
            $isPreserved = true;
            $this->advance();
        }

        $end = strpos($this->source, '*/', $this->position);
        if ($end === false) {
            $value = substr($this->source, $this->position);
            $this->advance($this->length - $this->position);
        } else {
            $value = substr($this->source, $this->position, $end - $this->position);
            $this->advance($end - $this->position + 2);
        }

        $type = $isPreserved ? TokenType::COMMENT_PRESERVED : TokenType::COMMENT_LOUD;

        return new Token($type, $value, $line, $column);
    }

    private function tokenizeHashOrColor(): Token
    {
        $line   = $this->line;
        $column = $this->column;

        $this->advance(); // skip #

        $start = $this->position;

        // Try hex chars first
        while ($this->position < $this->length && ctype_xdigit($this->source[$this->position])) {
            $this->position++;
        }

        if ($this->position > $start) {
            $count = $this->position - $start;
            $this->column += $count;

            return new Token(TokenType::HASH, substr($this->source, $start, $count), $line, $column);
        }

        // Fall back to alnum/underscore/hyphen (e.g. #foo, #my-id)
        while ($this->position < $this->length) {
            $c = $this->source[$this->position];

            if (! ctype_alnum($c) && $c !== '_' && $c !== '-') {
                break;
            }

            $this->position++;
        }

        $count = $this->position - $start;
        $this->column += $count;

        return new Token(TokenType::HASH, substr($this->source, $start, $count), $line, $column);
    }

    private function tokenizeUnicodeRange(): Token
    {
        $line   = $this->line;
        $column = $this->column;
        $value  = $this->source[$this->position];

        $this->advance();

        $value .= '+';

        $this->advance();

        while ($this->position < $this->length && $this->isUnicodeRangePartChar($this->source[$this->position])) {
            $value .= $this->source[$this->position];
            $this->advance();
        }

        return new Token(TokenType::IDENTIFIER, $value, $line, $column);
    }

    private function tokenizeString(): Token
    {
        $line   = $this->line;
        $column = $this->column;
        $quote  = $this->source[$this->position];
        $mask   = '\\' . $quote; // chars that end a plain chunk: backslash or closing quote

        $this->advance(); // skip opening quote

        $value = '';

        while ($this->position < $this->length) {
            // Find length of plain (non-special) chunk in one C-level call
            $safe = strcspn($this->source, $mask, $this->position);

            if ($safe > 0) {
                $value .= substr($this->source, $this->position, $safe);

                $this->advance($safe);
            }

            $char = $this->source[$this->position];

            if ($char === '\\') {
                $escapeResult = $this->parseEscapeSequence();

                if ($escapeResult === '\\') {
                    $value .= '\\';

                    continue;
                }

                if (ctype_xdigit($escapeResult[0] ?? '')) {
                    $decoded = $this->decodeAstralUnicodeEscape($escapeResult);
                    if ($decoded !== null) {
                        $value .= $decoded;
                    } else {
                        $value .= '\\' . $escapeResult;
                    }

                    continue;
                }

                $value .= $escapeResult;

                continue;
            }

            if ($char === $quote) {
                $this->advance();

                break;
            }
        }

        return new Token(TokenType::STRING, $value, $line, $column);
    }

    private function decodeAstralUnicodeEscape(string $hex): ?string
    {
        $codePoint = (int) hexdec($hex);

        if ($codePoint < 0x10000 || $codePoint > 0x10FFFF) {
            return null;
        }

        return $this->byte(0xF0 | ($codePoint >> 18))
            . $this->byte(0x80 | (($codePoint >> 12) & 0x3F))
            . $this->byte(0x80 | (($codePoint >> 6) & 0x3F))
            . $this->byte(0x80 | ($codePoint & 0x3F));
    }

    private function tokenizeNumber(): Token
    {
        $line   = $this->line;
        $column = $this->column;
        $start  = $this->position;

        // Optional sign
        if ($this->position < $this->length) {
            $c = $this->source[$this->position];

            if ($c === '-' || $c === '+') {
                $this->position++;
            }
        }

        // Integer part
        while ($this->position < $this->length && ctype_digit($this->source[$this->position])) {
            $this->position++;
        }

        // Decimal part
        if ($this->position < $this->length && $this->source[$this->position] === '.') {
            $this->position++;

            while ($this->position < $this->length && ctype_digit($this->source[$this->position])) {
                $this->position++;
            }
        }

        // Exponent
        if ($this->isExponentStart()) {
            $this->position++; // e/E

            if ($this->position < $this->length) {
                $c = $this->source[$this->position];

                if ($c === '+' || $c === '-') {
                    $this->position++;
                }
            }

            while ($this->position < $this->length && ctype_digit($this->source[$this->position])) {
                $this->position++;
            }
        }

        // Unit: % or alpha chars (px, em, rem, …)
        if ($this->position < $this->length && $this->source[$this->position] === '%') {
            $this->position++;
        } else {
            while ($this->position < $this->length && ctype_alpha($this->source[$this->position])) {
                $this->position++;
            }
        }

        $count = $this->position - $start;
        // Numbers never contain newlines — safe to increment column directly
        $this->column += $count;

        return new Token(TokenType::NUMBER, substr($this->source, $start, $count), $line, $column);
    }

    private function isExponentStart(): bool
    {
        if ($this->position >= $this->length) {
            return false;
        }

        $char = $this->source[$this->position]; // direct access instead of peek()

        if ($char !== 'e' && $char !== 'E') {
            return false;
        }

        if ($this->position + 1 >= $this->length) {
            return false;
        }

        $next = $this->source[$this->position + 1]; // direct access instead of peek(1)

        if (ctype_digit($next)) {
            return true;
        }

        if (
            ($next === '+' || $next === '-')
            && $this->position + 2 < $this->length
            && ctype_digit($this->source[$this->position + 2])
        ) {
            return true;
        }

        return false;
    }

    private function tokenizeIdentifier(): Token
    {
        $line   = $this->line;
        $column = $this->column;
        $value  = '';

        while ($this->position < $this->length) {
            // Scan the plain ASCII identifier chars in a tight inner loop
            $scanStart = $this->position;

            while ($this->position < $this->length) {
                $char = $this->source[$this->position];

                if (! ctype_alnum($char) && $char !== '_' && $char !== '-') {
                    break;
                }

                $this->position++;
            }

            if ($this->position > $scanStart) {
                $count = $this->position - $scanStart;

                // Identifier chars never contain newlines — direct column update
                $this->column += $count;

                $value .= substr($this->source, $scanStart, $count);
            }

            // Stop if no backslash escape follows
            if ($this->position >= $this->length || $this->source[$this->position] !== '\\') {
                break;
            }

            $normalizedEscape = $this->tokenizeIdentifierEscape();

            $value .= $normalizedEscape;
        }

        return new Token(TokenType::IDENTIFIER, $value, $line, $column);
    }

    private function tokenizeIdentifierEscape(): string
    {
        $escapeResult = $this->parseEscapeSequence();

        if ($escapeResult === '\\') {
            return '\\';
        }

        if (ctype_xdigit($escapeResult[0] ?? '')) {
            return $this->normalizeIdentifierEscapedCodePoint((int) hexdec($escapeResult));
        }

        return $this->normalizeIdentifierEscapedCodePoint(ord(substr($escapeResult, 1)));
    }

    private function parseEscapeSequence(): string
    {
        $this->advance();

        if ($this->position >= $this->length) {
            return '\\';
        }

        if (ctype_xdigit($this->source[$this->position])) {
            $hex = '';

            while ($this->position < $this->length && strlen($hex) < 6 && ctype_xdigit($this->source[$this->position])) {
                $hex .= $this->source[$this->position];

                $this->advance();
            }

            if ($this->position < $this->length && ctype_space($this->source[$this->position])) {
                $this->advance();
            }

            return $hex;
        }

        $escapedChar = $this->source[$this->position];

        $this->advance();

        return '\\' . $escapedChar;
    }

    private function normalizeIdentifierEscapedCodePoint(int $codePoint): string
    {
        if ($this->isIdentifierCodePoint($codePoint)) {
            return $this->encodeCodePoint($codePoint);
        }

        if ($this->isPrintableCodePoint($codePoint)) {
            return '\\' . $this->encodeCodePoint($codePoint);
        }

        return '\\' . strtolower(dechex($codePoint)) . ' ';
    }

    private function isIdentifierCodePoint(int $codePoint): bool
    {
        if ($codePoint >= 0 && $codePoint <= 0x7F) {
            $char = chr($codePoint);

            return ctype_alnum($char) || $char === '-' || $char === '_';
        }

        return $codePoint <= 0x10FFFF;
    }

    private function isPrintableCodePoint(int $codePoint): bool
    {
        if ($codePoint === 0x09 || $codePoint === 0x0A) {
            return false;
        }

        return $codePoint >= 0x20 && $codePoint <= 0x7E;
    }

    private function encodeCodePoint(int $codePoint): string
    {
        if ($codePoint >= 0 && $codePoint <= 0x7F) {
            return $this->byte($codePoint);
        }

        if ($codePoint <= 0x7FF) {
            return $this->byte(0xC0 | ($codePoint >> 6))
                . $this->byte(0x80 | ($codePoint & 0x3F));
        }

        if ($codePoint <= 0xFFFF) {
            return $this->byte(0xE0 | ($codePoint >> 12))
                . $this->byte(0x80 | (($codePoint >> 6) & 0x3F))
                . $this->byte(0x80 | ($codePoint & 0x3F));
        }

        return $this->byte(0xF0 | ($codePoint >> 18))
            . $this->byte(0x80 | (($codePoint >> 12) & 0x3F))
            . $this->byte(0x80 | (($codePoint >> 6) & 0x3F))
            . $this->byte(0x80 | ($codePoint & 0x3F));
    }

    private function byte(int $value): string
    {
        return chr($value & 0xFF);
    }

    private function tokenizeCssVariable(): Token
    {
        $line   = $this->line;
        $column = $this->column;
        $start  = $this->position;

        while ($this->position < $this->length) {
            $char = $this->source[$this->position];

            if ($char === ')' || $char === ',' || $char === ';' || $char === '}' || ctype_space($char)) {
                break;
            }

            $this->position++;
        }

        $count = $this->position - $start;

        // CSS variable names/values never contain newlines in this context
        $this->column += $count;

        return new Token(TokenType::CSS_VARIABLE, substr($this->source, $start, $count), $line, $column);
    }

    private function makeToken(TokenType $type, string $value, int $length): Token
    {
        $token = new Token($type, $value, $this->line, $this->column);

        $this->advance($length);

        return $token;
    }

    private function tokenizeOptionalDoubleCharOperator(
        string $nextChar,
        TokenType $doubleType,
        TokenType $singleType
    ): Token {
        $char = $this->source[$this->position];

        if ($this->peekChar() === $nextChar) {
            return $this->makeToken($doubleType, $char . $nextChar, 2);
        }

        return $this->makeToken($singleType, $char, 1);
    }

    private function tokenizeNumberOrSingleChar(TokenType $singleType): Token
    {
        if (ctype_digit($this->peekChar())) {
            return $this->tokenizeNumber();
        }

        $char = $this->source[$this->position];

        return $this->makeToken($singleType, $char, 1);
    }

    private function singleCharToken(string $char): ?Token
    {
        return match ($char) {
            '@'     => $this->makeToken(TokenType::AT, '@', 1),
            '$'     => $this->makeToken(TokenType::DOLLAR, '$', 1),
            ';'     => $this->makeToken(TokenType::SEMICOLON, ';', 1),
            ','     => $this->makeToken(TokenType::COMMA, ',', 1),
            '{'     => $this->makeToken(TokenType::LBRACE, '{', 1),
            '}'     => $this->makeToken(TokenType::RBRACE, '}', 1),
            '('     => $this->makeToken(TokenType::LPAREN, '(', 1),
            ')'     => $this->makeToken(TokenType::RPAREN, ')', 1),
            '['     => $this->makeToken(TokenType::LBRACKET, '[', 1),
            ']'     => $this->makeToken(TokenType::RBRACKET, ']', 1),
            '&'     => $this->makeToken(TokenType::AMPERSAND, '&', 1),
            '*'     => $this->makeToken(TokenType::STAR, '*', 1),
            '%'     => $this->makeToken(TokenType::PERCENT, '%', 1),
            '~'     => $this->makeToken(TokenType::TILDE, '~', 1),
            default => null,
        };
    }

    private function peekChar(int $offset = 1): string
    {
        $position = $this->position + $offset;

        if ($position >= $this->length) {
            return '';
        }

        return $this->source[$position];
    }

    private function advance(int $count = 1): void
    {
        if (! $this->trackPositions) {
            $this->position += $count;

            return;
        }

        if ($count === 1) {
            // Fast path for single-char advance (most common case via makeToken)
            if ($this->position < $this->length) {
                if ($this->source[$this->position] === "\n") {
                    $this->line++;
                    $this->column = 1;
                } else {
                    $this->column++;
                }

                $this->position++;
            }

            return;
        }

        // Bulk path: count newlines in the span with a single C-level call
        $end = min($this->position + $count, $this->length);
        $len = $end - $this->position;

        $chunk    = substr($this->source, $this->position, $len);
        $newlines = substr_count($chunk, "\n");

        if ($newlines > 0) {
            $this->line += $newlines;

            $lastNl = strrpos($chunk, "\n");

            $this->column = $len - (int) $lastNl;
        } else {
            $this->column += $len;
        }

        $this->position = $end;
    }

    private function isSingleLineCommentStart(): bool
    {
        if ($this->position === 0) {
            return true;
        }

        if ($this->source[$this->position - 1] !== ':') {
            return true;
        }

        return ! ctype_alnum($this->source[$this->position - 2]);
    }

    private function isUnicodeRangePartChar(string $char): bool
    {
        return $char === '?' || $char === '-' || ctype_xdigit($char);
    }
}
