<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Exceptions\InvalidSyntaxException;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Syntax;

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

    it('throws for invalid indented sass before compilation', function () {
        $compiler = new Compiler();
        $source   = ".grid\n  color: rgb(255, 0, 0\n";

        expect(fn() => $compiler->compileString($source, Syntax::SASS))
            ->toThrow(
                InvalidSyntaxException::class,
                "Expected closing ')' for '(' opened at line 2.",
            );
    });

    it('throws for unexpected closing parentheses in indented sass before compilation', function () {
        $compiler = new Compiler();
        $source   = ".grid\n  color: red)\n";

        expect(fn() => $compiler->compileString($source, Syntax::SASS))
            ->toThrow(InvalidSyntaxException::class, "Unexpected ')' at line 2.");
    });

    it('throws for unterminated strings in indented sass before compilation', function () {
        $compiler = new Compiler();
        $source   = ".grid\n  content: \"red\n";

        expect(fn() => $compiler->compileString($source, Syntax::SASS))
            ->toThrow(
                InvalidSyntaxException::class,
                'Unterminated string starting at line 2.',
            );
    });

    it('throws for unterminated multiline comments in indented sass before compilation', function () {
        $compiler = new Compiler();
        $source   = ".grid\n  /* comment\n";

        expect(fn() => $compiler->compileString($source, Syntax::SASS))
            ->toThrow(
                InvalidSyntaxException::class,
                'Unterminated comment starting at line 2.',
            );
    });

    it('throws for incomplete directive headers in indented sass before compilation', function () {
        $compiler = new Compiler();
        $source   = "@for\n\ntext\n  color: red\n";

        expect(fn() => $compiler->compileString($source, Syntax::SASS))
            ->toThrow(
                InvalidSyntaxException::class,
                "Incomplete directive header for '@for' at line 1.",
            );
    });

    it('throws for directive header continuations separated by an empty line', function () {
        $compiler = new Compiler();
        $source   = "@for\n\n  \$i from 1 through 3\n    color: #000\n";

        expect(fn() => $compiler->compileString($source, Syntax::SASS))
            ->toThrow(
                InvalidSyntaxException::class,
                "Directive header continuation for '@for' cannot be separated by an empty line after line 1.",
            );
    });

    it('throws for root declarations emitted directly from @for in scss', function () {
        $compiler = new Compiler();
        $source   = <<<'SCSS'
        @for $i from 1 through 3 {
          color: red;
        }
        SCSS;

        expect(fn() => $compiler->compileString($source))
            ->toThrow(SassErrorException::class, '@error: Expected identifier.');
    });
});
