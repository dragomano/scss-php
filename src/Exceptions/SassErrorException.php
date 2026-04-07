<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class SassErrorException extends SassException
{
    public function __construct(
        string $message,
        public readonly ?string $sourceFile = null,
        public readonly ?int $sourceLine = null,
        public readonly ?int $sourceColumn = null,
    ) {
        parent::__construct('@error: ' . $message);
    }
}
