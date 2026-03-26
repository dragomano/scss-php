<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class SassErrorException extends SassException
{
    public function __construct(
        string $message,
        private readonly ?string $sourceFile = null,
        private readonly ?int $sourceLine = null,
        private readonly ?int $sourceColumn = null
    ) {
        parent::__construct('@error: ' . $message);
    }

    public function getSourceFilePath(): ?string
    {
        return $this->sourceFile;
    }

    public function getSourceLineNumber(): ?int
    {
        return $this->sourceLine;
    }

    public function getSourceColumnNumber(): ?int
    {
        return $this->sourceColumn;
    }
}
