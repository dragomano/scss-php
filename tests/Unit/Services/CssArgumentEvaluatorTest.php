<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\ClosureAstValueEvaluator;
use Bugo\SCSS\Services\CssArgumentEvaluator;
use Tests\ReflectionAccessor;

describe('CssArgumentEvaluator', function () {
    beforeEach(function () {
        $this->evaluator = new CssArgumentEvaluator(
            new ClosureAstValueEvaluator(
                function (AstNode $node, Environment $env): AstNode {
                    if ($node instanceof VariableReferenceNode) {
                        return $env->getCurrentScope()->getVariable($node->name);
                    }

                    return $node;
                },
            ),
            static fn(string $name, array $arguments): array => $arguments,
        );
        $this->accessor = new ReflectionAccessor($this->evaluator);
    });

    it('expands css spread arguments and evaluates fallback positional and named values', function () {
        $env = new Environment();
        $env->getCurrentScope()->setVariable('left', new StringNode('resolved-left'));
        $env->getCurrentScope()->setVariable('tone', new StringNode('resolved-tone'));

        $result = $this->evaluator->expandCssCallArguments([
            new SpreadArgumentNode(new ArgumentListNode(
                [
                    new ListNode([
                        new VariableReferenceNode('left'),
                        new StringNode('and'),
                        new StringNode('literal'),
                    ], 'space'),
                ],
                'comma',
                false,
                [
                    'color' => new ListNode([
                        new VariableReferenceNode('tone'),
                        new StringNode('or'),
                        new StringNode('fallback'),
                    ], 'space'),
                ],
            )),
        ], $env);

        expect($result)->toHaveCount(2)
            ->and($result[0])->toBeInstanceOf(ListNode::class)
            ->and($result[1])->toBeInstanceOf(NamedArgumentNode::class);

        /** @var ListNode $spreadList */
        $spreadList = $result[0];
        expect($spreadList->items[0])->toBeInstanceOf(StringNode::class)
            ->and($spreadList->items[1])->toBeInstanceOf(StringNode::class);

        /** @var StringNode $firstSpreadItem */
        $firstSpreadItem = $spreadList->items[0];

        /** @var StringNode $secondSpreadItem */
        $secondSpreadItem = $spreadList->items[1];

        expect($firstSpreadItem->value)->toBe('resolved-left')
            ->and($secondSpreadItem->value)->toBe('and');

        /** @var NamedArgumentNode $named */
        $named = $result[1];
        expect($named->name)->toBe('color')
            ->and($named->value)->toBeInstanceOf(ListNode::class);

        /** @var ListNode $namedValue */
        $namedValue = $named->value;
        expect($namedValue->items[0])->toBeInstanceOf(StringNode::class)
            ->and($namedValue->items[1])->toBeInstanceOf(StringNode::class);

        /** @var StringNode $firstNamedValueItem */
        $firstNamedValueItem = $namedValue->items[0];

        /** @var StringNode $secondNamedValueItem */
        $secondNamedValueItem = $namedValue->items[1];

        expect($firstNamedValueItem->value)->toBe('resolved-tone')
            ->and($secondNamedValueItem->value)->toBe('or');
    });

    it('expands named css arguments through fallback evaluation', function () {
        $env = new Environment();
        $env->getCurrentScope()->setVariable('value', new StringNode('resolved-value'));

        $result = $this->evaluator->expandCssCallArguments([
            new NamedArgumentNode('size', new ListNode([
                new VariableReferenceNode('value'),
                new StringNode('and'),
                new StringNode('kept'),
            ], 'space')),
        ], $env);

        expect($result)->toHaveCount(1)
            ->and($result[0])->toBeInstanceOf(NamedArgumentNode::class);

        /** @var NamedArgumentNode $named */
        $named = $result[0];
        expect($named->name)->toBe('size')
            ->and($named->value)->toBeInstanceOf(ListNode::class);

        /** @var ListNode $namedValue */
        $namedValue = $named->value;
        expect($namedValue->items[0])->toBeInstanceOf(StringNode::class);

        /** @var StringNode $firstNamedValueItem */
        $firstNamedValueItem = $namedValue->items[0];

        expect($firstNamedValueItem->value)->toBe('resolved-value');
    });

    it('expands argument lists into positional items followed by named arguments', function () {
        $expanded = $this->evaluator->expandSpreadValue(new ArgumentListNode(
            [new StringNode('alpha'), new StringNode('beta')],
            'comma',
            false,
            ['tone' => new StringNode('red')],
        ));

        expect($expanded)->toHaveCount(3)
            ->and($expanded[0])->toBeInstanceOf(StringNode::class)
            ->and($expanded[0]->value)->toBe('alpha')
            ->and($expanded[1])->toBeInstanceOf(StringNode::class)
            ->and($expanded[1]->value)->toBe('beta')
            ->and($expanded[2])->toBeInstanceOf(NamedArgumentNode::class);

        /** @var NamedArgumentNode $named */
        $named = $expanded[2];
        expect($named->name)->toBe('tone')
            ->and($named->value)->toBeInstanceOf(StringNode::class);

        /** @var StringNode $namedValue */
        $namedValue = $named->value;

        expect($namedValue->value)->toBe('red');
    });

    it('expands spread maps into named arguments and rejects non-string keys', function () {
        $expanded = $this->evaluator->expandSpreadValue(new MapNode([
            new MapPair(new StringNode('width'), new NumberNode(10)),
        ]));

        expect($expanded)->toHaveCount(1)
            ->and($expanded[0])->toBeInstanceOf(NamedArgumentNode::class);

        /** @var NamedArgumentNode $named */
        $named = $expanded[0];
        expect($named->name)->toBe('width')
            ->and($named->value)->toBeInstanceOf(NumberNode::class);

        /** @var NumberNode $namedValue */
        $namedValue = $named->value;

        expect($namedValue->value)->toBe(10)
            ->and(fn() => $this->evaluator->expandSpreadValue(new MapNode([
                new MapPair(new NumberNode(1), new StringNode('bad')),
            ])))->toThrow(SassErrorException::class);
    });

    it('returns non-list spread values unchanged as a single argument', function () {
        $spread = new StringNode('single');

        expect($this->evaluator->expandSpreadValue($spread))
            ->toBe([$spread]);
    });

    it('compresses named colors recursively for output', function () {
        $value = new FunctionNode('rgb', [
            new ListNode([
                new StringNode('red'),
                new ArgumentListNode(
                    [new StringNode('blue', true)],
                    'comma',
                    false,
                    ['tone' => new StringNode('green')],
                ),
            ], 'comma'),
            new MapNode([
                new MapPair(
                    new StringNode('primary'),
                    new NamedArgumentNode('accent', new StringNode('navy')),
                ),
            ]),
        ]);

        $compressed = $this->evaluator->compressNamedColorsForOutput($value);

        expect($compressed)->toBeInstanceOf(FunctionNode::class);

        /** @var FunctionNode $compressed */
        $arguments = $compressed->arguments;

        expect($arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($arguments[1])->toBeInstanceOf(MapNode::class);

        /** @var ListNode $list */
        $list = $arguments[0];
        expect($list->items[0])->toBeInstanceOf(ColorNode::class)
            ->and($list->items[1])->toBeInstanceOf(ArgumentListNode::class);

        /** @var ColorNode $firstListItem */
        $firstListItem = $list->items[0];
        expect($firstListItem->value)->toBe('#f00');

        /** @var ArgumentListNode $argumentList */
        $argumentList = $list->items[1];
        expect($argumentList->items[0])->toBeInstanceOf(StringNode::class)
            ->and($argumentList->keywords['tone'])->toBeInstanceOf(ColorNode::class);

        /** @var StringNode $quotedItem */
        $quotedItem = $argumentList->items[0];
        expect($quotedItem->value)->toBe('blue');

        /** @var ColorNode $keywordTone */
        $keywordTone = $argumentList->keywords['tone'];
        expect($keywordTone->value)->toBe('#008000');

        /** @var MapNode $map */
        $map = $arguments[1];
        expect($map->pairs[0]->value)->toBeInstanceOf(NamedArgumentNode::class);

        /** @var NamedArgumentNode $named */
        $named = $map->pairs[0]->value;
        expect($named->value)->toBeInstanceOf(ColorNode::class);

        /** @var ColorNode $namedValue */
        $namedValue = $named->value;
        expect($namedValue->value)->toBe('#000080');
    });

    it('evaluates fallback css arguments across argument lists maps functions and variable references', function () {
        $env = new Environment();
        $env->getCurrentScope()->setVariable('fallback', new StringNode('resolved'));

        $argumentList = new ArgumentListNode(
            [new ListNode([
                new VariableReferenceNode('fallback'),
                new StringNode('and'),
                new StringNode('literal'),
            ], 'space')],
            'comma',
            true,
            ['tone' => new ListNode([
                new VariableReferenceNode('fallback'),
                new StringNode('or'),
                new StringNode('literal'),
            ], 'space')],
        );
        $map = new MapNode([
            new MapPair(
                new ListNode([
                    new VariableReferenceNode('fallback'),
                    new StringNode('and'),
                    new StringNode('literal'),
                ], 'space'),
                new ListNode([
                    new VariableReferenceNode('fallback'),
                    new StringNode('or'),
                    new StringNode('literal'),
                ], 'space'),
            ),
        ]);
        $named = new NamedArgumentNode('accent', new ListNode([
            new VariableReferenceNode('fallback'),
            new StringNode('and'),
            new StringNode('literal'),
        ], 'space'));
        $function = new FunctionNode('calc', [
            new ListNode([
                new VariableReferenceNode('fallback'),
                new StringNode('and'),
                new StringNode('literal'),
            ], 'space'),
        ]);
        $reference = new VariableReferenceNode('fallback');
        $plain = new NumberNode(3);

        $evaluatedArgumentList = $this->accessor->callMethod('evaluateFallbackCssArgument', [$argumentList, $env]);
        $evaluatedMap = $this->accessor->callMethod('evaluateFallbackCssArgument', [$map, $env]);
        $evaluatedNamed = $this->accessor->callMethod('evaluateFallbackCssArgument', [$named, $env]);
        $evaluatedFunction = $this->accessor->callMethod('evaluateFallbackCssArgument', [$function, $env]);
        $evaluatedReference = $this->accessor->callMethod('evaluateFallbackCssArgument', [$reference, $env]);
        $evaluatedPlain = $this->accessor->callMethod('evaluateFallbackCssArgument', [$plain, $env]);

        expect($evaluatedArgumentList)->toBeInstanceOf(ArgumentListNode::class)
            ->and($evaluatedArgumentList->items[0])->toBeInstanceOf(ListNode::class)
            ->and($evaluatedArgumentList->items[0]->items[0])->toBeInstanceOf(StringNode::class)
            ->and($evaluatedArgumentList->items[0]->items[0]->value)->toBe('resolved')
            ->and($evaluatedArgumentList->keywords['tone'])->toBeInstanceOf(ListNode::class)
            ->and($evaluatedArgumentList->keywords['tone']->items[0])->toBeInstanceOf(StringNode::class)
            ->and($evaluatedArgumentList->keywords['tone']->items[0]->value)->toBe('resolved')
            ->and($evaluatedMap)->toBeInstanceOf(MapNode::class)
            ->and($evaluatedMap->pairs[0]->key)->toBeInstanceOf(ListNode::class)
            ->and($evaluatedMap->pairs[0]->key->items[0])->toBeInstanceOf(StringNode::class)
            ->and($evaluatedMap->pairs[0]->key->items[0]->value)->toBe('resolved')
            ->and($evaluatedMap->pairs[0]->value)->toBeInstanceOf(ListNode::class)
            ->and($evaluatedMap->pairs[0]->value->items[0])->toBeInstanceOf(StringNode::class)
            ->and($evaluatedMap->pairs[0]->value->items[0]->value)->toBe('resolved')
            ->and($evaluatedNamed)->toBeInstanceOf(NamedArgumentNode::class)
            ->and($evaluatedNamed->value)->toBeInstanceOf(ListNode::class)
            ->and($evaluatedNamed->value->items[0])->toBeInstanceOf(StringNode::class)
            ->and($evaluatedNamed->value->items[0]->value)->toBe('resolved')
            ->and($evaluatedFunction)->toBeInstanceOf(FunctionNode::class)
            ->and($evaluatedFunction->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($evaluatedFunction->arguments[0]->items[0])->toBeInstanceOf(StringNode::class)
            ->and($evaluatedFunction->arguments[0]->items[0]->value)->toBe('resolved')
            ->and($evaluatedReference)->toBeInstanceOf(StringNode::class)
            ->and($evaluatedReference->value)->toBe('resolved')
            ->and($evaluatedPlain)->toBe($plain);
    });

    it('keeps fallback css arguments unchanged when nested values do not change', function () {
        $env = new Environment();
        $argumentList = new ArgumentListNode(
            [new StringNode('alpha')],
            'comma',
            false,
            ['tone' => new StringNode('beta')],
        );
        $map = new MapNode([
            new MapPair(new StringNode('alpha'), new StringNode('beta')),
        ]);
        $named = new NamedArgumentNode('accent', new ListNode([new StringNode('or')], 'space'));

        expect($this->accessor->callMethod('evaluateFallbackCssArgument', [$argumentList, $env]))
            ->toBe($argumentList)
            ->and($this->accessor->callMethod('evaluateFallbackCssArgument', [$map, $env]))
            ->toBe($map)
            ->and($this->accessor->callMethod('evaluateFallbackCssArgument', [$named, $env]))
            ->toBe($named);
    });

    it('detects css-preserving arguments recursively across nested node types', function () {
        $list = new ListNode([
            new StringNode('literal'),
            new ListNode([
                new StringNode('left'),
                new StringNode('and'),
                new StringNode('right'),
            ], 'space'),
        ], 'comma');
        $function = new FunctionNode('calc', [
            new ListNode([new StringNode('or')], 'space'),
        ]);
        $argumentList = new ArgumentListNode(
            [new StringNode('plain')],
            'comma',
            false,
            ['tone' => new ListNode([new StringNode('value'), new StringNode('and')], 'space')],
        );
        $named = new NamedArgumentNode('accent', new ListNode([new StringNode('or')], 'space'));
        $map = new MapNode([
            new MapPair(new ListNode([new StringNode('and')], 'space'), new StringNode('plain')),
        ]);

        expect($this->accessor->callMethod('shouldPreserveCssArgument', [$list]))->toBeTrue()
            ->and($this->accessor->callMethod('shouldPreserveCssArgument', [$function]))->toBeTrue()
            ->and($this->accessor->callMethod('shouldPreserveCssArgument', [$argumentList]))->toBeTrue()
            ->and($this->accessor->callMethod('shouldPreserveCssArgument', [$named]))->toBeTrue()
            ->and($this->accessor->callMethod('shouldPreserveCssArgument', [$map]))->toBeTrue();
    });

    it('evaluates fallback pair helper changes and keeps rebuilt pair structure', function () {
        $env = new Environment();
        $env->getCurrentScope()->setVariable('key', new StringNode('resolved-key'));
        $env->getCurrentScope()->setVariable('value', new StringNode('resolved-value'));

        [$pairs, $changed] = $this->accessor->callMethod('evaluateFallbackPairs', [[
            new MapPair(new VariableReferenceNode('key'), new VariableReferenceNode('value')),
        ], $env]);

        expect($changed)->toBeTrue()
            ->and($pairs[0]->key)->toBeInstanceOf(StringNode::class)
            ->and($pairs[0]->key->value)->toBe('resolved-key')
            ->and($pairs[0]->value)->toBeInstanceOf(StringNode::class)
            ->and($pairs[0]->value->value)->toBe('resolved-value');
    });

    it('returns null for empty named colors', function () {
        expect($this->evaluator->resolveNamedColorHex(''))->toBeNull();
    });
});
