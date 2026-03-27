<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\SassErrorException;
use Tests\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {
        it('supports @extend when extender is declared after base selector', function () {
            $source = <<<'SCSS'
            .base {
              color: red;
            }

            .first {
              @extend .base;
            }

            .second {
              @extend .base;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .base, .second, .first {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports transitive @extend chains', function () {
            $source = <<<'SCSS'
            .a {
              color: red;
            }

            .b {
              @extend .a;
            }

            .c {
              @extend .b;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a, .b, .c {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports @extend target as selector list of simple selectors', function () {
            $source = <<<'SCSS'
            .message {
              color: red;
            }

            .info {
              background: green;
            }

            .alert {
              @extend .message, .info;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .message, .alert {
              color: red;
            }
            .info, .alert {
              background: green;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('throws when @extend target is a compound selector', function () {
            $source = <<<'SCSS'
            .message {
              color: red;
            }

            .alert {
              @extend .main.message;
            }
            SCSS;

            expect(fn() => $this->compiler->compileString($source))
                ->toThrow(SassErrorException::class, 'Compound selectors may not be extended');
        });

        it('throws when @extend target is a complex selector', function () {
            $source = <<<'SCSS'
            .message {
              color: red;
            }

            .alert {
              @extend .main .message;
            }
            SCSS;

            expect(fn() => $this->compiler->compileString($source))
                ->toThrow(SassErrorException::class, 'Complex selectors may not be extended');
        });

        it('throws when @extend crosses media query boundaries', function () {
            $source = <<<'SCSS'
            @media screen and (max-width: 600px) {
              .error--serious {
                @extend .error;
              }
            }

            .error {
              border: 1px #f00;
              background-color: #fdd;
            }
            SCSS;

            expect(fn() => $this->compiler->compileString($source))
                ->toThrow(SassErrorException::class, 'You may not @extend selectors across media queries.');
        });

        it('extends simple selectors inside pseudo-classes', function () {
            $source = <<<'SCSS'
            .error:hover {
              background-color: #fee;
            }

            .error--serious {
              @extend .error;
              border-width: 3px;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .error:hover, .error--serious:hover {
              background-color: #fee;
            }
            .error--serious {
              border-width: 3px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('extends nested selectors without generating impossible or redundant combinations', function () {
            $source = <<<'SCSS'
            .content nav.sidebar {
              @extend .info;
            }

            p.info {
              background-color: #dee9fc;
            }

            .guide .info {
              border: 1px solid rgba(#000, 0.8);
              border-radius: 2px;
            }

            main.content .info {
              font-size: 0.8em;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            p.info {
              background-color: #dee9fc;
            }
            .guide .info, .guide .content nav.sidebar, .content .guide nav.sidebar {
              border: 1px solid rgba(0, 0, 0, .8);
              border-radius: 2px;
            }
            main.content .info, main.content nav.sidebar {
              font-size: .8em;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('combines independent extends within a complex selector', function () {
            $source = <<<'SCSS'
            .a .b {
              color: red;
            }

            .x {
              @extend .a;
            }

            .y {
              @extend .b;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a .b, .a .y, .x .b, .x .y {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('preserves compound selector order when combining extends', function () {
            $source = <<<'SCSS'
            .a.b {
              color: red;
            }

            .x {
              @extend .a;
            }

            .y {
              @extend .b;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a.b, .a.y, .b.x, .x.y {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('extends repeated occurrences of the same target selector', function () {
            $source = <<<'SCSS'
            .error .error {
              color: red;
            }

            .serious {
              @extend .error;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .error .error, .serious .error, .error .serious, .serious .serious {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('weaves ancestor chains when extending selectors that contain combinators', function () {
            $source = <<<'SCSS'
            .layout > .warning .title {
              color: red;
            }

            .sidebar .notice {
              @extend .warning;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .layout > .warning .title, .sidebar .layout > .notice .title {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports transitive extends through placeholders', function () {
            $source = <<<'SCSS'
            %base {
              color: red;
            }

            .button {
              @extend %base;
            }

            .primary-button {
              @extend .button;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .button, .primary-button {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('combines child combinator replacements across multiple extend targets', function () {
            $source = <<<'SCSS'
            .container > .item {
              color: red;
            }

            .panel {
              @extend .container;
            }

            .entry {
              @extend .item;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .container > .item, .container > .entry, .panel > .item, .panel > .entry {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports exact extend chains that end in compound selectors', function () {
            $source = <<<'SCSS'
            .button {
              color: red;
            }

            .primary {
              @extend .button;
            }

            .large.primary {
              @extend .primary;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .button, .primary, .large.primary {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('propagates placeholder extends into compound extenders', function () {
            $source = <<<'SCSS'
            %base .title {
              color: red;
            }

            .card {
              @extend %base;
            }

            .featured.card {
              @extend .card;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .card .title {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('uses heuristic interleaving for complex ancestor chains in @extend', function () {
            $source = <<<'SCSS'
            header .warning li {
              font-weight: bold;
            }

            aside .notice dd {
              @extend li;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            header .warning li, header .warning aside .notice dd, aside .notice header .warning dd {
              font-weight: bold;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('does not render placeholder selectors and only emits extended placeholders', function () {
            $source = <<<'SCSS'
            %message-shared {
              border: 1px solid #ccc;
              padding: 10px;
              color: #333;
            }

            %equal-heights {
              display: flex;
              flex-wrap: wrap;
            }

            .message {
              @extend %message-shared;
            }

            .success {
              @extend %message-shared;
              border-color: green;
            }

            .error {
              @extend %message-shared;
              border-color: red;
            }

            .warning {
              @extend %message-shared;
              border-color: yellow;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .warning, .error, .success, .message {
              border: 1px solid #ccc;
              padding: 10px;
              color: #333;
            }
            .success {
              border-color: green;
            }
            .error {
              border-color: red;
            }
            .warning {
              border-color: yellow;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('orders placeholder extenders in reverse declaration order like dart sass', function () {
            $source = <<<'SCSS'
            %toolbelt {
              box-sizing: border-box;
              border-top: 1px rgba(#000, .12) solid;
              padding: 16px 0;
              width: 100%;

              &:hover { border: 2px rgba(#000, .5) solid; }
            }

            .action-buttons {
              @extend %toolbelt;
              color: #4285f4;
            }

            .reset-buttons {
              @extend %toolbelt;
              color: #cddc39;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .reset-buttons, .action-buttons {
              box-sizing: border-box;
              border-top: 1px rgba(0, 0, 0, .12) solid;
              padding: 16px 0;
              width: 100%;
            }
            .reset-buttons:hover, .action-buttons:hover {
              border: 2px rgba(0, 0, 0, .5) solid;
            }
            .action-buttons {
              color: #4285f4;
            }
            .reset-buttons {
              color: #cddc39;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('orders many simple extenders in reverse declaration order like dart sass', function () {
            $source = <<<'SCSS'
            .s {
              color: red;
            }

            @for $i from 1 through 1000 {
              .c#{$i} {
                @extend .s;
              }
            }
            SCSS;

            $selectors = array_map(
                static fn(int $index): string => '.c' . $index,
                range(1000, 1),
            );

            $expected = /** @lang text */ '.s, ' . implode(', ', $selectors) . <<<'CSS'
             {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('omits placeholder-only rules and placeholder parts in mixed selector lists without extends', function () {
            $source = <<<'SCSS'
            .alert:hover, %strong-alert {
              font-weight: bold;
            }

            %strong-alert:hover {
              color: red;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .alert:hover {
              font-weight: bold;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });
    });
});
