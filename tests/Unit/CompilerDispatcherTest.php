<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerDispatcher;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Runtime\Environment;

describe('CompilerDispatcher', function () {
    it('throws LogicException when visitor is not set', function () {
        $dispatcher = new CompilerDispatcher();
        $env = new Environment();
        $node = new RootNode([]);

        expect(fn() => $dispatcher->compile($node, $env))
            ->toThrow(LogicException::class);
    });

    it('setVisitor() allows compile() to succeed', function () {
        // Build a real runtime so we get a proper visitor wired up
        $runtime = Tests\RuntimeFactory::createRuntime();

        $root = new RootNode([]);
        $env  = new Environment();

        // CompilerRuntime wires the visitor on construction
        $result = $runtime->dispatcher()->compile($root, $env);

        expect($result)->toBeString();
    });

    it('compile() returns empty string for empty root node', function () {
        $runtime = Tests\RuntimeFactory::createRuntime();

        $root = new RootNode([]);
        $env = new Environment();

        $result = $runtime->dispatcher()->compile($root, $env);

        expect($result)->toBe('');
    });

    it('compileWithContext() accepts TraversalContext', function () {
        $runtime = Tests\RuntimeFactory::createRuntime();

        $root = new RootNode([]);
        $ctx = Tests\RuntimeFactory::context();

        $result = $runtime->dispatcher()->compileWithContext($root, $ctx);

        expect($result)->toBe('');
    });
});
