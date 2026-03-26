<?php

declare(strict_types=1);

use Bugo\SCSS\Normalizers\NormalizerPipeline;
use Bugo\SCSS\Syntax;

describe('NormalizerPipeline', function () {
    beforeEach(function () {
        $this->pipeline = new NormalizerPipeline();
    });

    it('returns SCSS source unchanged', function () {
        $scss   = '.foo { color: red; }';
        $result = $this->pipeline->process($scss, Syntax::SCSS);

        expect($result)->toBe($scss);
    });

    it('normalizes indented SASS syntax by adding braces', function () {
        $sass   = ".foo\n  color: red";
        $result = $this->pipeline->process($sass, Syntax::SASS);

        expect($result)->toContain('{')
            ->and($result)->toContain('}')
            ->and($result)->toContain('color: red;');
    });

    it('returns CSS source unchanged', function () {
        $css    = 'body { margin: 0; }';
        $result = $this->pipeline->process($css, Syntax::CSS);

        // CSS has no dedicated normalizer, so it passes through
        expect($result)->toBe($css);
    });

    it('converts SASS mixin shorthand = to @mixin', function () {
        $sass   = "=my-mixin\n  color: red";
        $result = $this->pipeline->process($sass, Syntax::SASS);

        expect($result)->toContain('@mixin');
    });

    it('converts SASS include shorthand + to @include', function () {
        $sass   = ".foo\n  +my-mixin";
        $result = $this->pipeline->process($sass, Syntax::SASS);

        expect($result)->toContain('@include');
    });

    it('handles empty SASS source', function () {
        $result = $this->pipeline->process('', Syntax::SASS);

        expect($result)->toBe('');
    });

    it('normalizes SASS @import directive with semicolon', function () {
        $sass   = "@import 'vars'";
        $result = $this->pipeline->process($sass, Syntax::SASS);

        expect($result)->toContain("@import 'vars';");
    });
});
