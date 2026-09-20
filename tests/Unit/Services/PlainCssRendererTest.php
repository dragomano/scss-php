<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Runtime\Environment;
use Tests\Support\RuntimeFactory;

it('renders plain css rules separated by newlines', function () {
    $renderer = RuntimeFactory::createRuntime()->plainCssRenderer();

    $root = new RootNode([
        new ExtendNode('.skip-me'),
        new RuleNode('a', [new CommentNode('/* first */')]),
        new RuleNode('b', [new CommentNode('/* second */')]),
    ]);

    expect($renderer->render($root, new Environment()))->toBe(
        /** @lang text */
        <<<'CSS'
        a {
          /*/* first */*/
        }
        b {
          /*/* second */*/
        }
        CSS,
    );
});

it('supports css import directives and import nodes', function () {
    $renderer = RuntimeFactory::createRuntime()->plainCssRenderer();

    $root = new RootNode([
        new DirectiveNode('charset', '"utf-8"'),
        new ImportNode(['url(a.css)', 'url(b.css)']),
        new DirectiveNode('import', ''),
    ]);

    expect($renderer->render($root, new Environment()))->toBe(
        /** @lang text */
        <<<'CSS'
        @import url(a.css);
        @import url(b.css);
        @import;
        CSS,
    );
});

it('skips non visitable children inside rules', function () {
    $renderer = RuntimeFactory::createRuntime()->plainCssRenderer();

    $rule = new RuleNode('a', [
        new ExtendNode('.b'),
        new CommentNode('/* x */'),
    ]);

    expect($renderer->render(new RootNode([$rule]), new Environment()))->toBe(
        /** @lang text */
        <<<'CSS'
        a {
          /*/* x */*/
        }
        CSS,
    );
});

it('hoists bubbling at rules after rule content', function () {
    $renderer = RuntimeFactory::createRuntime()->plainCssRenderer();

    $rule = new RuleNode('a', [
        new CommentNode('/* x */'),
        new DirectiveNode('media', 'screen', [new CommentNode('/* y */')], true),
    ]);

    expect($renderer->render(new RootNode([$rule]), new Environment()))->toBe(
        /** @lang text */
        <<<'CSS'
        a {
          /*/* x */*/
        }
        @media screen {
          a {
            /*/* y */*/
          }
        }
        CSS,
    );
});

it('marks a rule as css function body when the selector is an @function declaration', function () {
    $renderer = RuntimeFactory::createRuntime()->plainCssRenderer();

    $rule = new RuleNode('@function --x ()', [
        new CommentNode('/* body */'),
    ]);

    expect($renderer->render(new RootNode([$rule]), new Environment()))->toBe(
        /** @lang text */
        <<<'CSS'
        @function --x () {
          /*/* body */*/
        }
        CSS,
    );
});

it('renders keyframes inside rules without bubbling', function () {
    $renderer = RuntimeFactory::createRuntime()->plainCssRenderer();

    $rule = new RuleNode('.a', [
        new DirectiveNode('keyframes', 'spin', [], true),
    ]);

    $out = $renderer->render(new RootNode([$rule]), new Environment());

    expect($out)->toContain('@keyframes');
});

it('renders empty at rule bodies', function () {
    $renderer = RuntimeFactory::createRuntime()->plainCssRenderer();

    $root = new RootNode([
        new DirectiveNode('media', 'screen', [], true),
        new SupportsNode('display: flex', []),
    ]);

    expect($renderer->render($root, new Environment()))->toBe(
        /** @lang text */
        <<<'CSS'
        @media screen {}
        @supports display: flex {}
        CSS,
    );
});
