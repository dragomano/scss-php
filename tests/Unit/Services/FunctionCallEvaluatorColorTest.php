<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('Compiler function evaluator color paths', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    it('resolves colors with unresolved alpha and missing channels like dart sass', function () {
        $cases = [
            ['.a { color: rgb(1 2 3 / var(--a)); }', 'color: rgb(1, 2, 3, var(--a))'],
            ['.a { color: rgb(none 1 2); }',        'color: rgb(none 1 2)'],
        ];

        foreach ($cases as $case) {
            expect($this->compiler->compileString($case[0]))->toEqualCss(
                /** @lang text */
                ".a {\n  {$case[1]};\n}",
            );
        }
    });
});
