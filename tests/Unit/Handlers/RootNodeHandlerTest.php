<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Tests\RuntimeFactory;

it('joins compiled root children with line breaks', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $node    = new RootNode([
        new CommentNode('first', true),
        new CommentNode('second', true),
    ]);

    expect($runtime->root()->handle($node, $ctx))->toBe("/*! first */\n/*! second */");
});

it('restores the saved mapping position when a following root child compiles to an empty string', function () {
    $compilerContext = new CompilerContext();

    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(sourceMapFile: 'output.css.map'),
        context: $compilerContext,
    );

    $ctx  = RuntimeFactory::context();
    $node = new RootNode([
        new CommentNode('first', true),
        new RuleNode('.empty', []),
    ]);

    $compilerContext->sourceMapState->collectMappings = true;
    $compilerContext->sourceMapState->generatedLine = 1;
    $compilerContext->sourceMapState->generatedColumn = 0;

    expect($runtime->root()->handle($node, $ctx))->toBe('/*! first */')
        ->and($compilerContext->sourceMapState->generatedLine)->toBe(1)
        ->and($compilerContext->sourceMapState->generatedColumn)->toBe(12);
});
