<?php

declare(strict_types=1);

use Tests\RuntimeFactory;

describe('Selector service', function () {
    beforeEach(function () {
        $this->selector = RuntimeFactory::createRuntime()->selector();
    });

    describe('combineMediaQueryPreludes()', function () {
        it('joins outer and inner with "and"', function () {
            expect($this->selector->combineMediaQueryPreludes('screen', '(min-width: 10px)'))
                ->toBe('screen and (min-width: 10px)');
        });

        it('returns inner when outer is empty', function () {
            expect($this->selector->combineMediaQueryPreludes('', '(max-width: 600px)'))
                ->toBe('(max-width: 600px)');
        });

        it('returns outer when inner is empty', function () {
            expect($this->selector->combineMediaQueryPreludes('print', ''))
                ->toBe('print');
        });

        it('joins two feature queries', function () {
            expect($this->selector->combineMediaQueryPreludes('(min-width: 600px)', '(orientation: landscape)'))
                ->toBe('(min-width: 600px) and (orientation: landscape)');
        });
    });

    describe('resolveNestedSelector()', function () {
        it('replaces & with parent selector', function () {
            expect($this->selector->resolveNestedSelector('&:hover', '.button'))
                ->toBe('.button:hover');
        });

        it('returns child unchanged when no & present', function () {
            expect($this->selector->resolveNestedSelector('.icon', '.button'))
                ->toBe('.icon');
        });

        it('handles multiple & references', function () {
            expect($this->selector->resolveNestedSelector('&.active, &:focus', '.btn'))
                ->toContain('.btn.active');
        });
    });

    describe('combineNestedSelectorWithParent()', function () {
        it('appends child after parent with space', function () {
            expect($this->selector->combineNestedSelectorWithParent('.icon', '.button'))
                ->toBe('.button .icon');
        });

        it('prepends parent to element child', function () {
            expect($this->selector->combineNestedSelectorWithParent('span', '.nav'))
                ->toBe('.nav span');
        });
    });

    describe('splitTopLevelSelectorList()', function () {
        it('splits comma-separated selectors', function () {
            expect($this->selector->splitTopLevelSelectorList('.a, .b, .c'))
                ->toBe(['.a', '.b', '.c']);
        });

        it('does not split inside :not()', function () {
            expect($this->selector->splitTopLevelSelectorList('.a, :not(.b, .c), .d'))
                ->toBe(['.a', ':not(.b, .c)', '.d']);
        });

        it('returns single selector as one-element array', function () {
            expect($this->selector->splitTopLevelSelectorList('.only'))
                ->toBe(['.only']);
        });
    });

    describe('optimizeRuleBlock()', function () {
        it('deduplicates repeated declarations', function () {
            $result = $this->selector->optimizeRuleBlock(".a {\n  color: red;\n  color: red;\n}");

            expect($result)->toBe(".a {\n  color: red;\n}");
        });

        it('keeps last value when property repeated with different values', function () {
            $result = $this->selector->optimizeRuleBlock(".a {\n  color: red;\n  color: blue;\n}");

            expect($result)->toContain('color: blue')
                ->and($result)->not->toContain('color: red');
        });

        it('leaves block unchanged when no duplicates', function () {
            $input  = ".a {\n  color: red;\n  margin: 0;\n}";
            $result = $this->selector->optimizeRuleBlock($input);

            expect($result)->toBe($input);
        });
    });
});
