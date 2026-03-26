<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class UnknownColorChannelException extends SassArgumentException
{
    public function __construct(string $space, string $channel)
    {
        parent::__construct("Unknown $space channel '$channel'.");
    }
}
