<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\ArrayLogger;

beforeEach(function () {
    $this->logger   = new ArrayLogger();
    $this->compiler = new Compiler(logger: $this->logger);
});

describe('Slash-separated values vs division', function () {
    it('preserves slash-separated literal values in CSS output', function () {
        $source = <<<'SCSS'
        .test {
          font: 15px / 30px Arial;
        }
        SCSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toContain('font: 15px/30px Arial');
    });

    it('evaluates division when left operand is computed', function () {
        $source = <<<'SCSS'
        .test {
          width: (10px + 5px) / 30px;
        }
        SCSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toContain('width: .5');
    });

    it('evaluates division when assigned to variable', function () {
        $source = <<<'SCSS'
        $result: 15px / 30px;
        .test {
          width: $result;
        }
        SCSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toContain('width: .5');
    });

    it('evaluates division when returned from function', function () {
        $source = <<<'SCSS'
        @function get-value() {
          @return 15px / 30px;
        }
        .test {
          width: get-value();
        }
        SCSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toContain('width: .5');
    });

    it('evaluates division in parentheses', function () {
        $source = <<<'SCSS'
        .test {
          width: (15px / 30px);
        }
        SCSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toContain('width: .5');
    });

    it('evaluates division when part of arithmetic expression', function () {
        $source = <<<'SCSS'
        .test {
          width: 15px / 30px + 1;
        }
        SCSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toContain('width: 1.5');
    });

    it('preserves slash in font shorthand with literal values', function () {
        $source = <<<'SCSS'
        .test {
          font: bold 16px/1.5 sans-serif;
        }
        SCSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toContain('font: bold 16px/1.5 sans-serif');
    });

    it('preserves slash in grid-row with literal values', function () {
        $source = <<<'SCSS'
        .test {
          grid-row: 2 / 4;
        }
        SCSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toContain('grid-row: 2 / 4');
    });

    describe('@debug slash handling', function () {
        it('preserves slash in literal 15px/30px', function () {
            $source = '@debug 15px / 30px;';

            $this->compiler->compileString($source);

            expect($this->logger->records[0]['message'])->toContain('15px / 30px');
        });

        it('evaluates division in (10px+5px)/30px expression', function () {
            $source = '@debug (10px + 5px) / 30px;';

            $this->compiler->compileString($source);

            expect($this->logger->records[0]['message'])->toContain('.5');
        });

        it('preserves slash in list.slash()', function () {
            $source = '@use "sass:list"; @debug list.slash(10px + 5px, 30px);';

            $this->compiler->compileString($source);

            expect($this->logger->records[0]['message'])->toContain('15px / 30px');
        });

        it('preserves slash in font shorthand in @debug', function () {
            $source = '@debug (bold 15px/30px sans-serif);';

            $this->compiler->compileString($source);

            expect($this->logger->records[0]['message'])->toContain('bold 15px / 30px sans-serif');
        });

        it('preserves spaced slash in grid-row-like list in @debug', function () {
            $source = '@use "sass:list"; @debug list.slash(1, 3);';

            $this->compiler->compileString($source);

            expect($this->logger->records[0]['message'])->toContain('1 / 3');
        });

        it('evaluates division in 15px/30px + 1', function () {
            $source = '@debug 15px/30px + 1;';

            $this->compiler->compileString($source);

            expect($this->logger->records[0]['message'])->toContain('1.5');
        });
    });
});
