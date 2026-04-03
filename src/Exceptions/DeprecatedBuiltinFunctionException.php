<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class DeprecatedBuiltinFunctionException extends SassException
{
    public function __construct(
        public readonly string $reference,
        public readonly string $suggestions,
        public readonly bool $multipleSuggestions = false,
    ) {
        parent::__construct($this->buildMessage());
    }

    private function buildMessage(): string
    {
        $keyword = $this->multipleSuggestions ? 'Suggestions' : 'Suggestion';

        return $this->reference . ' is deprecated. ' . $keyword . ': ' . $this->suggestions;
    }
}
