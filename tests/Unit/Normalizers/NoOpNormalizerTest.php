<?php

declare(strict_types=1);

use Bugo\SCSS\Normalizers\NoOpNormalizer;
use Bugo\SCSS\Syntax;

describe('NoOpNormalizer', function () {
    beforeEach(function () {
        $this->normalizer = new NoOpNormalizer();
    });

    describe('supports()', function () {
        it('supports SCSS syntax', function () {
            expect($this->normalizer->supports(Syntax::SCSS))->toBeTrue();
        });

        it('does not support SASS syntax', function () {
            expect($this->normalizer->supports(Syntax::SASS))->toBeFalse();
        });
    });

    describe('normalize()', function () {
        it('returns source unchanged', function () {
            $source = 'some scss code';

            expect($this->normalizer->normalize($source))->toBe($source);
        });
    });
})->covers(NoOpNormalizer::class);
