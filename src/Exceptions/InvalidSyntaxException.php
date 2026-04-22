<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class InvalidSyntaxException extends SassArgumentException
{
    public static function cannotDetectFromPath(string $path): self
    {
        return new self("Cannot detect syntax from path: $path");
    }

    public static function unexpectedClosingParenthesis(int $line): self
    {
        return new self("Unexpected ')' at line $line.");
    }

    public static function expectedClosingParenthesis(int $line): self
    {
        return new self("Expected closing ')' for '(' opened at line $line.");
    }

    public static function unterminatedString(int $line): self
    {
        return new self("Unterminated string starting at line $line.");
    }

    public static function unterminatedComment(int $line): self
    {
        return new self("Unterminated comment starting at line $line.");
    }

    public static function separatedDirectiveHeaderContinuation(int $line, string $directive): self
    {
        return new self("Directive header continuation for '$directive' cannot be separated by an empty line after line $line.");
    }

    public static function incompleteDirectiveHeader(int $line, string $directive): self
    {
        return new self("Incomplete directive header for '$directive' at line $line.");
    }
}
