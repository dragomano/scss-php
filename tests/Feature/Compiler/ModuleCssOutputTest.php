<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\Support\ArrayLogger;
use Tests\Support\MemoryLoader;

describe('Module CSS output', function () {
    it('reads wildcard module variables after another module updates their origin', function () {
        $loader = new MemoryLoader([
            '/_config.scss' => '$utilities: () !default;',
            '/_api.scss' => <<<'SCSS'
            @use "config" as *;
            @mixin generate {
              @each $name, $value in $utilities {
                .#{$name} { display: $value; }
              }
              @if $utilities != () {
                @property --so { syntax: "<number>"; inherits: false; initial-value: 1; }
              }
            }
            SCSS,
            '/_utilities.scss' => <<<'SCSS'
            @use "config";
            config.$utilities: ("sm\\:d-flex": flex, "\\32 xl\\:d-block": block);
            SCSS,
        ]);

        $compiler = new Compiler(loader: $loader);

        $source = <<<'SCSS'
        @use "config";
        @use "api";
        @use "utilities";
        @include api.generate;
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .sm\:d-flex {
          display: flex;
        }

        .\32 xl\:d-block {
          display: block;
        }

        @property --so {
          syntax: "<number>";
          inherits: false;
          initial-value: 1;
        }
        CSS;

        expect($compiler->compileString($source))->toEqualCss($expected);
    });

    it('allows local shadows of live wildcard module variables', function () {
        $loader = new MemoryLoader([
            '/_config.scss' => '$value: before;',
            '/_reader.scss' => <<<'SCSS'
            @use "config" as *;
            @mixin read { .reader { value: $value; } }
            SCSS,
        ]);

        $compiler = new Compiler(loader: $loader);

        $source = <<<'SCSS'
        @use "config";
        @use "reader";
        @use "config" as *;
        config.$value: after;
        .local { $value: local; value: $value; }
        @include reader.read;
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .local {
          value: local;
        }

        .reader {
          value: after;
        }
        CSS;

        expect($compiler->compileString($source))->toEqualCss($expected);
    });

    it('keeps parent selectors for content inside cascade layers', function () {
        $logger   = new ArrayLogger();
        $compiler = new Compiler(logger: $logger);

        $source = <<<'SCSS'
        @mixin focus { @content; }
        @layer forms {
          .control {
            @include focus {
              color: red;
              &:focus-visible { outline: 0; }
            }
          }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @layer forms {
          .control {
            color: red;
          }
          .control:focus-visible {
            outline: 0;
          }
        }
        CSS;

        expect($compiler->compileString($source))->toEqualCss($expected)
            ->and($logger->records)->toBeEmpty();
    });

    it('wraps content in the parent rule inside container queries', function () {
        $logger   = new ArrayLogger();
        $compiler = new Compiler(logger: $logger);

        $source = <<<'SCSS'
        @mixin up($width) { @container (width >= #{$width}) { @content; } }
        @layer components {
          @each $name, $width in (sm: 576px, md: 768px) {
            .#{$name}\:card-group {
              @include up($width) { display: flex; > .card { flex: 1; } }
            }
          }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @layer components {
          @container (width >= 576px) {
            .sm\:card-group {
              display: flex;
            }
            .sm\:card-group > .card {
              flex: 1;
            }
          }
          @container (width >= 768px) {
            .md\:card-group {
              display: flex;
            }
            .md\:card-group > .card {
              flex: 1;
            }
          }
        }
        CSS;

        expect($compiler->compileString($source))->toEqualCss($expected)
            ->and($logger->records)->toBeEmpty();
    });

    it('keeps validation companion selectors inside at-root content', function () {
        $logger   = new ArrayLogger();
        $compiler = new Compiler(logger: $logger);

        $source = <<<'SCSS'
        @mixin validation {
          @at-root { &[data-bs-validate]:valid, &:user-valid { @content; } }
        }
        @layer forms {
          .control { @include validation { color: green; &:focus { outline: 0; } } }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @layer forms {
          .control[data-bs-validate]:valid, .control:user-valid {
            color: green;
          }
          .control[data-bs-validate]:valid:focus, .control:user-valid:focus {
            outline: 0;
          }
        }
        CSS;

        expect($compiler->compileString($source))->toEqualCss($expected)
            ->and($logger->records)->toBeEmpty();
    });

    it('emits attribute selectors between child and sibling combinators', function () {
        $logger   = new ArrayLogger();
        $compiler = new Compiler(logger: $logger);

        $source = <<<'SCSS'
        @layer content {
          .table-card {
            > .table, > [class*="table-responsive"] > .table { margin: 0; }
            > [class*="table-responsive"] > .table > :not(caption):last-child > * { border: 0; }
          }
          .btn-group-divider > [class*="btn-"] + [class*="btn-"]::before { content: ""; }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @layer content {
          .table-card > .table, .table-card > [class*=table-responsive] > .table {
            margin: 0;
          }
          .table-card > [class*=table-responsive] > .table > :not(caption):last-child > * {
            border: 0;
          }
          .btn-group-divider > [class*=btn-] + [class*=btn-]::before {
            content: "";
          }
        }
        CSS;

        expect($compiler->compileString($source))->toEqualCss($expected)
            ->and($logger->records)->toBeEmpty();
    });

    it('keeps content keyframe selectors inside keyframes nested in a layer', function () {
        $source = <<<'SCSS'
        @mixin emit { @content; }
        @layer x {
          .a {
            @keyframes fade {
              @include emit {
                from { opacity: 0; }
                to { opacity: 1; }
              }
            }
          }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @layer x {
          @keyframes fade {
            from {
              opacity: 0;
            }
            to {
              opacity: 1;
            }
          }
        }
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expected);
    });

    it('keeps content declarations inside font-face nested in a layer', function () {
        $source = <<<'SCSS'
        @mixin emit { @content; }
        @layer x {
          .a {
            @font-face {
              @include emit {
                font-family: example;
                src: url(example.woff2);
              }
            }
          }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @layer x {
          @font-face {
            font-family: example;
            src: url(example.woff2);
          }
        }
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expected);
    });
});
