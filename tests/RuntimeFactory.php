<?php

declare(strict_types=1);

namespace Tests;

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\CompilerRuntime;
use Bugo\SCSS\Loader;
use Bugo\SCSS\LoaderInterface;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Parser;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\TraversalContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RuntimeFactory
{
    public static function createRuntime(
        array $loadPaths = [],
        ?CompilerOptions $options = null,
        ?CompilerContext $context = null,
        ?LoaderInterface $loader = null,
        ?ParserInterface $parser = null,
        ?LoggerInterface $logger = null
    ): CompilerRuntime {
        return new CompilerRuntime(
            $context ?? new CompilerContext(),
            $options ?? new CompilerOptions(),
            $loader ?? new Loader($loadPaths),
            $parser ?? new Parser(),
            $logger ?? new NullLogger()
        );
    }

    public static function parse(string $scss): RootNode
    {
        return (new Parser())->parse($scss);
    }

    public static function context(?Environment $env = null, int $indent = 0): TraversalContext
    {
        return new TraversalContext($env ?? new Environment(), $indent);
    }
}
