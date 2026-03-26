<?php

declare(strict_types=1);

namespace Bugo\SCSS;

interface CompilerInterface
{
    public function compileString(string $source, ?Syntax $syntax = null, string $sourceFile = ''): string;

    public function compileFile(string $path): string;
}
