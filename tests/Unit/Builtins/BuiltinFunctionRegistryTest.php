<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\FunctionRegistry;
use Bugo\SCSS\Builtins\ModuleInterface;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\NonFiniteNumberException;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;

describe('BuiltinFunctionRegistry', function () {
    beforeEach(function () {
        $this->testModule = new class implements ModuleInterface {
            public function getName(): string
            {
                return 'test';
            }

            public function getFunctions(): array
            {
                return ['echo', 'named'];
            }

            public function getVariables(): array
            {
                return [];
            }

            public function getGlobalAliases(): array
            {
                return ['echo-test' => 'echo'];
            }

            public function call(string $name, array $positional, array $named, ?BuiltinCallContext $context = null): AstNode
            {
                if ($name === 'echo') {
                    return $positional[0] ?? new StringNode('empty');
                }

                if ($name === 'named') {
                    return $named['value'] ?? new StringNode('missing');
                }

                return new StringNode('unknown');
            }
        };
    });

    it('resolves global aliases from default list module', function () {
        $registry = new FunctionRegistry();

        $result = $registry->tryCall('length', [new ListNode([new NumberNode(1), new NumberNode(2)])]);

        /** @var NumberNode $result */
        expect($result)->toBeInstanceOf(NumberNode::class)
            ->and($result->value)->toBe(2);
    });

    it('uses namespaced display name in list module argument errors', function () {
        $registry = new FunctionRegistry();
        $registry->registerUse('sass:list', null);

        expect(fn() => $registry->tryCall('list.nth', [new ListNode([new NumberNode(1)])]))
            ->toThrow(MissingFunctionArgumentsException::class, 'list.nth() expects list and index arguments.');
    });

    it('resolves namespaced calls after @use registration', function () {
        $registry = new FunctionRegistry([$this->testModule]);
        $registry->registerUse('sass:test', null);

        $result = $registry->tryCall('test.echo', [new StringNode('ok')]);

        /** @var StringNode $result */
        expect($result)->toBeInstanceOf(StringNode::class)
            ->and($result->value)->toBe('ok');
    });

    it('supports custom global aliases via registered modules', function () {
        $registry = new FunctionRegistry([$this->testModule]);

        $result = $registry->tryCall('echo-test', [new StringNode('value')]);

        /** @var StringNode $result */
        expect($result)->toBeInstanceOf(StringNode::class)
            ->and($result->value)->toBe('value');
    });

    it('splits named arguments for module calls', function () {
        $registry = new FunctionRegistry([$this->testModule]);
        $registry->registerUse('sass:test', null);

        $result = $registry->tryCall('test.named', [new NamedArgumentNode('value', new StringNode('named-value'))]);

        /** @var StringNode $result */
        expect($result)->toBeInstanceOf(StringNode::class)
            ->and($result->value)->toBe('named-value');
    });

    it('resolves namespaced calls for default sass:map module', function () {
        $registry = new FunctionRegistry();
        $registry->registerUse('sass:map', null);

        $map = new MapNode([
            new MapPair(new StringNode('nested'), new MapNode([
                new MapPair(new StringNode('k'), new NumberNode(10)),
            ])),
        ]);

        $result = $registry->tryCall('map.get', [$map, new StringNode('nested'), new StringNode('k')]);

        /** @var NumberNode $result */
        expect($result)->toBeInstanceOf(NumberNode::class)
            ->and($result->value)->toBe(10);
    });

    it('uses global display name with map module suffix in argument errors', function () {
        $registry = new FunctionRegistry();

        expect(fn() => $registry->tryCall('map-get', [new MapNode([])]))
            ->toThrow(MissingFunctionArgumentsException::class, 'map-get() (map module) expects map and key arguments.');
    });

    it('resolves global aliases for default sass:string module', function () {
        $registry = new FunctionRegistry();

        $result = $registry->tryCall('str-length', [new StringNode('hello')]);

        /** @var NumberNode $result */
        expect($result)->toBeInstanceOf(NumberNode::class)
            ->and($result->value)->toBe(5);
    });

    it('uses global display name with string module suffix in argument errors', function () {
        $registry = new FunctionRegistry();

        expect(fn() => $registry->tryCall('str-insert', [new StringNode('abc')]))
            ->toThrow(MissingFunctionArgumentsException::class, 'str-insert() (string module) expects required argument.');
    });

    it('resolves namespaced calls for default sass:selector module', function () {
        $registry = new FunctionRegistry();
        $registry->registerUse('sass:selector', null);

        $result = $registry->tryCall('selector.append', [new StringNode('.button'), new StringNode('.primary')]);

        /** @var StringNode $result */
        expect($result)->toBeInstanceOf(StringNode::class)
            ->and($result->value)->toBe('.button.primary');
    });

    it('uses global display name with selector module suffix in argument errors', function () {
        $registry = new FunctionRegistry();

        expect(fn() => $registry->tryCall('selector-parse', []))
            ->toThrow(MissingFunctionArgumentsException::class, 'selector-parse() (selector module) expects a string selector argument.');
    });

    it('resolves global aliases for default sass:meta module', function () {
        $registry = new FunctionRegistry();

        $result = $registry->tryCall('type-of', [new MapNode([])]);

        /** @var StringNode $result */
        expect($result)->toBeInstanceOf(StringNode::class)
            ->and($result->value)->toBe('map');
    });

    it('uses global display name with meta module suffix in argument errors', function () {
        $registry = new FunctionRegistry();

        expect(fn() => $registry->tryCall('inspect', []))
            ->toThrow(MissingFunctionArgumentsException::class, 'inspect() (meta module) expects a value argument.');
    });

    it('resolves namespaced calls for default sass:math module', function () {
        $registry = new FunctionRegistry();
        $registry->registerUse('sass:math', null);

        $result = $registry->tryCall('math.pow', [new NumberNode(2), new NumberNode(3)]);

        /** @var NumberNode $result */
        expect($result)->toBeInstanceOf(NumberNode::class)
            ->and($result->value)->toBe(8.0);
    });

    it('uses global display name with math module suffix in argument errors', function () {
        $registry = new FunctionRegistry();

        expect(fn() => $registry->tryCall('percentage', [new NumberNode(10, 'px')]))
            ->toThrow(MissingFunctionArgumentsException::class, 'percentage() (math module) expects a unitless number.');
    });

    it('falls back to css function for global min with incompatible units', function () {
        $registry = new FunctionRegistry();

        $result = $registry->tryCall('min', [new NumberNode(10, 'px'), new NumberNode(2, 'vw')]);

        expect($result)->toBeNull();
    });

    it('keeps namespaced math.min strict for incompatible units', function () {
        $registry = new FunctionRegistry();
        $registry->registerUse('sass:math', null);

        expect(fn() => $registry->tryCall('math.min', [new NumberNode(10, 'px'), new NumberNode(2, 'vw')]))
            ->toThrow(RuntimeException::class);
    });

    it('resolves namespaced calls for default sass:color module', function () {
        $registry = new FunctionRegistry();
        $registry->registerUse('sass:color', null);

        $result = $registry->tryCall('color.mix', [new ColorNode('#000000'), new ColorNode('#ffffff'), new NumberNode(50, '%')]);

        /** @var ColorNode $result */
        expect($result)->toBeInstanceOf(ColorNode::class)
            ->and($result->value)->toBe('#808080');
    });

    it('uses namespaced display name in color module argument errors', function () {
        $registry = new FunctionRegistry();
        $registry->registerUse('sass:color', null);

        expect(fn() => $registry->tryCall('color.mix', [new ColorNode('#000000')]))
            ->toThrow(MissingFunctionArgumentsException::class, "color.mix() expects required argument 'color'.");
    });

    it('resolves global aliases for default sass:color module', function () {
        $registry = new FunctionRegistry();

        $result = $registry->tryCall('lighten', [new ColorNode('#000000'), new NumberNode(20, '%')]);

        /** @var ColorNode $result */
        expect($result)->toBeInstanceOf(ColorNode::class)
            ->and($result->value)->toBe('#333333');
    });

    it('uses global display name with color module suffix in argument errors', function () {
        $registry = new FunctionRegistry();

        expect(fn() => $registry->tryCall('mix', [new ColorNode('#000000')]))
            ->toThrow(MissingFunctionArgumentsException::class, "mix() (color module) expects required argument 'color'.");
    });

    it('uses namespaced display name in unsupported color space errors', function () {
        $registry = new FunctionRegistry();
        $registry->registerUse('sass:color', null);

        expect(fn() => $registry->tryCall('color.to-space', [new ColorNode('#336699'), new StringNode('foo')]))
            ->toThrow(UnsupportedColorSpaceException::class, "Unsupported color space 'foo' in color.to-space().");
    });

    it('uses global display name with color module suffix in unsupported color space errors', function () {
        $registry = new FunctionRegistry();

        expect(fn() => $registry->tryCall('color', [
            new StringNode('foo'),
            new NumberNode(0),
            new NumberNode(0),
            new NumberNode(0),
        ]))->toThrow(UnsupportedColorSpaceException::class, "Unsupported color space 'foo' in color() (color module).");
    });

    it('uses global display name with color module suffix in non-finite number errors', function () {
        $registry = new FunctionRegistry();

        expect(fn() => $registry->tryCall('rgba', [new ColorNode('#ff0000'), new NumberNode(NAN)]))
            ->toThrow(NonFiniteNumberException::class, 'rgba() (color module) received a non-finite number.');
    });

    it('does not resolve deprecated namespaced sass:color legacy functions', function () {
        $registry = new FunctionRegistry();
        $registry->registerUse('sass:color', null);

        $result = $registry->tryCall('color.saturate', [new ColorNode('#336699'), new NumberNode(20, '%')]);

        expect($result)->toBeNull();
    });

    it('falls back to css function for global saturate filter-like call', function () {
        $registry = new FunctionRegistry();

        $result = $registry->tryCall('saturate', [new NumberNode(119, '%')]);

        expect($result)->toBeNull();
    });

    it('returns null for unknown modules or functions', function () {
        $registry = new FunctionRegistry();

        expect($registry->tryCall('unknown.fn', []))->toBeNull()
            ->and($registry->tryCall('unknown-global', []))->toBeNull();
    });

    it('ignores non-sass and unknown sass @use registrations', function () {
        $registry = new FunctionRegistry([$this->testModule]);

        $registry->registerUse('test', 'test');
        $registry->registerUse('sass:unknown', 'unknown');

        expect($registry->isBuiltinAlias('test'))->toBeFalse()
            ->and($registry->resolveModuleAlias('test'))->toBeNull()
            ->and($registry->isBuiltinAlias('unknown'))->toBeFalse()
            ->and($registry->resolveModuleAlias('unknown'))->toBeNull()
            ->and($registry->tryCall('test.echo', [new StringNode('ok')]))->toBeNull()
            ->and($registry->tryCall('unknown.echo', [new StringNode('ok')]))->toBeNull();
    });

    it('returns false for missing functions in a resolved module alias', function () {
        $registry = new FunctionRegistry();
        $registry->registerUse('sass:math', null);

        expect($registry->hasFunction('missing-fn', 'math'))->toBeFalse();
    });

    it('resets registered aliases from @use', function () {
        $registry = new FunctionRegistry([$this->testModule]);
        $registry->registerUse('sass:test', null);

        expect($registry->tryCall('test.echo', [new StringNode('ok')]))->toBeInstanceOf(StringNode::class);

        $registry->reset();

        expect($registry->tryCall('test.echo', [new StringNode('ok')]))->toBeNull();
    });
});
