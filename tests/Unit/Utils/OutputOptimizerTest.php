<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Style;
use Bugo\SCSS\Utils\OutputOptimizer;

beforeEach(function () {
    $this->optimizer = new OutputOptimizer();
});

it('adds charset for non ascii css', function () {
    $source = /** @lang text */ <<<'CSS'
    .test { content: "панда"; }
    CSS;

    $result = $this->optimizer->optimize($source, new CompilerOptions());

    expect($result)->toStartWith("@charset \"UTF-8\";\n");
});

it('keeps ascii css without charset prefix', function () {
    $source = /** @lang text */ <<<'CSS'
    .test { color: red; }
    CSS;

    $result = $this->optimizer->optimize($source, new CompilerOptions());

    expect($result)->toBe($source);
});

it('adds blank lines between root rules when splitRules is enabled', function () {
    $options = new CompilerOptions(splitRules: true);
    $source  = /** @lang text */ <<<'CSS'
    .first { width: 1px; }
    .second { width: 2px; }
    CSS;

    $expected = /** @lang text */ <<<'CSS'
    .first { width: 1px; }

    .second { width: 2px; }
    CSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe($expected);
});

it('compresses css output when style is compressed', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'CSS'
    /* comment */ .test { width: 10px; opacity: 0.7; } /*# sourceMappingURL=style.css.map */
    CSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('.test{width:10px;opacity:0.7}/*# sourceMappingURL=style.css.map */');
});

it('removes spaces around multiplication in math expressions when compressed', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'CSS'
    .test { padding: max(8px, min(10px, 2vw) * 2); }
    CSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('.test{padding:max(8px,min(10px,2vw)*2)}');
});

it('keeps preserved comments without inserting extra spaces when compressed', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'CSS'
    /*! one */ /*! two */ .test { width: 1px; }
    CSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('/*! one *//*! two */.test{width:1px}');
});

it('removes spaces between adjacent function calls when compressed', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'CSS'
    .test { filter: hue-rotate(120deg) saturate(113%); }
    CSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('.test{filter:hue-rotate(120deg)saturate(113%)}');
});

it('preserves raw rgba literals in compressed output', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'CSS'
    .test { box-shadow: 0 2px 5px rgba(0,0,0,.3); color: rgba(255,255,255,1); }
    CSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('.test{box-shadow:0 2px 5px rgba(0,0,0,.3);color:rgba(255,255,255,1)}');
});

it('shortens hue-rotate zero angle in compressed output', function () {
    $options = new CompilerOptions(style: Style::COMPRESSED);
    $source  = /** @lang text */ <<<'CSS'
    .test { filter: hue-rotate(0deg) saturate(100%); }
    CSS;

    $result = $this->optimizer->optimize($source, $options);

    expect($result)->toBe('.test{filter:hue-rotate(0)saturate(100%)}');
});

it('skips blank lines in input when splitRules is enabled', function () {
    $options = new CompilerOptions(splitRules: true);
    $source  = ".a { width: 1px; }\n\n.b { width: 2px; }";
    $result  = $this->optimizer->optimize($source, $options);

    expect($result)->toBe(".a { width: 1px; }\n\n.b { width: 2px; }");
});

it('skips whitespace-only lines in input when splitRules is enabled', function () {
    $options = new CompilerOptions(splitRules: true);
    $source  = ".a { width: 1px; }\n   \n.b { width: 2px; }";
    $result  = $this->optimizer->optimize($source, $options);

    expect($result)->toBe(".a { width: 1px; }\n\n.b { width: 2px; }");
});

it('does not insert blank line after root-level line without closing braces', function () {
    $options = new CompilerOptions(splitRules: true);
    $source  = ".a { width: 1px; }\nwidth: 2px;\n.b { width: 3px; }";
    $result  = $this->optimizer->optimize($source, $options);

    expect($result)->toBe(".a { width: 1px; }\n\nwidth: 2px;\n.b { width: 3px; }");
});

it('does not insert blank line inside nested blocks', function () {
    $options = new CompilerOptions(splitRules: true);
    $source  = ".a {\n  .b { width: 1px; }\n}\n.c { width: 2px; }";
    $result  = $this->optimizer->optimize($source, $options);

    expect($result)->toBe(".a {\n  .b { width: 1px; }\n}\n\n.c { width: 2px; }");
});

it('preserves css content after charset declaration', function () {
    $source = '.test { content: "панда"; }';
    $result = $this->optimizer->optimize($source, new CompilerOptions());

    expect($result)->toBe("@charset \"UTF-8\";\n.test { content: \"панда\"; }");
});
