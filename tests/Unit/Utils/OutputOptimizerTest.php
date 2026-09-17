<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Style;
use Bugo\SCSS\Utils\OutputOptimizer;

beforeEach(function () {
    $this->optimizer = new OutputOptimizer();
});

it('adds charset for non ascii css', function () {
    $source = /** @lang text */ <<<'SCSS'
    .test { content: "панда"; }
    SCSS;

    $result = $this->optimizer->optimize($source, new CompilerOptions());

    expect($result)->toStartWith("@charset \"UTF-8\";\n");
});

it('keeps ascii css without charset prefix', function () {
    $source = /** @lang text */ <<<'SCSS'
    .test { color: red; }
    SCSS;

    $result = $this->optimizer->optimize($source, new CompilerOptions());

    expect($result)->toBe($source);
});

it('adds blank lines between root rules in expanded style', function () {
    $options = new CompilerOptions(style: Style::EXPANDED);
    $source  = /** @lang text */ <<<'SCSS'
    .first { width: 1px; }
    .second { width: 2px; }
    SCSS;

    $expected = /** @lang text */ <<<'CSS'
    .first { width: 1px; }

    .second { width: 2px; }
    CSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe($expected);
});

it('compresses css output when style is compressed', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'SCSS'
    /* comment */ .test { width: 10px; opacity: 0.7; } /*# sourceMappingURL=style.css.map */
    SCSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('.test{width:10px;opacity:0.7}');
});

it('keeps an unterminated source map comment when compressed', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'CSS'
    .test { color: red; } /*# sourceMappingURL=a.css.map
    CSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('.test{color:red}/*# sourceMappingURL=a.css.map');
});

it('removes spaces around multiplication in math expressions when compressed', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'SCSS'
    .test { padding: max(8px, min(10px, 2vw) * 2); }
    SCSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('.test{padding:max(8px,min(10px,2vw)*2)}');
});

it('keeps preserved comments without inserting extra spaces when compressed', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'SCSS'
    /*! one */ /*! two */ .test { width: 1px; }
    SCSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('/*! one *//*! two */.test{width:1px}');
});

it('removes spaces between adjacent function calls when compressed', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'SCSS'
    .test { filter: hue-rotate(120deg) saturate(113%); }
    SCSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('.test{filter:hue-rotate(120deg)saturate(113%)}');
});

it('preserves raw rgba literals in compressed output', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'SCSS'
    .test { box-shadow: 0 2px 5px rgba(0,0,0,.3); color: rgba(255,255,255,1); }
    SCSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('.test{box-shadow:0 2px 5px rgba(0,0,0,.3);color:rgba(255,255,255,1)}');
});

it('shortens hue-rotate zero angle in compressed output', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'SCSS'
    .test { filter: hue-rotate(0deg) saturate(100%); }
    SCSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('.test{filter:hue-rotate(0)saturate(100%)}');
});

it('keeps blank lines in input in expanded style', function () {
    $options = new CompilerOptions(style: Style::EXPANDED);
    $source  = /** @lang text */ <<<'SCSS'
    .a { width: 1px; }

    .b { width: 2px; }
    SCSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe($source);
});

it('adds blank lines inside nested blocks', function () {
    $options  = new CompilerOptions(style: Style::EXPANDED);
    $source   = /** @lang text */ <<<'SCSS'
    .a {
      .b { width: 1px; }
    }
    .c { width: 2px; }
    SCSS;

    $expected = /** @lang text */ <<<'CSS'
    .a .b { width: 1px; }

    .c { width: 2px; }
    CSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe($expected);
});

it('preserves css content after charset declaration', function () {
    $source   = /** @lang text */ <<<'SCSS'
    .test { content: "панда"; }
    SCSS;

    $expected = /** @lang text */ <<<'CSS'
    @charset "UTF-8";
    .test { content: "панда"; }
    CSS;

    $result = $this->optimizer->optimize($source, new CompilerOptions());

    expect($result)->toBe($expected);
});
