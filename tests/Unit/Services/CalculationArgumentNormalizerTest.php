<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Services\CalculationArgumentNormalizer;
use Tests\RuntimeFactory;

describe('CalculationArgumentNormalizer', function () {
    it('delegates calculation argument normalization to the evaluator', function () {
        $runtime    = RuntimeFactory::createRuntime();
        $evaluator  = $runtime->evaluation();
        $normalizer = new CalculationArgumentNormalizer($evaluator);
        $arguments  = [
            new NamedArgumentNode('width', new NumberNode(10, 'px')),
            new ArgumentListNode(
                [new NumberNode(20, 'px')],
                'comma',
                false,
                ['height' => new NumberNode(30, 'px')],
            ),
        ];

        $expected = $evaluator->normalizeCalculationArguments('calc', $arguments);

        expect($normalizer->normalize('calc', $arguments))->toEqual($expected);
    });
});
