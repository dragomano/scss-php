<?php

declare(strict_types=1);

use Bugo\SCSS\Services\SelectorRuleOptimizer;

describe('SelectorRuleOptimizer', function () {
    beforeEach(function () {
        $this->optimizer = new SelectorRuleOptimizer();
    });

    it('keeps unterminated sibling rule bodies unchanged while merging adjacent matches', function () {
        $input = ".a {\n  color: red;\n  margin: 0;\n  padding: 0;\n";

        expect($this->optimizer->optimizeAdjacentSiblingRuleBlocks($input))
            ->toBe($input);
    });

    it('stops merging adjacent sibling rules when a later matching block is unterminated', function () {
        $input = /** @lang text */ <<<'CSS'
        .a {
          color: red;
        }
        .a {
          margin: 0;
        }
        .a {
          padding: 0;
        CSS;

        $expected = /** @lang text */ <<<'CSS'
        .a {
          color: red;
          margin: 0;
          padding: 0;
        CSS;

        expect($this->optimizer->optimizeAdjacentSiblingRuleBlocks($input))
            ->toBe($expected);
    });

    it('ignores malformed top-level declarations while still removing empty lines in rule bodies', function () {
        $input = /** @lang text */ <<<'CSS'
        .a {
          1foo: red;
          color   red;

          color: blue;
        }
        CSS;

        $expected = /** @lang text */ <<<'CSS'
        .a {
          1foo: red;
          color   red;
          color: blue;
        }
        CSS;

        expect($this->optimizer->optimizeRuleBlock($input))
            ->toBe($expected);
    });

    it('does not treat empty sibling rule bodies as merge candidates', function () {
        $input = /** @lang text */ <<<'CSS'
        .a {
        }
        .a {
          color: red;
        }
        CSS;

        expect($this->optimizer->optimizeAdjacentSiblingRuleBlocks($input))
            ->toBe($input);
    });
});
