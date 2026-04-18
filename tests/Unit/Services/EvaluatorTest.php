<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\ReturnNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Parser;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\CallableDefinition;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Style;
use Tests\ReflectionAccessor;
use Tests\RuntimeFactory;

it('evaluates variable references and resolves spread arguments', function () {
    $runtime = RuntimeFactory::createRuntime();

    $env = new Environment();
    $env->getCurrentScope()->setVariable('value', new NumberNode(5, 'px'));

    $resolved = $runtime->evaluation()->evaluateValue(new VariableReferenceNode('value'), $env);

    [$positional, $named] = $runtime->evaluation()->resolveCallArguments([
        new SpreadArgumentNode(new ListNode([new StringNode('a'), new StringNode('b')], 'comma')),
        new NamedArgumentNode('width', new NumberNode(10, 'px')),
    ], $env);

    expect($resolved)->toBeInstanceOf(NumberNode::class);

    if (! $resolved instanceof NumberNode) {
        throw new RuntimeException('Expected resolved value to be NumberNode.');
    }

    expect($resolved->value)->toBe(5)
        ->and(count($positional))->toBe(2)
        ->and($named['width'])->toBeInstanceOf(NumberNode::class);
});

it('parses content call arguments and concatenates strings', function () {
    $runtime = RuntimeFactory::createRuntime();

    $arguments    = $runtime->evaluation()->parseContentCallArguments('(10px, $tone: red)');
    $concatenated = $runtime->evaluation()->evaluateStringConcatenationList(
        new ListNode([new StringNode('foo'), new StringNode('+'), new StringNode('bar')], 'space'),
    );

    expect(count($arguments))->toBe(2)
        ->and($concatenated)->toBeInstanceOf(StringNode::class);

    if (! $concatenated instanceof StringNode) {
        throw new RuntimeException('Expected concatenated value to be StringNode.');
    }

    expect($concatenated->value)->toBe('foobar');
});

it('expands call arguments through evaluator delegation', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();

    $arguments = $runtime->evaluation()->expandCallArguments([
        new SpreadArgumentNode(new ListNode([
            new NumberNode(1),
            new NumberNode(2),
        ], 'comma')),
        new NamedArgumentNode('tone', new StringNode('red')),
    ], $env);

    expect($arguments)->toHaveCount(3)
        ->and($arguments[0])->toBeInstanceOf(NumberNode::class)
        ->and($arguments[1])->toBeInstanceOf(NumberNode::class)
        ->and($arguments[2])->toBeInstanceOf(NamedArgumentNode::class);

    /** @var NamedArgumentNode $namedArgument */
    $namedArgument = $arguments[2];

    expect($namedArgument->name)->toBe('tone')
        ->and($namedArgument->value)->toBeInstanceOf(StringNode::class);
});

it('evaluates logical operators in list expressions', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();
    $parser  = new Parser();

    $ast = $parser->parse(<<<'SCSS'
    .test {
      a: true and true;
      b: true and false;
      c: true or false;
      d: false or false;
      e: not true;
      f: not false;
      g: false or 1px;
      h: true and 1px;
    }
    SCSS);

    $rule = $ast->children[0];

    expect($rule)->toBeInstanceOf(RuleNode::class);

    if (! $rule instanceof RuleNode) {
        throw new RuntimeException('Expected parsed node to be RuleNode.');
    }

    foreach ($rule->children as $child) {
        expect($child)->toBeInstanceOf(DeclarationNode::class);
    }

    /** @var array<int, DeclarationNode> $declarations */
    $declarations = $rule->children;

    $a = $runtime->evaluation()->evaluateValue($declarations[0]->value, $env);
    $b = $runtime->evaluation()->evaluateValue($declarations[1]->value, $env);
    $c = $runtime->evaluation()->evaluateValue($declarations[2]->value, $env);
    $d = $runtime->evaluation()->evaluateValue($declarations[3]->value, $env);
    $e = $runtime->evaluation()->evaluateValue($declarations[4]->value, $env);
    $f = $runtime->evaluation()->evaluateValue($declarations[5]->value, $env);
    $g = $runtime->evaluation()->evaluateValue($declarations[6]->value, $env);
    $h = $runtime->evaluation()->evaluateValue($declarations[7]->value, $env);

    expect($runtime->evaluation()->format($a, $env))->toBe('true')
        ->and($runtime->evaluation()->format($b, $env))->toBe('false')
        ->and($runtime->evaluation()->format($c, $env))->toBe('true')
        ->and($runtime->evaluation()->format($d, $env))->toBe('false')
        ->and($runtime->evaluation()->format($e, $env))->toBe('false')
        ->and($runtime->evaluation()->format($f, $env))->toBe('true')
        ->and($runtime->evaluation()->format($g, $env))->toBe('1px')
        ->and($runtime->evaluation()->format($h, $env))->toBe('1px');
});

it('evaluates comparison operators with correct precedence', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();
    $parser  = new Parser();

    $ast = $parser->parse(<<<'SCSS'
    .test {
      a: 1 + 2 * 3 == 7;
      b: 1 + 2 * 3 == 1 + (2 * 3);
      c: 2 * 3 == 6;
      d: 10 - 5 > 3;
      e: 10 / 2 <= 5;
      f: 1 + 1 != 3;
      g: 5 * 2 >= 10;
      h: 8 - 3 < 10;
    }
    SCSS);

    $rule = $ast->children[0];

    expect($rule)->toBeInstanceOf(RuleNode::class);

    if (! $rule instanceof RuleNode) {
        throw new RuntimeException('Expected parsed node to be RuleNode.');
    }

    foreach ($rule->children as $child) {
        expect($child)->toBeInstanceOf(DeclarationNode::class);
    }

    /** @var array<int, DeclarationNode> $declarations */
    $declarations = $rule->children;

    $a = $runtime->evaluation()->evaluateValue($declarations[0]->value, $env);
    $b = $runtime->evaluation()->evaluateValue($declarations[1]->value, $env);
    $c = $runtime->evaluation()->evaluateValue($declarations[2]->value, $env);
    $d = $runtime->evaluation()->evaluateValue($declarations[3]->value, $env);
    $e = $runtime->evaluation()->evaluateValue($declarations[4]->value, $env);
    $f = $runtime->evaluation()->evaluateValue($declarations[5]->value, $env);
    $g = $runtime->evaluation()->evaluateValue($declarations[6]->value, $env);
    $h = $runtime->evaluation()->evaluateValue($declarations[7]->value, $env);

    expect($runtime->evaluation()->format($a, $env))->toBe('true')
        ->and($runtime->evaluation()->format($b, $env))->toBe('true')
        ->and($runtime->evaluation()->format($c, $env))->toBe('true')
        ->and($runtime->evaluation()->format($d, $env))->toBe('true')
        ->and($runtime->evaluation()->format($e, $env))->toBe('true')
        ->and($runtime->evaluation()->format($f, $env))->toBe('true')
        ->and($runtime->evaluation()->format($g, $env))->toBe('true')
        ->and($runtime->evaluation()->format($h, $env))->toBe('true');
});

it('preserves literal slash sublists while still evaluating changed list items', function () {
    $runtime = RuntimeFactory::createRuntime();

    $env = new Environment();
    $env->getCurrentScope()->setVariable('value', new StringNode('resolved'));

    $slashList = new ListNode([
        new NumberNode(1.0, null, true),
        new StringNode('/'),
        new NumberNode(2.0, null, true),
    ], 'space');

    $result = $runtime->evaluation()->evaluateValue(new ListNode([
        new VariableReferenceNode('value'),
        $slashList,
    ], 'comma'), $env);

    expect($result)->toBeInstanceOf(ListNode::class);

    if (! $result instanceof ListNode) {
        throw new RuntimeException('Expected result to be ListNode.');
    }

    expect($result->items[0])->toBeInstanceOf(StringNode::class)
        ->and($result->items[1])->toBe($slashList);

    /** @var StringNode $firstItem */
    $firstItem = $result->items[0];

    expect($firstItem->value)->toBe('resolved');
});

it('returns comparison results directly from list evaluation', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();

    $result = $runtime->evaluation()->evaluateValue(new ListNode([
        new NumberNode(1.0),
        new StringNode('<'),
        new NumberNode(2.0),
    ], 'space'), $env);

    expect($result)->toBeInstanceOf(BooleanNode::class);

    if (! $result instanceof BooleanNode) {
        throw new RuntimeException('Expected result to be BooleanNode.');
    }

    expect($runtime->evaluation()->format($result, $env))->toBe('true');
});

it('returns null for malformed comparison lists', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();

    expect($runtime->evaluation()->evaluateComparisonList(new ListNode([
        new StringNode('<'),
        new NumberNode(2.0),
    ], 'space'), $env))->toBeNull()
        ->and($runtime->evaluation()->evaluateComparisonList(new ListNode([
            new NumberNode(1.0),
            new NumberNode(2.0),
            new StringNode('<'),
        ], 'space'), $env))->toBeNull()
        ->and($runtime->evaluation()->evaluateComparisonList(new ListNode([
            new NumberNode(1.0),
            new StringNode('between'),
            new NumberNode(2.0),
        ], 'space'), $env))->toBeNull()
        ->and($runtime->evaluation()->evaluateComparisonList(new ListNode([
            new NumberNode(1.0),
            new StringNode('<'),
            new NumberNode(2.0),
        ], 'comma'), $env))->toBeNull();
});

it('evaluates argument list items and keywords lazily', function () {
    $runtime = RuntimeFactory::createRuntime();

    $env = new Environment();
    $env->getCurrentScope()->setVariable('first', new StringNode('resolved-item'));
    $env->getCurrentScope()->setVariable('named', new StringNode('resolved-keyword'));

    $result = $runtime->evaluation()->evaluateValue(new ArgumentListNode(
        [new VariableReferenceNode('first'), new StringNode('tail')],
        'comma',
        false,
        [
            'primary' => new VariableReferenceNode('named'),
            'secondary' => new StringNode('kept'),
        ],
    ), $env);

    expect($result)->toBeInstanceOf(ArgumentListNode::class);

    if (! $result instanceof ArgumentListNode) {
        throw new RuntimeException('Expected result to be ArgumentListNode.');
    }

    expect($result->items[0])->toBeInstanceOf(StringNode::class)
        ->and($result->items[1])->toBeInstanceOf(StringNode::class)
        ->and($result->keywords['primary'])->toBeInstanceOf(StringNode::class)
        ->and($result->keywords['secondary'])->toBeInstanceOf(StringNode::class)
        ->and(count($result->items))->toBe(2);

    /** @var StringNode $firstItem */
    $firstItem = $result->items[0];
    /** @var StringNode $secondItem */
    $secondItem = $result->items[1];
    /** @var StringNode $primaryKeyword */
    $primaryKeyword = $result->keywords['primary'];
    /** @var StringNode $secondaryKeyword */
    $secondaryKeyword = $result->keywords['secondary'];

    expect($firstItem->value)->toBe('resolved-item')
        ->and($secondItem->value)->toBe('tail')
        ->and($primaryKeyword->value)->toBe('resolved-keyword')
        ->and($secondaryKeyword->value)->toBe('kept');
});

it('evaluates map keys and values lazily', function () {
    $runtime = RuntimeFactory::createRuntime();

    $env = new Environment();
    $env->getCurrentScope()->setVariable('key', new StringNode('resolved-key'));
    $env->getCurrentScope()->setVariable('value', new StringNode('resolved-value'));

    $unchangedKey   = new StringNode('kept-key');
    $unchangedValue = new StringNode('kept-value');

    $map = new MapNode([
        new MapPair(new VariableReferenceNode('key'), new VariableReferenceNode('value')),
        new MapPair($unchangedKey, $unchangedValue),
    ]);

    $result = $runtime->evaluation()->evaluateValue($map, $env);

    expect($result)->toBeInstanceOf(MapNode::class)
        ->and($result)->not->toBe($map);

    if (! $result instanceof MapNode) {
        throw new RuntimeException('Expected result to be MapNode.');
    }

    expect($result->pairs[0]->key)->toBeInstanceOf(StringNode::class)
        ->and($result->pairs[0]->value)->toBeInstanceOf(StringNode::class)
        ->and($result->pairs[1]->key)->toBe($unchangedKey)
        ->and($result->pairs[1]->value)->toBe($unchangedValue);

    /** @var StringNode $firstKey */
    $firstKey = $result->pairs[0]->key;
    /** @var StringNode $firstValue */
    $firstValue = $result->pairs[0]->value;

    expect($firstKey->value)->toBe('resolved-key')
        ->and($firstValue->value)->toBe('resolved-value');
});

it('keeps named arguments unchanged when their value does not change', function () {
    $runtime  = RuntimeFactory::createRuntime();
    $env      = new Environment();
    $argument = new NamedArgumentNode('tone', new StringNode('red'));

    $result = $runtime->evaluation()->evaluateValue($argument, $env);

    expect($result)->toBe($argument);
});

it('rebuilds named arguments when their value changes', function () {
    $runtime = RuntimeFactory::createRuntime();

    $env = new Environment();
    $env->getCurrentScope()->setVariable('tone', new StringNode('blue'));

    $result = $runtime->evaluation()->evaluateValue(
        new NamedArgumentNode('tone', new VariableReferenceNode('tone')),
        $env,
    );

    expect($result)->toBeInstanceOf(NamedArgumentNode::class);

    if (! $result instanceof NamedArgumentNode) {
        throw new RuntimeException('Expected result to be NamedArgumentNode.');
    }

    expect($result->name)->toBe('tone')
        ->and($result->value)->toBeInstanceOf(StringNode::class);

    /** @var StringNode $namedValue */
    $namedValue = $result->value;

    expect($namedValue->value)->toBe('blue');
});

it('throws when user function recursion exceeds the evaluator limit', function () {
    $runtime = RuntimeFactory::createRuntime();

    $env = new Environment();
    $env->getCurrentScope()->setFunction('loop', new CallableDefinition([], [
        new ReturnNode(new StringNode('done')),
    ], $env->getCurrentScope(), 1));

    $ctx = (new ReflectionAccessor($runtime))->getProperty('ctx');
    $ctx->moduleState->callDepth = 100;

    expect(fn() => $runtime->evaluation()->evaluateValue(new FunctionNode('loop'), $env))
        ->toThrow(MaxIterationsExceededException::class);
});

it('returns simplified max() calls when builtins defer to css', function () {
    $runtime = RuntimeFactory::createRuntime();

    $env = new Environment();
    $ctx = (new ReflectionAccessor($runtime))->getProperty('ctx');

    $functionRegistry = new ReflectionAccessor($ctx->functionRegistry);
    $functionRegistry->setProperty('globalAliases', []);
    $functionRegistry->setProperty('moduleFactories', []);

    $result = $runtime->evaluation()->evaluateValue(new FunctionNode('max', [
        new NumberNode(1),
        new NumberNode(2),
    ]), $env);

    expect($result)->toBeInstanceOf(NumberNode::class);

    if (! $result instanceof NumberNode) {
        throw new RuntimeException('Expected result to be NumberNode.');
    }

    expect($result->value)->toBe(2.0);
});

it('compresses fallback hsl() functions to hex colors in compressed mode', function () {
    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(style: Style::COMPRESSED),
    );

    $env = new Environment();
    $ctx = (new ReflectionAccessor($runtime))->getProperty('ctx');

    $functionRegistry = new ReflectionAccessor($ctx->functionRegistry);
    $functionRegistry->setProperty('globalAliases', []);
    $functionRegistry->setProperty('moduleFactories', []);

    $result = $runtime->evaluation()->evaluateValue(new FunctionNode('hsl', [
        new NumberNode(0, 'deg'),
        new NumberNode(100, '%'),
        new NumberNode(50, '%'),
    ]), $env);

    expect($result)->toBeInstanceOf(ColorNode::class);

    if (! $result instanceof ColorNode) {
        throw new RuntimeException('Expected result to be ColorNode.');
    }

    expect($result->value)->toBe('#f00');
});

it('returns unchanged nodes for unsupported evaluateValue inputs', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();
    $node    = new ColorNode('#abc');

    expect($runtime->evaluation()->evaluateValue($node, $env))->toBe($node);
});

it('returns the original function node when css fallback reparsing does not apply', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();
    $node    = new class extends AstNode {};

    expect($runtime->evaluation()->evaluateValue($node, $env))->toBe($node);
});

it('reparses formatted declaration expressions conservatively', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();

    $unchanged = $runtime->evaluation()->tryEvaluateFormattedDeclarationExpression(
        'width',
        new NumberNode(3, 'px'),
        $env,
    );

    expect($unchanged)->toBeNull();
});

it('returns null when reparsing formatted declaration expressions fails or does not yield a declaration', function () {
    $badParser = new class implements ParserInterface {
        public function setTrackSourceLocations(bool $track): void {}

        public function parse(string $source): RootNode
        {
            throw new SassErrorException('bad parse');
        }
    };

    $runtimeWithBadParser = RuntimeFactory::createRuntime(parser: $badParser);

    $nullFromException = $runtimeWithBadParser->evaluation()->tryEvaluateFormattedDeclarationExpression(
        'width',
        new StringNode('calc(1px / )'),
        new Environment(),
    );

    $nonDeclarationParser = new class implements ParserInterface {
        public function setTrackSourceLocations(bool $track): void {}

        public function parse(string $source): RootNode
        {
            return new RootNode([
                new RuleNode('.__tmp__', [new StringNode('not-a-declaration')]),
            ]);
        }
    };

    $runtimeWithoutDeclaration = RuntimeFactory::createRuntime(parser: $nonDeclarationParser);

    $nullFromMissingDeclaration = $runtimeWithoutDeclaration->evaluation()->tryEvaluateFormattedDeclarationExpression(
        'width',
        new StringNode('calc(1px / 2px)'),
        new Environment(),
    );

    expect($nullFromException)->toBeNull()
        ->and($nullFromMissingDeclaration)->toBeNull();
});

it('returns null when reparsed formatted declarations do not start with a rule node', function () {
    $parser = new class implements ParserInterface {
        public function setTrackSourceLocations(bool $track): void {}

        public function parse(string $source): RootNode
        {
            return new RootNode([new StringNode('not-a-rule')]);
        }
    };

    $runtime = RuntimeFactory::createRuntime(parser: $parser);

    expect($runtime->evaluation()->tryEvaluateFormattedDeclarationExpression(
        'width',
        new StringNode('(10px / 2)'),
        new Environment(),
    ))->toBeNull();
});

it('formats variable references parent selectors mixin refs and named arguments', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();
    $scope   = $env->getCurrentScope();

    $scope->setVariable('value', new StringNode('resolved'));
    $scope->setVariable('__parent_selector', new StringNode('.parent'));

    expect($runtime->evaluation()->format(new VariableReferenceNode('value'), $env))->toBe('resolved')
        ->and($runtime->evaluation()->format(new StringNode('&'), $env))->toBe('.parent')
        ->and($runtime->evaluation()->format(new MixinRefNode('theme-mixin'), $env))->toBe('theme-mixin')
        ->and($runtime->evaluation()->format(
            new NamedArgumentNode('tone', new StringNode('red')),
            $env,
        ))->toBe('$tone: red');
});

it('falls back to plain string formatting when no parent selector is set', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();

    expect($runtime->evaluation()->format(new StringNode('&'), $env))->toBe('&');
});

it('throws when resolving malformed or missing namespaced variables', function () {
    $runtime  = RuntimeFactory::createRuntime();
    $env      = new Environment();
    $accessor = new ReflectionAccessor($runtime->evaluation());

    expect(fn() => $accessor->callMethod('resolveVariable', ['theme.color', $env]))
        ->toThrow(ModuleResolutionException::class)
        ->and(fn() => $accessor->callMethod('resolveVariable', ['theme.', $env]))
        ->toThrow(
            LogicException::class,
            'Qualified variable reference must include a member name.',
        );
});

it('rebuilds compact slash declaration values when an item changes', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();
    $env->getCurrentScope()->setVariable('ratio', new NumberNode(16, 'px'));

    $value = new ListNode([
        new VariableReferenceNode('ratio'),
        new StringNode('/'),
        new NumberNode(9, 'px'),
    ], 'space');

    $result = $runtime->evaluation()->evaluateDeclarationValue($value, 'font', $env);

    expect($result)->toBeInstanceOf(ListNode::class)
        ->and($result)->not->toBe($value);

    if (! $result instanceof ListNode) {
        throw new RuntimeException('Expected result to be ListNode.');
    }

    expect($result->items[0])->toBeInstanceOf(NumberNode::class)
        ->and($result->items[1])->toBeInstanceOf(StringNode::class);

    /** @var NumberNode $firstItem */
    $firstItem = $result->items[0];
    /** @var StringNode $secondItem */
    $secondItem = $result->items[1];

    expect($firstItem->value)->toBe(16)
        ->and($secondItem->value)->toBe('/');
});

it('applies global and module variable declarations', function () {
    $runtime     = RuntimeFactory::createRuntime();
    $env         = new Environment();
    $moduleScope = new Scope();

    $env->getCurrentScope()->addModule('theme', $moduleScope);

    $globalApplied = $runtime->evaluation()->applyVariableDeclaration(
        new VariableDeclarationNode('spacing', new NumberNode(8, 'px'), true),
        $env,
    );
    $moduleApplied = $runtime->evaluation()->applyVariableDeclaration(
        new ModuleVarDeclarationNode('theme', 'accent', new StringNode('blue')),
        $env,
    );

    expect($globalApplied)->toBeTrue()
        ->and($moduleApplied)->toBeTrue()
        ->and($env->getGlobalScope()->getVariable('spacing'))->toBeInstanceOf(NumberNode::class)
        ->and($moduleScope->getVariable('accent'))->toBeInstanceOf(StringNode::class);

    /** @var NumberNode $spacing */
    $spacing = $env->getGlobalScope()->getVariable('spacing');
    /** @var StringNode $accent */
    $accent = $moduleScope->getVariable('accent');

    expect($spacing->value)->toBe(8)
        ->and($accent->value)->toBe('blue');
});

it('wraps scalar each() iterables in a one-item list', function () {
    $runtime = RuntimeFactory::createRuntime();
    $value   = new StringNode('single');

    expect($runtime->evaluation()->eachIterableItems($value))->toBe([$value]);
});

it('re-evaluates formatted slash expressions into strict arithmetic results', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();

    $result = $runtime->evaluation()->tryEvaluateFormattedDeclarationExpression(
        'width',
        new StringNode('(10px / 2)'),
        $env,
    );

    expect($result)->toBeInstanceOf(NumberNode::class);

    if (! $result instanceof NumberNode) {
        throw new RuntimeException('Expected result to be NumberNode.');
    }

    expect($result->value)->toBe(5.0)
        ->and($result->unit)->toBe('px');
});

it('formats unsupported ast nodes as an empty string', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env     = new Environment();
    $node    = new class extends AstNode {};

    expect($runtime->evaluation()->format($node, $env))->toBe('');
});

it('returns null when reparsed formatted declarations do not yield a declaration node', function () {
    $parser = new class implements ParserInterface {
        public function setTrackSourceLocations(bool $track): void {}

        public function parse(string $source): RootNode
        {
            return new RootNode([
                new RuleNode('.__tmp__', [new StringNode('not-a-declaration')]),
            ]);
        }
    };

    $runtime = RuntimeFactory::createRuntime(parser: $parser);

    expect($runtime->evaluation()->tryEvaluateFormattedDeclarationExpression(
        'width',
        new StringNode('(10px / 2)'),
        new Environment(),
    ))->toBeNull();
});
