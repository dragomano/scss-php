<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\Support\ColorFunctionArgumentList;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('ColorFunctionArgumentList', function () {
    beforeEach(function () {
        $this->arguments = new ColorFunctionArgumentList();
    });

    it('expands a single space-separated list into positional arguments', function () {
        $items = [new NumberNode(1), new NumberNode(2)];

        $function = new FunctionNode('rgb', [new ListNode($items, 'space')]);

        expect($this->arguments->expandArguments($function))->toBe($items);
    });

    it('keeps comma-separated arguments untouched', function () {
        $items = [new NumberNode(1), new NumberNode(2)];

        $function = new FunctionNode('rgb', $items);

        expect($this->arguments->expandArguments($function))->toBe($items);
    });

    it('unpacks a slash triple with the first channel and the alpha', function () {
        $triple = new ListNode([new NumberNode(1), new StringNode('/'), new NumberNode(3)], 'space');

        [$channels, $alpha] = $this->arguments->splitChannelsAndAlpha([$triple, new NumberNode(9)]);

        expect($channels)->toHaveCount(1)
            ->and($channels[0])->toBeInstanceOf(NumberNode::class)
            ->and((float) $channels[0]->value)->toBe(1.0)
            ->and($alpha)->toBeInstanceOf(NumberNode::class)
            ->and((float) $alpha->value)->toBe(9.0);
    });

    it('stops unpacking after the slash separator is seen in the triple', function () {
        $triple = new ListNode([new NumberNode(1), new StringNode('/'), new NumberNode(3)], 'space');
        $extra  = new NumberNode(9);

        [$channels, $alpha] = $this->arguments->splitChannelsAndAlpha([$triple, $extra], false);

        expect($channels)->toHaveCount(1)
            ->and($alpha)->toBe($extra);
    });

    it('keeps a three-item group without slash as a single channel', function () {
        $group = new ListNode([new NumberNode(1), new NumberNode(2), new NumberNode(3)], 'space');

        [$channels, $alpha] = $this->arguments->splitChannelsAndAlpha([$group]);

        expect($channels)->toHaveCount(1)
            ->and($channels[0])->toBe($group)
            ->and($alpha)->toBeNull();
    });

    it('ignores bracketed three-item groups with a slash', function () {
        $group = new ListNode([new NumberNode(1), new StringNode('/'), new NumberNode(3)], 'space', true);

        [$channels, $alpha] = $this->arguments->splitChannelsAndAlpha([$group]);

        expect($channels)->toHaveCount(1)
            ->and($channels[0])->toBe($group)
            ->and($alpha)->toBeNull();
    });
});
