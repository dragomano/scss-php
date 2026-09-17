<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class InvalidColorChannelsException extends SassArgumentException
{
    public static function slashElementCount(string $argument, int $count): self
    {
        $verb = $count === 1 ? 'was' : 'were';

        return new self("\$$argument: Only 2 slash-separated elements allowed, but $count $verb passed.");
    }

    public static function emptyList(string $argument): self
    {
        return new self("\$$argument: Color component list may not be empty.");
    }

    public static function bracketed(string $argument, string $rendered): self
    {
        return new self("\$$argument: Expected an unbracketed list, was $rendered");
    }

    public static function wrongSeparator(string $argument, string $rendered): self
    {
        return new self("\$$argument: Expected a space- or slash-separated list, was $rendered");
    }

    public static function channelType(string $argument, string $channel, string $rendered): self
    {
        return new self("\$$argument: Expected $channel channel to be a number, was $rendered.");
    }

    public static function alphaType(string $argument, string $rendered): self
    {
        return new self("\$$argument: Expected alpha to be a number, was $rendered.");
    }

    public static function channelCount(string $space, string $argument, string $rendered, int $count): self
    {
        return new self("\$$argument: The $space color space has 3 channels but $rendered has $count.");
    }
}
