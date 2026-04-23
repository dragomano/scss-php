<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\Exceptions\InvalidLoopBoundaryException;
use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\EachNode;
use Bugo\SCSS\Nodes\ElseIfNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\VariableDefinition;
use Bugo\SCSS\Services\AstValueEvaluatorInterface;
use Bugo\SCSS\Services\AstValueFormatterInterface;
use Bugo\SCSS\Services\EachLoopBinderInterface;
use Bugo\SCSS\Services\ExtendsResolver;
use Bugo\SCSS\Services\FunctionConditionEvaluatorInterface;
use Bugo\SCSS\Services\Text;
use Bugo\SCSS\Services\VariableDeclarationApplierInterface;
use Bugo\SCSS\Utils\SelectorTokenizer;

describe('ExtendsResolver', function () {
    beforeEach(function () {
        $this->ctx = new CompilerContext();

        $this->iterationValues  = [];
        $this->conditionResults = [];

        $parser = new class implements ParserInterface {
            public function setTrackSourceLocations(bool $track): void {}

            public function parse(string $source): RootNode
            {
                return new RootNode();
            }
        };

        $evaluateValue = static fn(AstNode $node, Environment $env): AstNode => $node;

        $format = static function (AstNode $node, Environment $env): string {
            if ($node instanceof StringNode) {
                return $node->value;
            }

            if ($node instanceof NumberNode) {
                return (string) $node->value;
            }

            return '';
        };

        $this->createResolver = function (SelectorTokenizer $tokenizer) use ($parser, $evaluateValue, $format): ExtendsResolver {
            $text = new Text(
                $parser,
                new class ($evaluateValue) implements AstValueEvaluatorInterface {
                    public function __construct(private readonly Closure $evaluateValue) {}

                    public function evaluate(AstNode $node, Environment $env): AstNode
                    {
                        return ($this->evaluateValue)($node, $env);
                    }
                },
                new class ($format) implements AstValueFormatterInterface {
                    public function __construct(private readonly Closure $format) {}

                    public function format(AstNode $node, Environment $env): string
                    {
                        return ($this->format)($node, $env);
                    }
                },
            );

            return new ExtendsResolver(
                $this->ctx,
                $text,
                $tokenizer,
                new class ($evaluateValue) implements AstValueEvaluatorInterface {
                    public function __construct(private readonly Closure $evaluateValue) {}

                    public function evaluate(AstNode $node, Environment $env): AstNode
                    {
                        return ($this->evaluateValue)($node, $env);
                    }
                },
                new class ($this) implements FunctionConditionEvaluatorInterface {
                    public function __construct(private readonly object $testCase) {}

                    public function evaluate(string $condition, Environment $env): bool
                    {
                        return $this->testCase->conditionResults[$condition] ?? false;
                    }
                },
                new class ($this) implements VariableDeclarationApplierInterface {
                    public function __construct(private readonly object $testCase) {}

                    public function apply(AstNode $node, Environment $env): bool
                    {
                        if (! $node instanceof StringNode || $node->value !== 'tick') {
                            return false;
                        }

                        $value = $env->getCurrentScope()->getAstVariable('i');

                        if ($value instanceof NumberNode) {
                            $this->testCase->iterationValues[] = $value->value;
                        }

                        return true;
                    }
                },
                new class implements EachLoopBinderInterface {
                    public function items(AstNode $iterableValue): array
                    {
                        return [];
                    }

                    public function assign(array $variables, AstNode $item, Environment $env): void {}
                },
                new class ($format) implements AstValueFormatterInterface {
                    public function __construct(private readonly Closure $format) {}

                    public function format(AstNode $node, Environment $env): string
                    {
                        return ($this->format)($node, $env);
                    }
                },
            );
        };

        $this->resolver = ($this->createResolver)(new SelectorTokenizer());
    });

    it('adjusts exclusive for loop bounds before collecting children', function () {
        $this->resolver->collectExtends(
            new ForNode('i', new NumberNode(1), new NumberNode(3), false, [new StringNode('tick')]),
            new Environment(),
        );

        expect($this->iterationValues)->toBe([1, 2]);
    });

    it('throws max iteration guards for for and while collections', function () {
        $this->conditionResults['forever'] = true;

        expect(fn() => $this->resolver->collectExtends(
            new ForNode('i', new NumberNode(1), new NumberNode(10002), true),
            new Environment(),
        ))->toThrow(MaxIterationsExceededException::class)
            ->and(fn() => $this->resolver->collectExtends(
                new WhileNode('forever'),
                new Environment(),
            ))->toThrow(MaxIterationsExceededException::class);
    });

    it('collects extends from the first matching else-if branch', function () {
        $this->conditionResults['if'] = false;
        $this->conditionResults['elseif'] = true;

        $node = new IfNode(
            'if',
            [new RuleNode('.if', [new ExtendNode('%if-target')])],
            [new ElseIfNode('elseif', [new RuleNode('.picked', [new ExtendNode('%picked-target')])])],
            [new RuleNode('.else', [new ExtendNode('%else-target')])],
        );

        $this->resolver->collectExtends($node, new Environment());

        expect($this->ctx->outputState->extends->pendingExtends)->toBe([
            [
                'target'  => '%picked-target',
                'source'  => '.picked',
                'context' => '',
            ],
        ]);
    });

    it('collects root variables before traversing nested nodes and preserves declaration line', function () {
        $env = new Environment();

        $node = new RootNode([
            new VariableDeclarationNode('theme-color', new StringNode('red'), global: true, line: 12),
            new RuleNode('.picked', [new ExtendNode('%picked-target')]),
        ]);

        $this->resolver->collectExtends($node, $env);

        /** @var VariableDefinition $definition */
        $definition = $env->getCurrentScope()->findVariableDefinition('theme-color');

        expect($env->getCurrentScope()->getVariable('theme-color'))->toBeInstanceOf(StringNode::class)
            ->and($definition)->not->toBeNull()
            ->and($definition->line())->toBe(12)
            ->and($this->ctx->outputState->extends->pendingExtends)->toBe([
                [
                    'target'  => '%picked-target',
                    'source'  => '.picked',
                    'context' => '',
                ],
            ]);
    });

    it('resolves nested selectors against the current parent selector when collecting rule extends', function () {
        $env = new Environment();
        $env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode('.parent'));

        $this->resolver->collectExtends(
            new RuleNode('&:hover', [new ExtendNode('%hover-target')]),
            $env,
        );

        expect($this->ctx->outputState->extends->selectorContexts)->toHaveKey('.parent:hover')
            ->and($this->ctx->outputState->extends->pendingExtends)->toBe([
                [
                    'target'  => '%hover-target',
                    'source'  => '.parent:hover',
                    'context' => '',
                ],
            ]);
    });

    it('collects extends inside supports and block directives and skips directives without blocks', function () {
        $env = new Environment();

        $this->resolver->collectExtends(
            new SupportsNode('  (display: grid)  ', [
                new RuleNode('.grid', [new ExtendNode('%grid-target')]),
            ]),
            $env,
        );

        $this->resolver->collectExtends(
            new DirectiveNode('Media', '  screen  ', [
                new RuleNode('.screen', [new ExtendNode('%screen-target')]),
            ], true),
            $env,
        );

        $this->resolver->collectExtends(
            new DirectiveNode('media', 'print', [
                new RuleNode('.ignored', [new ExtendNode('%ignored-target')]),
            ], false),
            $env,
        );

        expect($this->ctx->outputState->extends->pendingExtends)->toBe([
            [
                'target'  => '%grid-target',
                'source'  => '.grid',
                'context' => '@supports (display: grid)',
            ],
            [
                'target'  => '%screen-target',
                'source'  => '.screen',
                'context' => '@media screen',
            ],
        ]);
    });

    it('iterates through each nodes and collects children for every assigned item', function () {
        $state = new stdClass();
        $state->assigned = [];

        $resolver = new ExtendsResolver(
            $this->ctx,
            new Text(
                new class implements ParserInterface {
                    public function setTrackSourceLocations(bool $track): void {}

                    public function parse(string $source): RootNode
                    {
                        return new RootNode();
                    }
                },
                new class implements AstValueEvaluatorInterface {
                    public function evaluate(AstNode $node, Environment $env): AstNode
                    {
                        return $node;
                    }
                },
                new class implements AstValueFormatterInterface {
                    public function format(AstNode $node, Environment $env): string
                    {
                        return $node instanceof StringNode ? $node->value : '';
                    }
                },
            ),
            new SelectorTokenizer(),
            new class implements AstValueEvaluatorInterface {
                public function evaluate(AstNode $node, Environment $env): AstNode
                {
                    return $node;
                }
            },
            new class implements FunctionConditionEvaluatorInterface {
                public function evaluate(string $condition, Environment $env): bool
                {
                    return false;
                }
            },
            new class implements VariableDeclarationApplierInterface {
                public function apply(AstNode $node, Environment $env): bool
                {
                    return false;
                }
            },
            new class ($state) implements EachLoopBinderInterface {
                public function __construct(private readonly object $state) {}

                public function items(AstNode $iterableValue): array
                {
                    return [new StringNode('first'), new StringNode('second')];
                }

                public function assign(array $variables, AstNode $item, Environment $env): void
                {
                    if ($item instanceof StringNode) {
                        $this->state->assigned[] = $item->value;
                        $env->getCurrentScope()->setVariableLocal($variables[0] ?? 'item', $item);
                    }
                }
            },
            new class implements AstValueFormatterInterface {
                public function format(AstNode $node, Environment $env): string
                {
                    return $node instanceof StringNode ? $node->value : '';
                }
            },
        );

        $resolver->collectExtends(
            new EachNode(['item'], new StringNode('list'), [
                new RuleNode('.item', [new ExtendNode('%item-target')]),
            ]),
            new Environment(),
        );

        expect($state->assigned)->toBe(['first', 'second'])
            ->and($this->ctx->outputState->extends->pendingExtends)->toBe([
                [
                    'target'  => '%item-target',
                    'source'  => '.item',
                    'context' => '',
                ],
                [
                    'target'  => '%item-target',
                    'source'  => '.item',
                    'context' => '',
                ],
            ]);
    });

    it('returns early for while nodes without matching conditions and collects at-root children', function () {
        $env = new Environment();

        $this->resolver->collectExtends(new WhileNode('never', [
            new RuleNode('.ignored', [new ExtendNode('%ignored-target')]),
        ]), $env);

        $this->resolver->collectExtends(new AtRootNode([
            new RuleNode('.rooted', [new ExtendNode('%root-target')]),
        ]), $env);

        expect($this->ctx->outputState->extends->pendingExtends)->toBe([
            [
                'target'  => '%root-target',
                'source'  => '.rooted',
                'context' => '',
            ],
        ]);
    });

    it('formats string loop boundaries and rejects invalid values', function () {
        $env = new Environment();

        $this->resolver->collectExtends(
            new ForNode('i', new StringNode('12.5'), new StringNode('12.5'), true, [new StringNode('tick')]),
            $env,
        );

        expect($this->iterationValues)->toBe([12])
            ->and(fn() => $this->resolver->collectExtends(
                new ForNode('i', new StringNode('oops'), new NumberNode(1), true),
                $env,
            ))
            ->toThrow(InvalidLoopBoundaryException::class);
    });

    it('ignores empty extend registrations and empty extend target lists', function () {
        $this->resolver->registerExtend('', '.source');
        $this->resolver->registerExtend('.target', '');

        expect($this->ctx->outputState->extends->extendMap)->toBe([])
            ->and($this->resolver->extractSimpleExtendTargetSelectors('   '))->toBe([]);
    });

    it('rejects complex extend selectors', function () {
        expect(fn() => $this->resolver->extractSimpleExtendTargetSelectors('.foo > .bar'))
            ->toThrow(SassErrorException::class, 'Complex selectors may not be extended. Use a simple selector target in @extend.');
    });

    it('skips empty selectors in extend target lists with a trailing comma', function () {
        $this->resolver->collectExtends(
            new RuleNode('.source', [new ExtendNode('%target,')]),
            new Environment(),
        );

        expect($this->ctx->outputState->extends->pendingExtends)->toBe([
            [
                'target'  => '%target',
                'source'  => '.source',
                'context' => '',
            ],
        ]);
    });

    it('does not replace partial selector token matches when applying extends', function () {
        $this->resolver->registerExtend('bar', '.baz');
        $this->ctx->outputState->extends->selectorContexts['bar'] = ['' => true];

        expect($this->resolver->applyExtendsToSelector('.foobar'))->toBe('.foobar');
    });

    it('falls back to substring replacement when structured selector extension cannot be used', function () {
        $this->resolver->registerExtend('bar', '> baz');
        $this->ctx->outputState->extends->selectorContexts['bar'] = ['' => true];

        expect($this->resolver->applyExtendsToSelector('foobar bar'))->toBe('foobar bar, foobar > baz');
    });

    it('falls back to direct replacement when weaving cannot apply without a preceding combinator', function () {
        $this->resolver->registerExtend('.bar', '.baz');
        $this->ctx->outputState->extends->selectorContexts['.bar'] = ['' => true];

        expect($this->resolver->applyExtendsToSelector('foo .bar > baz'))->toBe('foo .bar > baz, foo .baz > baz');
    });

    it('replaces the target directly when fallback weaving has no combinator before the match', function () {
        $this->resolver->registerExtend('.bar', '.baz');
        $this->ctx->outputState->extends->selectorContexts['.bar'] = ['' => true];

        expect($this->resolver->applyExtendsToSelector('foo > .bar'))->toBe('foo > .bar, foo > .baz');
    });

    it('skips empty selector parts when collecting extends from rule with trailing comma', function () {
        // ".foo," splits into [".foo", ""] — the empty part must be skipped
        $env = new Environment();

        $this->resolver->collectExtends(
            new RuleNode('.foo,', [new ExtendNode('%target')]),
            $env,
        );

        expect($this->ctx->outputState->extends->selectorContexts)->toHaveKey('.foo')
            ->and($this->ctx->outputState->extends->pendingExtends)->toHaveCount(1)
            ->and($this->ctx->outputState->extends->pendingExtends[0]['source'])->toBe('.foo,');
    });

    it('skips empty parts in applyExtendsToSelector', function () {
        // SelectorHelper::splitList with filterEmpty=false returns empty parts for leading comma
        $this->resolver->registerExtend('%ph', '.replacement');

        // ", .foo" produces ["", ".foo"] with filterEmpty=false — empty part must be skipped
        $result = $this->resolver->applyExtendsToSelector(', .foo');

        expect($result)->toBe('.foo');
    });

    it('returns selector unchanged when applyExtendsToSelector has no extend state and no placeholders', function () {
        expect($this->resolver->applyExtendsToSelector('.plain:hover'))->toBe('.plain:hover')
            ->and($this->resolver->hasCollectedExtends())->toBeFalse();
    });

    it('deduplicates extenders when the same extender appears via multiple paths', function () {
        // .a selector extended by .b and .c; .c also extended by .b
        // When resolving transitive extenders of .a: pending=[.c,.b], then .c adds .b again
        $this->resolver->registerExtend('.a', '.b');
        $this->resolver->registerExtend('.a', '.c');
        $this->resolver->registerExtend('.c', '.b');

        $this->ctx->outputState->extends->selectorContexts['.a'] = ['' => true];

        $result = $this->resolver->applyExtendsToSelector('.a');

        expect($result)->toContain('.b')
            ->and($result)->toContain('.c')
            ->and(substr_count($result, '.b'))->toBe(1);
    });

    it('skips already-seen nested extenders when traversing transitive chains', function () {
        // .a→.b; .b→.c,.d; .c→.d  — when processing .c's nested extenders, .d is already in $seen
        $this->resolver->registerExtend('.a', '.b');
        $this->resolver->registerExtend('.b', '.c');
        $this->resolver->registerExtend('.b', '.d');
        $this->resolver->registerExtend('.c', '.d');

        $this->ctx->outputState->extends->selectorContexts['.a'] = ['' => true];

        $result = $this->resolver->applyExtendsToSelector('.a');

        expect($result)->toContain('.b')
            ->and($result)->toContain('.c')
            ->and($result)->toContain('.d')
            ->and(substr_count($result, '.d'))->toBe(1);
    });

    it('collects extends from the matching if body or else body', function () {
        $env = new Environment();

        $this->conditionResults['if-true'] = true;
        $this->conditionResults['if-false'] = false;

        $this->resolver->collectExtends(
            new IfNode(
                'if-true',
                [new RuleNode('.if-body', [new ExtendNode('%if-target')])],
                [new ElseIfNode('elseif', [new RuleNode('.elseif', [new ExtendNode('%elseif-target')])])],
                [new RuleNode('.else-body', [new ExtendNode('%else-target')])],
            ),
            $env,
        );

        $this->resolver->collectExtends(
            new IfNode(
                'if-false',
                [new RuleNode('.if-body', [new ExtendNode('%if-target')])],
                [new ElseIfNode('elseif-false', [new RuleNode('.elseif', [new ExtendNode('%elseif-target')])])],
                [new RuleNode('.else-body', [new ExtendNode('%else-target')])],
            ),
            $env,
        );

        expect($this->ctx->outputState->extends->pendingExtends)->toBe([
            [
                'target'  => '%if-target',
                'source'  => '.if-body',
                'context' => '',
            ],
            [
                'target'  => '%else-target',
                'source'  => '.else-body',
                'context' => '',
            ],
        ]);
    });
});
