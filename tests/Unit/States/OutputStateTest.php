<?php

declare(strict_types=1);

use Bugo\SCSS\Runtime\DeferredAtRuleChunk;
use Bugo\SCSS\States\OutputState;

describe('OutputState', function () {
    it('initializes with empty collections', function () {
        $state = new OutputState();

        expect($state->extends->extendMap)->toBe([])
            ->and($state->deferral->atRootStack)->toBe([])
            ->and($state->deferral->bubblingStack)->toBe([])
            ->and($state->deferral->atRuleStack)->toBe([])
            ->and($state->extends->selectorContexts)->toBe([])
            ->and($state->extends->pendingExtends)->toBe([]);
    });

    it('reset() clears all collections', function () {
        $state = new OutputState();

        $state->extends->extendMap['selector'] = ['replaced'];
        $state->extends->pendingExtends[] = ['target' => 'a', 'source' => 'b', 'context' => 'c'];
        $state->deferral->atRootStack[] = ['chunk'];
        $state->deferral->bubblingStack[] = ['chunk'];
        $state->deferral->atRuleStack[] = [new DeferredAtRuleChunk(1, 'x')];
        $state->extends->selectorContexts['sel'] = ['ctx' => true];

        $state->reset();

        expect($state->extends->extendMap)->toBe([])
            ->and($state->deferral->atRootStack)->toBe([])
            ->and($state->deferral->bubblingStack)->toBe([])
            ->and($state->deferral->atRuleStack)->toBe([])
            ->and($state->extends->selectorContexts)->toBe([])
            ->and($state->extends->pendingExtends)->toBe([]);
    });

    it('can store and retrieve extend map entries', function () {
        $state = new OutputState();

        $state->extends->extendMap['.foo'] = ['.bar'];

        expect($state->extends->extendMap['.foo'])->toBe(['.bar']);
    });

    it('can accumulate pending extends', function () {
        $state = new OutputState();

        $state->extends->pendingExtends[] = ['target' => '.placeholder', 'source' => '.component', 'context' => ''];
        $state->extends->pendingExtends[] = ['target' => '.other', 'source' => '.thing', 'context' => ''];

        expect($state->extends->pendingExtends)->toHaveCount(2);
    });
});
