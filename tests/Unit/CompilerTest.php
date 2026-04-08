<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;

describe('Compiler', function () {
    it('compiles basic color to hex when outputHexColors is enabled', function () {
        $compiler = new Compiler(options: new CompilerOptions(outputHexColors: true));
        $css      = $compiler->compileString('.test { color: rgb(255, 0, 0); }');

        expect($css)->toContain('#f00');
    });

    it('compiles basic color without hex conversion by default', function () {
        $compiler = new Compiler();
        $css      = $compiler->compileString('.test { color: rgb(255, 0, 0); }');

        expect($css)->toContain('rgb(255, 0, 0)');
    });
});
