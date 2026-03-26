<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\StringNode;
use Tests\RuntimeFactory;

it('renders declarations with important flag', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context(indent: 1);
    $node    = new DeclarationNode('color', new StringNode('red'), important: true);

    expect($runtime->declaration()->handle($node, $ctx))
        ->toBe('  color: red !important;');
});

it('omits declarations whose value resolves to null', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    expect($runtime->declaration()->handle(new DeclarationNode('color', new NullNode()), $ctx))
        ->toBe('');
});
