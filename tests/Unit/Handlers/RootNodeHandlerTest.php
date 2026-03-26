<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\RootNode;
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
