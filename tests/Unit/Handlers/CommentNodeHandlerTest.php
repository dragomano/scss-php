<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Style;
use Tests\RuntimeFactory;

it('renders preserved and interpolated comments', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx = RuntimeFactory::context(indent: 1);
    $ctx->env->getCurrentScope()->setVariable('name', new StringNode('box'));

    $comment = new CommentNode('hello #{$name}', true);

    expect($runtime->comment()->handle($comment, $ctx))->toBe('  /*! hello box */');
});

it('drops non preserved comments in compressed mode', function () {
    $runtime = RuntimeFactory::createRuntime(options: new CompilerOptions(style: Style::COMPRESSED));
    $ctx = RuntimeFactory::context();

    expect($runtime->comment()->handle(new CommentNode('x'), $ctx))->toBe('');
});
