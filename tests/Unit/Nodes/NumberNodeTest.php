<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\NumberNode;

describe(NumberNode::class, function () {
    it('formats NaN and infinity values in string representation', function () {
        expect((string) new NumberNode(NAN))->toBe('NaN')
            ->and((string) new NumberNode(INF))->toBe('infinity')
            ->and((string) new NumberNode(-INF))->toBe('-infinity');
    });
});
