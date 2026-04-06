<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;

describe('Compiler', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    it('compiles mixins', function () {
        $source = <<<'SCSS'
        @mixin button-style($color) { color: $color; }
        .test { @include button-style(red); }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          color: red;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('compiles bRadius mixin with chained and/or conditions', function () {
        $source = <<<'SCSS'
        @mixin bRadius($topLeft: 6px, $topRight: null, $bottomRight: null, $bottomLeft: null) {
          @if $topRight == null and $bottomRight == null and $bottomLeft == null and $topLeft != null and $topLeft >= 0 {
            border-radius: $topLeft;
          } @else {
            $tl: if($topLeft == null or $topLeft < 0, 0, $topLeft);
            $tr: if($topRight == null or $topRight < 0, 0, $topRight);
            $br: if($bottomRight == null or $bottomRight < 0, 0, $bottomRight);
            $bl: if($bottomLeft == null or $bottomLeft < 0, 0, $bottomLeft);

            @if $tl == $tr and $tr == $br and $br == $bl {
              border-radius: $tl;
            } @else if $tl == $br and $tr == $bl {
              border-radius: $tl $tr;
            } @else if $tr == $bl {
              border-radius: $tl $tr $br;
            } @else {
              border-radius: $tl $tr $br $bl;
            }
          }
        }

        .default { @include bRadius(); }
        .single { @include bRadius(8px); }
        .pair { @include bRadius(10px, 20px, 10px, 20px); }
        .triple { @include bRadius(1px, 2px, 3px, 2px); }
        .quad { @include bRadius(8px, 4px, 2px, 1px); }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .default {
          border-radius: 6px;
        }
        .single {
          border-radius: 8px;
        }
        .pair {
          border-radius: 10px 20px;
        }
        .triple {
          border-radius: 1px 2px 3px;
        }
        .quad {
          border-radius: 8px 4px 2px 1px;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('bubbles keyframes from mixin content and keeps interpolated names without extra spaces', function () {
        $source = <<<'SCSS'
        @mixin inline-animation($duration) {
          $name: inline-#{unique-id()};

          @keyframes #{$name} {
            @content;
          }

          animation-name: $name;
          animation-duration: $duration;
          animation-iteration-count: infinite;
        }

        .pulse {
          @include inline-animation(2s) {
            from { background-color: yellow }
            to { background-color: red }
          }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @keyframes inline-u1 {
          from {
            background-color: yellow;
          }

          to {
            background-color: red;
          }
        }
        .pulse {
          animation-name: inline-u1;
          animation-duration: 2s;
          animation-iteration-count: infinite;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('keeps @content lexical scope and does not expose mixin-local variables', function () {
        $source = <<<'SCSS'
        @mixin hover {
          $inner-border-width: 10px;

          &:not([disabled]):hover {
            outline: $inner-border-width solid red;
            @content;
          }
        }

        .button {
          border: 1px solid black;
          $outer-border-width: 2px;

          @include hover {
            border-width: $outer-border-width;
            border-top-width: $inner-border-width;
          }
        }
        SCSS;

        expect(fn() => $this->compiler->compileString($source))
            ->toThrow(UndefinedSymbolException::class, 'Undefined variable: $inner-border-width');
    });

    it('passes @content arguments into @include using parameters', function () {
        $source = <<<'SCSS'
        @mixin media($types...) {
          @each $type in $types {
            @media #{$type} {
              @content($type);
            }
          }
        }

        @include media(screen, print) using ($type) {
          h1 {
            font-size: 40px;

            @if $type == print {
              font-family: Calluna;
            }
          }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @media screen {
          h1 {
            font-size: 40px;
          }
        }
        @media print {
          h1 {
            font-size: 40px;
            font-family: Calluna;
          }
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('bubbles @content output through a mixin-created media query', function () {
        $source = <<<'SCSS'
        @mixin media($condition...) {
          @media (#{$condition}) {
            @content;
          }
        }

        .content-media-bug {
          @include media("min-width: 600px") {
            width: 50%;
          }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @media (min-width: 600px) {
          .content-media-bug {
            width: 50%;
          }
        }
        CSS;

        expect($this->compiler->compileString($source))->toEqualCss($expected);
    });

    it('keeps mixin output grouped when a mixin is included inside @media', function () {
        $source = <<<'SCSS'
        @mixin bordered {
          border: 1px solid black;
        }

        .media-grouping-bug {
          @media (min-width: 768px) {
            @include bordered;
            color: blue;
          }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @media (min-width: 768px) {
          .media-grouping-bug {
            border: 1px solid black;
            color: blue;
          }
        }
        CSS;

        expect($this->compiler->compileString($source))->toEqualCss($expected);
    });

    it('bubbles a mixin-created media query after base declarations', function () {
        $source = <<<'SCSS'
        @mixin desktop {
          @media (min-width: 1024px) {
            @content;
          }
        }

        .button {
          width: 100%;

          @include desktop {
            width: auto;
            background: blue;
          }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .button {
          width: 100%;
        }
        @media (min-width: 1024px) {
          .button {
            width: auto;
            background: blue;
          }
        }
        CSS;

        expect($this->compiler->compileString($source))->toEqualCss($expected);
    });

    it('keeps declarations outside and inside mixin-created media blocks separated correctly', function () {
        $source = <<<'SCSS'
        @mixin test {
          border: 1px solid black;
          @media (min-width: 600px) {
            color: red;
          }
        }

        .rule {
          @include test;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .rule {
          border: 1px solid black;
        }
        @media (min-width: 600px) {
          .rule {
            color: red;
          }
        }
        CSS;

        expect($this->compiler->compileString($source))->toEqualCss($expected);
    });

    it('keeps only the last duplicate declaration after @include without extra blank lines', function () {
        $source = <<<'SCSS'
        @mixin button-style($color) {
          background-color: $color;
          border-radius: 7px;
          &:hover {
            background-color: blue;
          }
        }

        .test {
          @include button-style(red);
          border-radius: 3px;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          background-color: red;
          border-radius: 3px;
        }
        .test:hover {
          background-color: blue;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('keeps vendor fallback declarations with the same property after @include', function () {
        $source = <<<'SCSS'
        @mixin flexbox() {
          display: -webkit-box;
          display: -moz-box;
          display: -ms-flexbox;
          display: -webkit-flex;
          display: flex;
        }

        @mixin flex($values) {
          -webkit-box-flex: $values;
          -moz-box-flex: $values;
          -webkit-flex: $values;
          -ms-flex: $values;
          flex: $values;
        }

        @mixin order($val) {
          -webkit-box-ordinal-group: $val;
          -moz-box-ordinal-group: $val;
          -ms-flex-order: $val;
          -webkit-order: $val;
          order: $val;
        }

        .wrapper {
          @include flexbox();
        }

        .item {
          @include flex(1 200px);
          @include order(2);
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .wrapper {
          display: -webkit-box;
          display: -moz-box;
          display: -ms-flexbox;
          display: -webkit-flex;
          display: flex;
        }
        .item {
          -webkit-box-flex: 1 200px;
          -moz-box-flex: 1 200px;
          -webkit-flex: 1 200px;
          -ms-flex: 1 200px;
          flex: 1 200px;
          -webkit-box-ordinal-group: 2;
          -moz-box-ordinal-group: 2;
          -ms-flex-order: 2;
          -webkit-order: 2;
          order: 2;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('keeps include-generated nested selector order before following nested rules', function () {
        $source = <<<'SCSS'
        @mixin variations($color) {
          .light { color: lighten($color, 20%); }
          .dark { color: darken($color, 20%); }
        }

        .class-0 {
          @include variations(#3399ff);

          &.nested-1 {
            color: red;
          }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .class-0 .light {
          color: #9cf;
        }
        .class-0 .dark {
          color: #06c;
        }
        .class-0.nested-1 {
          color: red;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('treats hyphens and underscores as equivalent in mixin names', function () {
        $source = <<<'SCSS'
        @mixin reset-list() {
          margin: 0;
          padding: 0;
        }

        .test {
          @include reset_list();
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          margin: 0;
          padding: 0;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('throws when @include is used before mixin declaration', function () {
        $source = <<<'SCSS'
        @include my-mixin();

        @mixin my-mixin() {
          color: red;
        }
        SCSS;

        expect(fn() => $this->compiler->compileString($source))
            ->toThrow(UndefinedSymbolException::class, 'Undefined mixin: my-mixin');
    });
});
