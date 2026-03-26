<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('Compiler', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    it('compiles all Sass color spaces without problems', function () {
        $source = <<<'SCSS'
        .all-spaces {
          --rgb:              rgb(12 34 56);
          --hwb:              hwb(210deg 20% 30%);
          --hsl:              hsl(210deg 40% 50%);
          --srgb:             color(srgb 0.1 0.2 0.3);
          --srgb-linear:      color(srgb-linear 0.1 0.2 0.3);
          --display-p3:       color(display-p3 0.1 0.2 0.3);
          --display-p3-linear:color(display-p3-linear 0.1 0.2 0.3);
          --a98-rgb:          color(a98-rgb 0.1 0.2 0.3);
          --prophoto-rgb:     color(prophoto-rgb 0.1 0.2 0.3);
          --rec2020:          color(rec2020 0.1 0.2 0.3);
          --xyz:              color(xyz 0.1 0.2 0.3);
          --xyz-d50:          color(xyz-d50 0.1 0.2 0.3);
          --xyz-d65:          color(xyz-d65 0.1 0.2 0.3);
          --lab:              lab(60% 10 20);
          --lch:              lch(60% 30 200deg);
          --oklab:            oklab(50% 0.1 0.2);
          --oklch:            oklch(50% 0.1 200deg);

          background-color: var(--rgb);
          border: 8px solid var(--hwb);
          color: var(--hsl);
          outline: 4px solid var(--srgb);
          box-shadow: 0 10px 30px var(--srgb-linear);
          text-decoration-color: var(--display-p3);
          accent-color: var(--a98-rgb);
          caret-color: var(--display-p3-linear);
          column-rule-color: var(--prophoto-rgb);
        }
        SCSS;

        $expected = <<<'CSS'
        .all-spaces {
          --rgb: rgb(12 34 56);
          --hwb: hwb(210deg 20% 30%);
          --hsl: hsl(210deg 40% 50%);
          --srgb: color(srgb 0.1 0.2 0.3);
          --srgb-linear: color(srgb-linear 0.1 0.2 0.3);
          --display-p3: color(display-p3 0.1 0.2 0.3);
          --display-p3-linear: color(display-p3-linear 0.1 0.2 0.3);
          --a98-rgb: color(a98-rgb 0.1 0.2 0.3);
          --prophoto-rgb: color(prophoto-rgb 0.1 0.2 0.3);
          --rec2020: color(rec2020 0.1 0.2 0.3);
          --xyz: color(xyz 0.1 0.2 0.3);
          --xyz-d50: color(xyz-d50 0.1 0.2 0.3);
          --xyz-d65: color(xyz-d65 0.1 0.2 0.3);
          --lab: lab(60% 10 20);
          --lch: lch(60% 30 200deg);
          --oklab: oklab(50% 0.1 0.2);
          --oklch: oklch(50% 0.1 200deg);
          background-color: var(--rgb);
          border: 8px solid var(--hwb);
          color: var(--hsl);
          outline: 4px solid var(--srgb);
          box-shadow: 0 10px 30px var(--srgb-linear);
          text-decoration-color: var(--display-p3);
          accent-color: var(--a98-rgb);
          caret-color: var(--display-p3-linear);
          column-rule-color: var(--prophoto-rgb);
        }
        CSS;

        expect($this->compiler->compileString($source))->toEqualCss($expected);
    });
});
