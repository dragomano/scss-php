<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Tests\RuntimeFactory;

it('handles @at-root blocks', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $node    = new AtRootNode([
        new RuleNode('.box', [new DeclarationNode('color', new StringNode('red'))]),
    ]);

    expect($runtime->atRule()->handleAtRoot($node, $ctx))->toContain('.box');
});

it('handles block directives', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $node    = new DirectiveNode('media', 'screen', [
        new RuleNode('.box', [new DeclarationNode('color', new StringNode('red'))]),
    ], true);

    $expected = /** @lang text */ <<<'CSS'
    @media screen {
      .box {
        color: red;
      }
    }
    CSS;

    expect($runtime->atRule()->handleDirective($node, $ctx))->toEqualCss($expected);
});
