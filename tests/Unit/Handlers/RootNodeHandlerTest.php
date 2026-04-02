<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Style;
use Tests\ReflectionAccessor;
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

it('restores source-map position when a following root child compiles to an empty string', function () {
    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(style: Style::COMPRESSED, sourceMapFile: 'output.css.map')
    );
    $ctx  = RuntimeFactory::context();
    $node = new RootNode([
        new CommentNode('first', true, 2, 3),
        new CommentNode('second', false, 4, 5),
    ]);

    $runtimeContext = new ReflectionAccessor($runtime);
    $ctxObject      = $runtimeContext->getProperty('ctx');
    $ctxObject->sourceMapState->collectMappings = true;

    $result = $runtime->root()->handle($node, $ctx);

    expect($result)->toBe('/*! first */')
        ->and($ctxObject->sourceMapState->generatedLine)->toBe(1)
        ->and($ctxObject->sourceMapState->generatedColumn)->toBe(12)
        ->and($ctxObject->sourceMapState->mappings)->toHaveCount(1);
});
