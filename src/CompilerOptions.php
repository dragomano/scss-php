<?php

declare(strict_types=1);

namespace Bugo\SCSS;

final readonly class CompilerOptions
{
    public function __construct(
        public Style $style = Style::EXPANDED,
        public string $sourceFile = 'input.scss',
        public string $outputFile = 'output.css',
        public ?string $sourceMapFile = null,
        public bool $includeSources = false,
        public bool $outputHexColors = false,
        public bool $splitRules = false,
        public bool $verboseLogging = false,
    ) {}
}
