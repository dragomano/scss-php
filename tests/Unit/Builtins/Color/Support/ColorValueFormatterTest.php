<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\Adapters\IrisConverterAdapter;
use Bugo\SCSS\Builtins\Color\Support\ColorValueFormatter;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('ColorValueFormatter', function () {
    beforeEach(function () {
        $this->formatter = new ColorValueFormatter(new IrisConverterAdapter());
    });

    it('formats signed values and degree values', function () {
        expect($this->formatter->formatDegrees(120.0))->toBe('120deg')
            ->and($this->formatter->formatSignedNumber(-0.5))->toBe('-0.5')
            ->and($this->formatter->formatSignedPercentage(12.5))->toBe('12.5%');
    });

    it('describes nested ast values', function () {
        $value = new FunctionNode('color', [
            new ListNode([
                new StringNode('srgb'),
                new NumberNode(0.1),
                new NumberNode(0.2),
                new NumberNode(0.3),
            ], 'space'),
        ]);

        expect($this->formatter->describeValue($value))->toBe('color(srgb 0.1 0.2 0.3)');
    });

    it('returns an empty string for unsupported ast nodes', function () {
        $value = new class () extends AstNode {};

        expect($this->formatter->describeValue($value))->toBe('');
    });
});
