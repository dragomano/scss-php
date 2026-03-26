<?php

declare(strict_types=1);

use Bugo\SCSS\States\OutputState;

describe('OutputState', function () {
    it('initializes with empty collections', function () {
        $state = new OutputState();

        expect($state->extendMap)->toBe([])
            ->and($state->deferredAtRootStack)->toBe([])
            ->and($state->deferredBubblingStack)->toBe([])
            ->and($state->deferredAtRuleStack)->toBe([])
            ->and($state->selectorContexts)->toBe([])
            ->and($state->pendingExtends)->toBe([]);
    });

    it('reset() clears all collections', function () {
        $state = new OutputState();

        $state->extendMap['selector'] = ['replaced'];
        $state->pendingExtends[] = ['target' => 'a', 'source' => 'b', 'context' => 'c'];
        $state->deferredAtRootStack[] = ['chunk'];
        $state->deferredBubblingStack[] = ['chunk'];
        $state->deferredAtRuleStack[] = [['levels' => 1, 'chunk' => 'x']];
        $state->selectorContexts['sel'] = ['ctx' => true];

        $state->reset();

        expect($state->extendMap)->toBe([])
            ->and($state->deferredAtRootStack)->toBe([])
            ->and($state->deferredBubblingStack)->toBe([])
            ->and($state->deferredAtRuleStack)->toBe([])
            ->and($state->selectorContexts)->toBe([])
            ->and($state->pendingExtends)->toBe([]);
    });

    it('can store and retrieve extend map entries', function () {
        $state = new OutputState();

        $state->extendMap['.foo'] = ['.bar'];

        expect($state->extendMap['.foo'])->toBe(['.bar']);
    });

    it('can accumulate pending extends', function () {
        $state = new OutputState();

        $state->pendingExtends[] = ['target' => '.placeholder', 'source' => '.component', 'context' => ''];
        $state->pendingExtends[] = ['target' => '.other', 'source' => '.thing', 'context' => ''];

        expect($state->pendingExtends)->toHaveCount(2);
    });
});
