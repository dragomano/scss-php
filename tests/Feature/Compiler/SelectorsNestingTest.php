<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {
        it('compiles simple CSS rules', function () {
            $source = <<<'SCSS'
            .test { color: red; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('compiles nested rules', function () {
            $source = <<<'SCSS'
            .parent { .child { margin: 10px; } }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .parent .child {
              margin: 10px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('keeps nested selector behavior for include inside nested rule', function () {
            $source = <<<'SCSS'
            @mixin interactive-title {
              color: red;

              &:hover {
                color: blue;
              }
            }

            .card {
              .title {
                @include interactive-title;
                font-weight: bold;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .card .title {
              color: red;
              font-weight: bold;
            }
            .card .title:hover {
              color: blue;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('bubbles media from include inside nested rule', function () {
            $source = <<<'SCSS'
            @mixin responsive-title {
              color: red;

              @media (min-width: 40rem) {
                color: blue;
              }
            }

            .card {
              .title {
                @include responsive-title;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .card .title {
              color: red;
            }
            @media (min-width: 40rem) {
              .card .title {
                color: blue;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps at-root chunks from include inside nested rule', function () {
            $source = <<<'SCSS'
            @mixin nested-utility {
              @at-root .utility {
                display: block;
              }

              color: red;
            }

            .card {
              .title {
                @include nested-utility;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .card .title {
              color: red;
            }
            .utility {
              display: block;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('replaces ampersand in nested selectors', function () {
            $source = <<<'SCSS'
            .card {
              &:hover { color: red; }
              &.active, &.selected { border: 1px solid #000; }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .card:hover {
              color: red;
            }
            .card.active, .card.selected {
              border: 1px solid #000;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('replaces ampersand in nested selectors inside media query', function () {
            $source = <<<'SCSS'
            .card {
              @media (max-width: 600px) {
                &:hover { color: red; }
                .title { font-size: 14px; }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media (max-width: 600px) {
              .card:hover {
                color: red;
              }
              .card .title {
                font-size: 14px;
              }
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('resolves variables in @media prelude', function () {
            $source = <<<'SCSS'
            $layout-breakpoint-small: 960px;

            @media (min-width: $layout-breakpoint-small) {
              .hide-extra-small {
                display: none;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media (min-width: 960px) {
              .hide-extra-small {
                display: none;
              }
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('combines nested media queries when possible', function () {
            $source = <<<'SCSS'
            @media (hover: hover) {
              .button:hover {
                border: 2px solid black;
              }

              @media (color) {
                .button:hover {
                  border-color: #036;
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media (hover: hover) {
              .button:hover {
                border: 2px solid black;
              }
            }
            @media (hover: hover) and (color) {
              .button:hover {
                border-color: #036;
              }
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('compiles @supports blocks', function () {
            $source = <<<'SCSS'
            @supports (display: grid) and (display: inline-grid) { .test { display: grid; } }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @supports (display: grid) and (display: inline-grid) {
              .test {
                display: grid;
              }
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('places bubbled @supports blocks after the parent rule', function () {
            $source = <<<'SCSS'
            .banner {
              @supports (position: sticky) {
                position: sticky;
              }

              position: fixed;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @supports (position: sticky) {
              .banner {
                position: sticky;
              }
            }
            .banner {
              position: fixed;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('splits outer declarations around nested rules using modern mixed declaration ordering', function () {
            $source = <<<'SCSS'
            .case {
              color: red;

              &--serious {
                font-weight: bold;
              }

              font-weight: normal;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .case {
              color: red;
            }
            .case--serious {
              font-weight: bold;
            }
            .case {
              font-weight: normal;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('preserves source order when a nested parent selector emits the same selector', function () {
            $source = <<<'SCSS'
            .case {
              color: red;

              & {
                color: blue;
              }

              color: green;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .case {
              color: red;
            }
            .case {
              color: blue;
            }
            .case {
              color: green;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('interpolates values in @supports conditions', function () {
            $source = <<<'SCSS'
            $query: "(feature1: val)";
            @supports #{$query} { .test { a: b; } }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @supports (feature1: val) {
              .test {
                a: b;
              }
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates variable expressions in @supports conditions', function () {
            $source = <<<'SCSS'
            $feature: feature2;
            $val: val;
            @supports ($feature: $val) { .test { a: b; } }
            @supports (not ($feature + 3: $val + 4)) { .test { a: b; } }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @supports (feature2: val) {
              .test {
                a: b;
              }
            }
            @supports not (feature23: val4) {
              .test {
                a: b;
              }
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('parses pseudo-class selectors inside @for loop blocks', function () {
            $source = <<<'SCSS'
            @for $i from 1 through 3 {
              ul:nth-child(3n + #{$i}) {
                margin-left: $i * 10;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            ul:nth-child(3n + 1) {
              margin-left: 10;
            }
            ul:nth-child(3n + 2) {
              margin-left: 20;
            }
            ul:nth-child(3n + 3) {
              margin-left: 30;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('interpolates nth() selector lookup with arithmetic index', function () {
            $source = <<<'SCSS'
            @mixin order($height, $selectors...) {
              @for $i from 0 to length($selectors) {
                #{nth($selectors, $i + 1)} {
                  position: absolute;
                  height: $height;
                  margin-top: $i * $height;
                }
              }
            }

            @include order(150px, "input.name", "input.address", "input.zip");
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            input.name {
              position: absolute;
              height: 150px;
              margin-top: 0;
            }
            input.address {
              position: absolute;
              height: 150px;
              margin-top: 150px;
            }
            input.zip {
              position: absolute;
              height: 150px;
              margin-top: 300px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports @at-root for moving nested content to root level', function () {
            $source = <<<'SCSS'
            .parent {
              color: blue;

              @at-root {
                .outside {
                  color: red;
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .parent {
              color: blue;
            }
            .outside {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('bubbles nested @media at-rules out of style rules', function () {
            $source = <<<'SCSS'
            .print-only {
              display: none;

              @media print {
                display: block;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .print-only {
              display: none;
            }
            @media print {
              .print-only {
                display: block;
              }
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('bubbles nested @container at-rules out of style rules', function () {
            $source = <<<'SCSS'
            .article_alt3_view {
              @container (min-width: 400px) {
                .card {
                  flex-direction: row;
                  align-items: normal;

                  .lazy {
                    width: 50%;
                  }
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @container (min-width: 400px) {
              .article_alt3_view .card {
                flex-direction: row;
                align-items: normal;
              }
              .article_alt3_view .card .lazy {
                width: 50%;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('resolves parent selector for rules inside @at-root block form', function () {
            $source = <<<'SCSS'
            .component {
              @at-root {
                .component--compact & {
                  padding: 0.8rem;
                }

                .component--bordered & {
                  border: 1px solid #ddd;
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .component--compact .component {
              padding: .8rem;
            }
            .component--bordered .component {
              border: 1px solid #ddd;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports @at-root with and without query filters for at-rules', function () {
            $source = <<<'SCSS'
            @media print {
              .page {
                width: 8in;

                @at-root (without: media) {
                  color: #111;
                }

                @at-root (with: rule) {
                  font-size: 1.2em;
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media print {
              .page {
                width: 8in;
              }
            }
            .page {
              color: #111;
            }
            .page {
              font-size: 1.2em;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports @at-root (without: rule) and keeps surrounding at-rules', function () {
            $source = <<<'SCSS'
            @media print {
              .page {
                @at-root (without: rule) {
                  .note {
                    color: #333;
                  }
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media print {
              .note {
                color: #333;
              }
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports @at-root (without: all) and excludes both style and at-rules', function () {
            $source = <<<'SCSS'
            @media print {
              .page {
                @at-root (without: all) {
                  .warn {
                    color: #900;
                  }
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .warn {
              color: #900;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('renders parent selector value for ampersand in declaration values', function () {
            $source = <<<'SCSS'
            .main aside:hover,
            .sidebar p {
              parent-selector: &;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .main aside:hover, .sidebar p {
              parent-selector: .main aside:hover, .sidebar p;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates inline if() with sass(&) in selector interpolation', function () {
            $source = <<<'SCSS'
            @mixin app-background($color) {
              #{if(sass(&): '&.app-background'; else: '.app-background')} {
                background-color: $color;
                color: rgba(#fff, 0.75);
              }
            }

            @include app-background(#036);

            .sidebar {
              @include app-background(#c6538c);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .app-background {
              background-color: #036;
              color: rgba(255, 255, 255, .75);
            }
            .sidebar.app-background {
              background-color: #c6538c;
              color: rgba(255, 255, 255, .75);
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports selector.unify with parent selector inside @at-root mixin', function () {
            $source = <<<'SCSS'
            @use "sass:selector";

            @mixin unify-parent($child) {
              @at-root #{selector.unify(&, $child)} {
                @content;
              }
            }

            .wrapper .field {
              @include unify-parent("input") {
                /* ... */
              }
              @include unify-parent("select") {
                /* ... */
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .wrapper input.field {
              /* ... */
            }
            .wrapper select.field {
              /* ... */
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('handles grouped selectors with nested pseudo-classes', function () {
            $source = <<<'SCSS'
            #lp_layout {
              h3,
              h4 {
                &:hover {
                  white-space: normal;
                }
              }
            }
            SCSS;

            $expected = <<<'CSS'
            #lp_layout h3:hover, #lp_layout h4:hover {
              white-space: normal;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('handles nested pseudo-elements', function () {
            $source = <<<'SCSS'
            .fa-portal {
              &::before {
                content: "\f0ac";
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .fa-portal::before {
              content: "\f0ac";
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('handles child combinators in nesting', function () {
            $source = <<<'SCSS'
            .sidebar > {
              .error {
                color: red;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .sidebar > .error {
              color: red;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('handles direct child combinators', function () {
            $source = <<<'SCSS'
            .article_view {
              > div {
                margin-bottom: 10px;
              }
            }
            SCSS;

            $expected = <<<'CSS'
            .article_view > div {
              margin-bottom: 10px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('handles complex nested pseudo-classes', function () {
            $source = <<<'SCSS'
            .article {
              &:nth-last-child(-n+5) {
                grid-column: span 2;
              }

              &:nth-last-child(2),
              &:last-child {
                grid-column: span 3;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .article:nth-last-child(-n+5) {
              grid-column: span 2;
            }
            .article:nth-last-child(2), .article:last-child {
              grid-column: span 3;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('handles pseudo-classes with nested selectors', function () {
            $source = <<<'SCSS'
            .article_simple_view {
              > div {
                &:hover {
                  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, .1), 0 1px 2px 0 rgba(0, 0, 0, .06);
                }
              }
            }
            SCSS;

            $expected = <<<'CSS'
            .article_simple_view > div:hover {
              box-shadow: 0 1px 3px 0 rgba(0, 0, 0, .1), 0 1px 2px 0 rgba(0, 0, 0, .06);
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('handles descendant pseudo-classes', function () {
            $source = <<<'SCSS'
            article {
              a:hover {
                text-decoration: none;
                opacity: .7;
              }
            }
            SCSS;

            $expected = <<<'CSS'
            article a:hover {
              text-decoration: none;
              opacity: .7;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('handles nested selectors with margins', function () {
            $source = <<<'SCSS'
            section {
              #display_head {
                margin-top: .1em;
                margin-bottom: 0;

                span {
                  margin: 0;
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            section #display_head {
              margin-top: .1em;
              margin-bottom: 0;
            }
            section #display_head span {
              margin: 0;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('handles basic @supports syntax variants', function (string $source, string $expected) {
            expect($this->compiler->compileString($source))->toEqualCss($expected);
        })->with([
            ['@supports (animation-name: test) { foo {a: b} }', "@supports (animation-name: test) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports (transform-origin: 5% 5%) { foo {a: b} }', "@supports (transform-origin: 5% 5%) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports selector(A > B) { foo {a: b} }', "@supports selector(A > B) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports not (transform-origin: 10em 10em 10em) { foo {a: b} }', "@supports not (transform-origin: 10em 10em 10em) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports not (not (transform-origin: 2px)) { foo {a: b} }', "@supports not (not (transform-origin: 2px)) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports (display: table-cell) and (display: list-item) { foo {a: b} }', "@supports (display: table-cell) and (display: list-item) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports (display: table-cell) and (display: list-item) and (display: run-in) { foo {a: b} }', "@supports (display: table-cell) and (display: list-item) and (display: run-in) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports (display: table-cell) and ((display: list-item) and (display:run-in)) { foo {a: b} }', "@supports (display: table-cell) and (display: list-item) and (display: run-in) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports (display: table-cell) and ((display: list-item) or (display:run-in)) { foo {a: b} }', "@supports (display: table-cell) and ((display: list-item) or (display: run-in)) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports (transform-style: preserve) or (-moz-transform-style: preserve) { foo {a: b} }', "@supports (transform-style: preserve) or (-moz-transform-style: preserve) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports (transform-style: preserve) or (-moz-transform-style: preserve) or (-o-transform-style: preserve) or (-webkit-transform-style: preserve) { foo {a: b} }', "@supports (transform-style: preserve) or (-moz-transform-style: preserve) or (-o-transform-style: preserve) or (-webkit-transform-style: preserve) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports (transform-style: preserve-3d) or ((-moz-transform-style: preserve-3d) or ((-o-transform-style: preserve-3d) or (-webkit-transform-style: preserve-3d))) { foo {a: b} }', "@supports (transform-style: preserve-3d) or (-moz-transform-style: preserve-3d) or (-o-transform-style: preserve-3d) or (-webkit-transform-style: preserve-3d) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports not ((text-align-last: justify) or (-moz-text-align-last: justify)) { foo {a: b} }', "@supports not ((text-align-last: justify) or (-moz-text-align-last: justify)) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports (--foo: green) { foo {a: b} }', "@supports (--foo: green) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports not selector(:is(a, b)) { foo {a: b} }', "@supports not selector(:is(a, b)) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports selector(:nth-child(2n of .foo)) { foo {a: b} }', "@supports selector(:nth-child(2n of .foo)) {\n  foo {\n    a: b;\n  }\n}"],
            ['@supports ((display: grid) or (display: subgrid)) { foo {a: b} }', "@supports ((display: grid) or (display: subgrid)) {\n  foo {\n    a: b;\n  }\n}"],
        ]);

        it('handles interpolated @supports conditions in direct syntax forms', function () {
            $source = <<<'SCSS'
            $feature: grid;
            $wrapped: "(display: grid)";

            @supports (display: #{$feature}) { foo {a: b} }
            @supports #{$wrapped} { bar {a: b} }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @supports (display: grid) {
              foo {
                a: b;
              }
            }
            @supports (display: grid) {
              bar {
                a: b;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('handles additional @at-root block forms', function () {
            $source = <<<'SCSS'
            .parent {
              .child {
                @at-root {
                  .sibling { color: red; }
                }
              }
            }

            .block {
              &__element {
                @at-root {
                  .other { color: blue; }
                  .another { color: green; }
                }
              }
            }

            .outer {
              .middle {
                @at-root {
                  .inner { color: red; }
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .sibling {
              color: red;
            }
            .other {
              color: blue;
            }
            .another {
              color: green;
            }
            .inner {
              color: red;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });
    });
});
