<?php

declare(strict_types=1);

use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\FunctionConditionEvaluator;
use Tests\RuntimeFactory;

describe('FunctionConditionEvaluator', function () {
    it('delegates condition evaluation to the condition service', function () {
        $runtime   = RuntimeFactory::createRuntime();
        $evaluator = new FunctionConditionEvaluator($runtime->condition());

        expect($evaluator->evaluate('true', new Environment()))->toBeTrue()
            ->and($evaluator->evaluate('false', new Environment()))->toBeFalse();
    });
});
