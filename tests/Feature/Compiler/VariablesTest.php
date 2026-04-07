<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;

describe('Compiler', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    it('keeps local variable assignments scoped to the current rule', function () {
        $source = <<<'SCSS'
        $variable: global value;

        .content {
          $variable: local value;
          value: $variable;
        }

        .sidebar {
          value: $variable;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .content {
          value: local value;
        }
        .sidebar {
          value: global value;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('supports !default and !global variable modifiers', function () {
        $source = <<<'SCSS'
        $brand: null;
        $brand: blue !default;
        $size: 1px;

        .box {
          $size: 2px !global;
          color: $brand;
          width: $size;
        }

        .after {
          width: $size;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .box {
          color: blue;
          width: 2px;
        }
        .after {
          width: 2px;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('compiles variable declarations and references', function () {
        $source = <<<'SCSS'
        $primary-color: #333;
        .test { color: $primary-color; }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          color: #333;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('treats hyphens and underscores as equivalent in variable names', function () {
        $source = <<<'SCSS'
        $font-size: 16px;
        .test {
          a: $font_size;
          b: $font-size;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          a: 16px;
          b: 16px;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('allows reassignment across underscore and hyphen spellings for one variable', function () {
        $source = <<<'SCSS'
        $font_size: 12px;
        $font-size: 20px;
        .test {
          value: $font_size;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          value: 20px;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('throws when variable is used before its declaration inside a rule block', function () {
        $source = <<<'SCSS'
        .a {
          color: $x;
          $x: red;
        }
        SCSS;

        expect(fn() => $this->compiler->compileString($source))
            ->toThrow(UndefinedSymbolException::class, 'Undefined variable: $x');
    });
});
