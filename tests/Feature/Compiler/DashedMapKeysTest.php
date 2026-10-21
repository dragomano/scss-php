<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Syntax;

it('evaluates unquoted dashed map keys and nested values', function () {
    $source = <<<'SCSS'
    @use 'sass:map';
    @use 'sass:meta';
    $gap: 2px;
    $tokens: (--x: $gap * 2, --nested: (--y: 3px), ordinary: true);
    .result {
      type: meta.type-of($tokens);
      x: map.get($tokens, --x);
      y: map.get($tokens, --nested, --y);
      has-key: map.has-key($tokens, --x);
      ordinary: map.get($tokens, ordinary);
    }
    SCSS;

    expect((new Compiler())->compileString($source))->toEqualCss(/** @lang text */ <<<'CSS'
    .result {
      type: map;
      x: 4px;
      y: 3px;
      has-key: true;
      ordinary: true;
    }
    CSS);
});

it('emits token maps with dashed keys after merging defaults and overrides', function () {
    $source = <<<'SCSS'
    @use 'sass:map';
    @function defaults($base, $overrides) { @return map.merge($base, $overrides); }
    @mixin tokens($map) {
      @each $property, $value in $map { #{$property}: #{$value}; }
    }
    $card-tokens: defaults((
      --card-spacer-x: var(--spacer-6),
      --card-border-width: 1px,
      --card-bg: inherit,
    ), (--card-bg: var(--bg-body)));
    .card { @include tokens($card-tokens); }
    SCSS;

    expect((new Compiler())->compileString($source))->toEqualCss(/** @lang text */ <<<'CSS'
    .card {
      --card-spacer-x: var(--spacer-6);
      --card-border-width: 1px;
      --card-bg: var(--bg-body);
    }
    CSS);
});

it('supports interpolated dashed keys alongside quoted keys', function () {
    $source = <<<'SCSS'
    @use 'sass:map';
    $name: gap;
    $tokens: (--#{$name}: 1px, "--quoted": 2px);
    .result {
      gap: map.get($tokens, --gap);
      quoted: map.get($tokens, --quoted);
    }
    SCSS;

    expect((new Compiler())->compileString($source))->toEqualCss(/** @lang text */ <<<'CSS'
    .result {
      gap: 1px;
      quoted: 2px;
    }
    CSS);
});

it('preserves raw CSS custom property values containing colons', function (Syntax $syntax) {
    $source = <<<'SCSS'
    .raw {
      --literal:--x:1px;
      --fallback:var(--x,--y);
      --quoted:"--x:1px";
      --pseudo::test;
      --spaced : :value;
    }
    SCSS;

    expect((new Compiler())->compileString($source, $syntax))->toEqualCss(/** @lang text */ <<<'CSS'
    .raw {
      --literal:--x:1px;
      --fallback:var(--x,--y);
      --quoted:"--x:1px";
      --pseudo::test;
      --spaced: :value;
    }
    CSS);
})->with([Syntax::SCSS, Syntax::CSS]);
