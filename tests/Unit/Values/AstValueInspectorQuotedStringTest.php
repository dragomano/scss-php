<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Values\AstValueInspector;

describe('AstValueInspector quoted strings', function () {
    it('detects quoted string nodes', function () {
        expect(AstValueInspector::isQuotedString(new StringNode('x', true)))->toBeTrue()
            ->and(AstValueInspector::isQuotedString(new StringNode('x')))->toBeFalse()
            ->and(AstValueInspector::isQuotedString(new NumberNode(1)))->toBeFalse()
            ->and(AstValueInspector::isQuotedString(null))->toBeFalse();
    });
});
