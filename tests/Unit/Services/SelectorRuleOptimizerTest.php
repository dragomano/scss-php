<?php

declare(strict_types=1);

use Bugo\SCSS\Services\SelectorRuleOptimizer;
use Tests\ReflectionAccessor;

describe('SelectorRuleOptimizer', function () {
    beforeEach(function () {
        $this->optimizer = new SelectorRuleOptimizer();
        $this->accessor  = new ReflectionAccessor($this->optimizer);
    });

    it('keeps unterminated sibling rule bodies unchanged while merging adjacent matches', function () {
        $input = ".a {\n  color: red;\n  margin: 0;\n  padding: 0;\n";

        expect($this->optimizer->optimizeAdjacentSiblingRuleBlocks($input))
            ->toBe($input);
    });

    it('returns null for malformed declaration keys and properties', function () {
        expect($this->accessor->callMethod('extractDeclarationKey', ['color red;']))->toBeNull()
            ->and($this->accessor->callMethod('extractDeclarationProperty', ['']))->toBeNull()
            ->and($this->accessor->callMethod('extractDeclarationProperty', ['1color: red;']))->toBeNull()
            ->and($this->accessor->callMethod('extractDeclarationProperty', ['color red;']))->toBeNull()
            ->and($this->accessor->callMethod('declarationHasVendorValue', ['color red;']))->toBeFalse();
    });

    it('skips spaces before colons and detects vendor-prefixed values', function () {
        expect($this->accessor->callMethod('extractDeclarationProperty', ['color   : red;']))->toBe('color')
            ->and($this->accessor->callMethod('declarationHasVendorValue', ['display: -webkit-box;']))->toBeTrue();
    });

    it('builds declaration keys and ignores regular values without vendor prefixes', function () {
        expect($this->accessor->callMethod('extractDeclarationKey', ['  color : red;']))->toBe('color:red')
            ->and($this->accessor->callMethod('declarationHasVendorValue', ['display: block;']))->toBeFalse();
    });

    it('rejects incomplete simple sibling rule starts and mismatched selectors', function () {
        expect($this->accessor->callMethod('isSimpleSiblingRuleStart', [[
            '.a {',
            '',
        ], 0]))->toBeFalse()
            ->and($this->accessor->callMethod('isMatchingSimpleSiblingRuleStart', [[
                '.a {',
                '  color: red;',
                '}',
            ], 5, '.a {']))->toBeFalse();
    });
});
