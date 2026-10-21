<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Loader;
use Tests\Support\ArrayLogger;
use Tests\Support\RuntimeFactory;

beforeEach(function () {
    $this->moduleDir = sys_get_temp_dir() . '/scss-php-extend-dependencies-' . uniqid('', true);

    mkdir($this->moduleDir . '/layout', 0777, true);
});

it('collects configured variables functions and mixins through relative module forwards', function (string $namespace, string $prefix) {
    file_put_contents($this->moduleDir . '/_settings.scss', <<<'SCSS'
    $enabled: true !default;
    $sizes: (xs: 10rem) !default;
    SCSS);

    file_put_contents($this->moduleDir . '/_helpers.scss', <<<'SCSS'
    @use 'settings';
    @function name($key) { @return 'container-#{$key}'; }
    @mixin widths {
      @each $key, $width in settings.$sizes {
        @media (min-width: $width) {
          %width-#{$key} { max-width: $width; }
          .container, .container-#{$key} { @extend %width-#{$key}; }
        }
      }
    }
    SCSS);

    file_put_contents($this->moduleDir . '/_api.scss', <<<'SCSS'
    @forward 'settings';
    @forward 'helpers';
    SCSS);

    file_put_contents($this->moduleDir . '/layout/_containers.scss', str_replace(
        ['NAMESPACE', 'PREFIX'],
        [$namespace, $prefix],
        <<<'SCSS'
        @use '../api' as NAMESPACE;
        @if PREFIX$enabled {
          .container { width: 100%; }
          @each $key, $width in PREFIX$sizes {
            .#{PREFIXname($key)} { @extend .container; }
          }
          @include PREFIXwidths;
        }
        SCSS,
    ));

    $compiler = new Compiler(loader: new Loader([$this->moduleDir]));

    $css = $compiler->compileString(<<<'SCSS'
    @use 'api' with ($sizes: (sm: 40rem, md: 60rem));
    @use 'layout/containers';
    SCSS);

    expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
    .container, .container-md, .container-sm {
      width: 100%;
    }

    @media (min-width: 40rem) {
      .container, .container-sm, .container-md {
        max-width: 40rem;
      }
    }
    @media (min-width: 60rem) {
      .container, .container-sm, .container-md {
        max-width: 60rem;
      }
    }
    CSS);
})->with([
    'wildcard' => ['*', ''],
    'namespace' => ['api', 'api.'],
]);

it('collects content arguments and extends a global placeholder from a layer', function () {
    file_put_contents($this->moduleDir . '/_settings.scss', '$prefixes: ("", "sm-", "md-");');

    file_put_contents($this->moduleDir . '/_loops.scss', <<<'SCSS'
    @use 'settings' as *;
    @mixin variants {
      @each $prefix in $prefixes {
        @content($prefix);
      }
    }
    SCSS);

    file_put_contents($this->moduleDir . '/_drawer.scss', <<<'SCSS'
    @use 'loops';
    %drawer-tokens { --drawer-width: 20rem; }
    @layer components {
      @include loops.variants using ($prefix, $suffix: drawer) {
        .#{$prefix}#{$suffix} { @extend %drawer-tokens; }
      }
      .drawer { display: block; }
    }
    SCSS);

    $compiler = new Compiler(loader: new Loader([$this->moduleDir]));

    expect($compiler->compileString('@use "drawer";'))->toEqualCss(/** @lang text */ <<<'CSS'
    .md-drawer, .sm-drawer, .drawer {
      --drawer-width: 20rem;
    }

    @layer components {
      .drawer {
        display: block;
      }
    }
    CSS);
});

it('keeps module mutations and diagnostics isolated during collection', function () {
    file_put_contents($this->moduleDir . '/_settings.scss', '$names: (small, large); $count: 0;');

    file_put_contents($this->moduleDir . '/_variants.scss', <<<'SCSS'
    @use 'settings' as *;
    @mixin variants {
      $count: $count + 1 !global;
      @each $name in $names {
        .#{$name} { @extend .base; }
      }
    }
    .base { color: red; }
    @include variants;
    .count { order: $count; }
    @warn 'module evaluated';
    SCSS);

    $logger   = new ArrayLogger();
    $compiler = new Compiler(loader: new Loader([$this->moduleDir]), logger: $logger);

    expect($compiler->compileString('@use "variants"; @use "variants" as second;'))->toEqualCss(/** @lang text */ <<<'CSS'
    .base, .large, .small {
      color: red;
    }

    .count {
      order: 1;
    }
    CSS);

    expect(array_filter($logger->records, static fn(array $record): bool => $record['level'] === 'warning'))
        ->toHaveCount(1);
});

it('collects root extensions after loading their wildcard dependencies', function () {
    file_put_contents($this->moduleDir . '/_settings.scss', '$names: (small, large); .base { color: red; }');

    $compiler = new Compiler(loader: new Loader([$this->moduleDir]));

    expect($compiler->compileString(<<<'SCSS'
    @use 'settings' as *;
    @each $name in $names { .#{$name} { @extend .base; } }
    SCSS))->toEqualCss(/** @lang text */ <<<'CSS'
    .base, .small, .large {
      color: red;
    }
    CSS);
});

it('keeps extensions when an unreachable load-css dependency cannot be collected', function () {
    file_put_contents($this->moduleDir . '/_bad.scss', '@for $i from oops through 1 { .bad { @extend .base; } }');

    $compiler = new Compiler(loader: new Loader([$this->moduleDir]));

    expect($compiler->compileString(<<<'SCSS'
    @use 'sass:meta';
    @if false { @include meta.load-css('bad'); }
    .base { color: red; }
    .active { @extend .base; }
    SCSS))->toEqualCss(/** @lang text */ <<<'CSS'
    .base, .active {
      color: red;
    }
    CSS);
});

it('bounds collection of circular forwards without losing root extensions', function () {
    file_put_contents($this->moduleDir . '/_a.scss', '@forward "b";');
    file_put_contents($this->moduleDir . '/_b.scss', '@forward "a";');

    $runtime = RuntimeFactory::createRuntime(loadPaths: [$this->moduleDir]);
    $ast     = RuntimeFactory::parse('@use "a"; .base { color: red; } .active { @extend .base; }');

    $runtime->extendsGraph()->finalize($ast, true);

    expect($runtime->extends()->applyExtendsToSelector('.base'))->toBe('.base, .active');
});

it('preserves the media context while collecting module content', function () {
    file_put_contents($this->moduleDir . '/_helpers.scss', '@mixin content { @content; }');

    file_put_contents($this->moduleDir . '/_cards.scss', <<<'SCSS'
    @use 'helpers';
    @media (min-width: 40rem) {
      %token { color: red; }
      @include helpers.content { .card { @extend %token; } }
    }
    SCSS);

    $compiler = new Compiler(loader: new Loader([$this->moduleDir]));

    expect($compiler->compileString('@use "cards";'))->toEqualCss(/** @lang text */ <<<'CSS'
    @media (min-width: 40rem) {
      .card {
        color: red;
      }
    }
    CSS);
});

it('reports errors from dependent mixins during normal compilation', function () {
    file_put_contents($this->moduleDir . '/_helpers.scss', <<<'SCSS'
    @mixin broken($value: $missing) { .item { @extend .base; } }
    SCSS);

    $compiler = new Compiler(loader: new Loader([$this->moduleDir]));

    expect(fn() => $compiler->compileString('@use "helpers"; .base { color: red; } @include helpers.broken;'))
        ->toThrow(UndefinedSymbolException::class, 'Undefined variable: $missing');
});
