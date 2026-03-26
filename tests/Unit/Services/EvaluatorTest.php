<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Parser;
use Bugo\SCSS\Runtime\Environment;
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

    $arguments = $runtime->evaluation()->parseContentCallArguments('(10px, $tone: red)');
    $concatenated = $runtime->evaluation()->evaluateStringConcatenationList(
        new ListNode([new StringNode('foo'), new StringNode('+'), new StringNode('bar')], 'space')
    );

    expect(count($arguments))->toBe(2)
        ->and($concatenated)->toBeInstanceOf(StringNode::class);

    if (! $concatenated instanceof StringNode) {
        throw new RuntimeException('Expected concatenated value to be StringNode.');
    }

    expect($concatenated->value)->toBe('foobar');
});

it('evaluates logical operators in list expressions', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env = new Environment();
    $parser = new Parser();

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
    $env = new Environment();
    $parser = new Parser();

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
