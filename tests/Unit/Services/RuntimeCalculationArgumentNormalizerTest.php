<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Services\RuntimeCalculationArgumentNormalizer;
use Tests\RuntimeFactory;

describe('RuntimeCalculationArgumentNormalizer', function () {
    it('delegates calculation argument normalization to the runtime evaluation service', function () {
        $runtime = RuntimeFactory::createRuntime();
        $normalizer = new RuntimeCalculationArgumentNormalizer($runtime);

        $arguments = [
            new NamedArgumentNode('width', new NumberNode(10, 'px')),
            new ArgumentListNode(
                [new NumberNode(20, 'px')],
                'comma',
                false,
                ['height' => new NumberNode(30, 'px')],
            ),
        ];

        /** @var array{NamedArgumentNode, ArgumentListNode} $normalized */
        $normalized = $normalizer->normalize('calc', $arguments);

        expect($normalized)->toHaveCount(2)
            ->and($normalized[0])->toBeInstanceOf(NamedArgumentNode::class)
            ->and($normalized[0]->name)->toBe('width')
            ->and($normalized[0]->value)->toBeInstanceOf(NumberNode::class)
            ->and($normalized[0]->value->value)->toBe(10)
            ->and($normalized[1])->toBeInstanceOf(ArgumentListNode::class)
            ->and($normalized[1]->items)->toHaveCount(1)
            ->and($normalized[1]->items[0])->toBeInstanceOf(NumberNode::class)
            ->and($normalized[1]->items[0]->value)->toBe(20)
            ->and($normalized[1]->keywords)->toHaveCount(1)
            ->and($normalized[1]->keywords['height'])->toBeInstanceOf(NumberNode::class)
            ->and($normalized[1]->keywords['height']->value)->toBe(30);
    });
});
