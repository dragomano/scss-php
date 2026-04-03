<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\CallArgumentResolver;
use Tests\ReflectionAccessor;
use Tests\RuntimeFactory;

describe('CallArgumentResolver', function () {
    beforeEach(function () {
        $runtime         = RuntimeFactory::createRuntime();
        $evaluation      = $runtime->evaluation();
        $accessor        = new ReflectionAccessor($evaluation);
        $this->env       = new Environment();
        $this->resolver  = $accessor->getProperty('callArguments');
        $this->cssArg    = $accessor->getProperty('cssArgument');
        $this->userFn    = $accessor->getProperty('userFunction');
        $this->evaluate  = fn($node, $env) => $node;
    });

    it('returns an empty list when content call parsing does not start with a rule node', function () {
        $resolver = new CallArgumentResolver(
            new class () implements ParserInterface {
                public function setTrackSourceLocations(bool $track): void {}

                public function parse(string $source): RootNode
                {
                    return new RootNode([new StringNode('not-a-rule')]);
                }
            },
            $this->cssArg,
            $this->userFn,
            $this->evaluate
        );

        expect($resolver->parseContentCallArguments('(1, 2)'))->toBe([]);
    });

    it('returns an empty list when content call parsing does not produce the expected declaration', function () {
        $resolver = new CallArgumentResolver(
            new class () implements ParserInterface {
                public function setTrackSourceLocations(bool $track): void {}

                public function parse(string $source): RootNode
                {
                    return new RootNode([
                        new RuleNode('.__content__', [new StringNode('unexpected')]),
                    ]);
                }
            },
            $this->cssArg,
            $this->userFn,
            $this->evaluate
        );

        expect($resolver->parseContentCallArguments('(1, 2)'))->toBe([]);
    });

    it('returns no ast nodes when extractAstNodes receives a non-array value', function () {
        expect($this->resolver->extractAstNodes('nope'))->toBe([]);
    });

    it('returns no argument nodes when extractArgumentNodes receives a non-array value', function () {
        expect($this->resolver->extractArgumentNodes('nope'))->toBe([]);
    });

    it('collects named values from expanded spread call arguments', function () {
        [$positional, $named] = $this->resolver->resolveCallArguments([
            new SpreadArgumentNode(new ListNode([
                new NumberNode(1),
                new NamedArgumentNode('color', new StringNode('red')),
            ])),
        ], $this->env);

        expect($positional)->toHaveCount(1)
            ->and($positional[0])->toBeInstanceOf(NumberNode::class)
            ->and($positional[0]->value)->toBe(1)
            ->and($named)->toHaveKey('color')
            ->and($named['color'])->toBeInstanceOf(StringNode::class)
            ->and($named['color']->value)->toBe('red');
    });
});
