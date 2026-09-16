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
use Bugo\SCSS\Services\LoopIterator;
use Bugo\SCSS\Services\Text;
use Bugo\SCSS\Services\VariableDeclarationApplierInterface;
use Bugo\SCSS\Utils\SelectorComponent;
use Bugo\SCSS\Utils\SelectorTokenizer;

describe('ExtendsResolver', function () {
    beforeEach(function () {
        $this->ctx = new CompilerContext();

        $this->iterationValues  = [];
        $this->conditionResults = [];

        $parser = new class implements ParserInterface {
            public function setTrackSourceLocations(bool $track): void {}

            public function setPlainCss(bool $plainCss): void {}

            public function parse(string $source): RootNode
            {
                return new RootNode();
            }

            public function parseInlineExpression(string $expr): AstNode
            {
                return new StringNode($expr);
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

                    public function evaluate(string $condition, Environment $env, ?int $line = null): bool
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
                new LoopIterator(),
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
                'target'   => '%picked-target',
                'source'   => '.picked',
                'context'  => '',
                'optional' => false,
                'priority' => 1,
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
                    'target'   => '%picked-target',
                    'source'   => '.picked',
                    'context'  => '',
                    'optional' => false,
                    'priority' => 1,
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
                    'target'   => '%hover-target',
                    'source'   => '.parent:hover',
                    'context'  => '',
                    'optional' => false,
                    'priority' => 1,
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
                'target'   => '%grid-target',
                'source'   => '.grid',
                'context'  => '@supports (display: grid)',
                'optional' => false,
                'priority' => 1,
            ],
            [
                'target'   => '%screen-target',
                'source'   => '.screen',
                'context'  => '@media screen',
                'optional' => false,
                'priority' => 2,
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

                    public function setPlainCss(bool $plainCss): void {}

                    public function parse(string $source): RootNode
                    {
                        return new RootNode();
                    }

                    public function parseInlineExpression(string $expr): AstNode
                    {
                        return new StringNode($expr);
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
                public function evaluate(string $condition, Environment $env, ?int $line = null): bool
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
            new LoopIterator(),
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
                    'target'   => '%item-target',
                    'source'   => '.item',
                    'context'  => '',
                    'optional' => false,
                    'priority' => 1,
                ],
                [
                    'target'   => '%item-target',
                    'source'   => '.item',
                    'context'  => '',
                    'optional' => false,
                    'priority' => 2,
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
                'target'   => '%root-target',
                'source'   => '.rooted',
                'context'  => '',
                'optional' => false,
                'priority' => 1,
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
                'target'   => '%target',
                'source'   => '.source',
                'context'  => '',
                'optional' => false,
                'priority' => 1,
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
            ->and($this->ctx->outputState->extends->pendingExtends[0]['source'])->toBe('.foo');
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

    it('collects extends from each and while loops nested inside rules', function () {
        $env = new Environment();
        $env->getCurrentScope()->setVariableLocal('__extend_directive_context', new StringNode(''));

        $resolver = new ExtendsResolver(
            $this->ctx,
            new Text(
                new class implements ParserInterface {
                    public function setTrackSourceLocations(bool $track): void {}

                    public function setPlainCss(bool $plainCss): void {}

                    public function parse(string $source): RootNode
                    {
                        return new RootNode();
                    }

                    public function parseInlineExpression(string $expr): AstNode
                    {
                        return new StringNode($expr);
                    }
                },
                new class implements AstValueEvaluatorInterface {
                    public function evaluate(AstNode $node, Environment $env): AstNode
                    {
                        return new NumberNode(2);
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
                public function evaluate(string $condition, Environment $env, ?int $line = null): bool
                {
                    if ($condition === 'loop') {
                        $ran = isset($GLOBALS['__loopRan']);

                        $GLOBALS['__loopRan'] = true;

                        return ! $ran;
                    }

                    return false;
                }
            },
            new class implements VariableDeclarationApplierInterface {
                public function apply(AstNode $node, Environment $env): bool
                {
                    return false;
                }
            },
            new class implements EachLoopBinderInterface {
                public function items(AstNode $iterableValue): array
                {
                    return [new NumberNode(1), new NumberNode(2)];
                }

                public function assign(array $variables, AstNode $item, Environment $env): void {}
            },
            new class implements AstValueFormatterInterface {
                public function format(AstNode $node, Environment $env): string
                {
                    return $node instanceof StringNode ? $node->value : '';
                }
            },
            new LoopIterator(),
        );

        $resolver->collectExtends(
            new RuleNode('.looping', [
                new ExtendNode('%target'),
                new EachNode(['item'], new StringNode('items'), [
                    new ExtendNode('%each-target'),
                ]),
                new ForNode('i', new NumberNode(1), new NumberNode(2), true, [
                    new ExtendNode('%for-target'),
                ]),
                new WhileNode('loop', [
                    new ExtendNode('%while-target'),
                ]),
            ]),
            $env,
        );

        $targets = array_column($this->ctx->outputState->extends->pendingExtends, 'target');

        expect($targets)->toBe([
            '%target',
            '%each-target',
            '%each-target',
            '%for-target',
            '%for-target',
            '%while-target',
        ]);
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
                'target'   => '%if-target',
                'source'   => '.if-body',
                'context'  => '',
                'optional' => false,
                'priority' => 1,
            ],
            [
                'target'   => '%else-target',
                'source'   => '.else-body',
                'context'  => '',
                'optional' => false,
                'priority' => 2,
            ],
        ]);
    });

    it('applies pending extends on finalize and skips optional missing private placeholders', function () {
        $state = $this->ctx->outputState->extends;

        $state->pendingExtends = [
            [
                'target'   => '%_absent-secret',
                'source'   => '.src',
                'context'  => '',
                'optional' => true,
                'priority' => 1,
            ],
            [
                'target'   => '.target',
                'source'   => '.src',
                'context'  => '',
                'optional' => false,
                'priority' => 2,
            ],
        ];

        $state->selectorContexts['.target'] = ['' => true];

        $this->resolver->finalizeCollectedExtends();

        expect($state->extendMap)->toBe([
            '.target' => [
                ['source' => '.src', 'priority' => 2],
            ],
        ]);
    });

    it('rejects pending extends that cross media query contexts', function () {
        $state = $this->ctx->outputState->extends;

        $state->pendingExtends[] = [
            'target'   => '.target',
            'source'   => '.src',
            'context'  => '',
            'optional' => false,
            'priority' => 1,
        ];

        $state->selectorContexts['.target'] = ['@media screen' => true];

        expect(fn() => $this->resolver->finalizeCollectedExtends())
            ->toThrow(SassErrorException::class, 'You may not @extend selectors across media queries.');
    });

    it('drops foreign extensions whose target is a private placeholder', function () {
        $foreign = ($this->createResolver)(new SelectorTokenizer());

        $foreign->collectExtends(
            new RootNode([
                new RuleNode('%_secret', [new StringNode('x')]),
                new RuleNode('.ext', [new ExtendNode('%_secret')]),
            ]),
            new Environment(),
        );

        $foreignStore = $foreign->buildExtensionStore()['store'];

        expect($foreignStore['extensions'])->not->toBeEmpty();

        $store = ['extensions' => [], 'byExtender' => [], 'sourceSpecificity' => []];

        $this->resolver->addForeignExtensionsToStore($store, [$foreignStore]);

        expect($store['extensions'])->toBe([]);
    });

    it('merges repeated extension of the same extender into one non-optional record', function () {
        $state = $this->ctx->outputState->extends;

        $this->resolver->collectExtends(new RootNode([
            new RuleNode('.base', []),
            new RuleNode('.lead', [new ExtendNode('.base', true)]),
            new RuleNode('.lead', [new ExtendNode('.base')], 2),
        ]), new Environment());

        expect($state->events)->toHaveCount(5);

        $built = $this->resolver->buildExtensionStore();

        $extension = $built['store']['extensions']['.base']['.lead'];

        expect($extension['optional'])->toBeFalse()
            ->and($extension['extender'][0]->sel)->toBe('.lead');
    });

    it('returns a selector unchanged when it is already one of the rendered box selectors', function () {
        $this->ctx->outputState->extends->boxes = [
            ['rawParts' => ['.a'], 'selectors' => ['.a', '.b'], 'originals' => ['.a'], 'context' => ''],
        ];

        expect($this->resolver->applyExtendsToSelector('.b'))->toBe('.b');
    });

    it('uses the direct substring fallback when the extend target has no selector tokens', function () {
        $this->resolver->registerExtend('>', '.baz');
        $this->ctx->outputState->extends->selectorContexts['>'] = ['' => true];

        expect($this->resolver->applyExtendsToSelector('foo > bar'))->toBe('foo > bar, foo .baz bar');
    });

    it('weaves a multi-compound extender across a preceding combinator during fallback replacement', function () {
        $this->resolver->registerExtend('+', '.x .y');
        $this->ctx->outputState->extends->selectorContexts['+'] = ['' => true];

        expect($this->resolver->applyExtendsToSelector('a > b + c'))->toBe('a > b + c, .x a > b .y c');
    });

    it('falls back to direct replacement when the weaved extender has a single compound', function () {
        $this->resolver->registerExtend('+', '.x');
        $this->ctx->outputState->extends->selectorContexts['+'] = ['' => true];

        expect($this->resolver->applyExtendsToSelector('a > b + c'))->toBe('a > b + c, a > b .x c');
    });

    it('skips blank selector parts when collecting the line break map', function () {
        $this->resolver->registerExtend('%p', '.x');

        expect($this->resolver->applyExtendsToSelector('.a, ,.b'))->toBe('.a, .b');
    });

    it('drops a rule whose placeholder survives inside a non-selector functional pseudo', function () {
        $this->resolver->registerExtend('%p', '.x');

        expect($this->resolver->applyExtendsToSelector(':lang(%foo)'))->toBe('');
    });

    it('keeps the child selector when an empty parent selector cannot be combined', function () {
        $env = new Environment();
        $env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode(''));

        $this->resolver->collectExtends(new RuleNode('.foo', [new ExtendNode('%t')]), $env);

        expect($this->ctx->outputState->extends->selectorContexts)->toHaveKey('.foo');
    });

    it('computes structural specificity for nth-of pseudos while building the store', function () {
        $this->resolver->collectExtends(new RootNode([
            new RuleNode(':nth-child(2 of .x)', [new ExtendNode('.y')]),
        ]), new Environment());

        $this->resolver->finalizeCollectedExtends();

        expect($this->ctx->outputState->extends->boxes)->not->toBeEmpty();
    });

    it('drops an extended variant that is equivalent to another variant', function () {
        $this->resolver->registerExtend('.x', '.a.b');
        $this->resolver->registerExtend('.y', '.b.a');

        expect($this->resolver->applyExtendsToSelector('.x, .y'))->toBe('.x, .y, .b.a');
    });

    it('drops an extended variant that is a strict subset of a protected selector', function () {
        $this->resolver->registerExtend('.a', '.a.b');

        expect($this->resolver->applyExtendsToSelector('.a'))->toBe('.a');
    });

    it('keeps variants when the candidate superselector has more compounds', function () {
        $this->resolver->registerExtend('.x', '.a');
        $this->resolver->registerExtend('.y', '.b .c');

        expect($this->resolver->applyExtendsToSelector('.x, .y'))->toBe('.x, .a, .y, .b .c');
    });

    it('keeps variants whose pseudo elements differ from the candidate superselector', function () {
        $this->resolver->registerExtend('.x', '.a::before');
        $this->resolver->registerExtend('.y', '.a::after');

        expect($this->resolver->applyExtendsToSelector('.x, .y'))->toBe('.x, .a::before, .y, .a::after');
    });

    it('drops a compound-universal variant covered by a plain superselector', function () {
        $this->resolver->registerExtend('.x', '.a');
        $this->resolver->registerExtend('.y', '*.a');

        expect($this->resolver->applyExtendsToSelector('.x, .y'))->toBe('.x, .y, *.a');
    });

    it('rejects a namespaced universal superselector with a conflicting namespace', function () {
        $this->resolver->registerExtend('.p', 'html|*');
        $this->resolver->registerExtend('.q', 'svg|*');

        expect($this->resolver->applyExtendsToSelector('.p, .q'))->toBe('.p, html|*, .q, svg|*');
    });

    it('drops a variant covered by a same-namespace universal superselector', function () {
        $this->resolver->registerExtend('.a1', 'x svg|*');
        $this->resolver->registerExtend('.a2', 'x svg|* y');

        expect($this->resolver->applyExtendsToSelector('.a1, .a2'))->toBe('.a1, x svg|*, .a2');
    });

    it('drops a variant covered by a superselector whose universal namespace matches the candidate', function () {
        $this->resolver->registerExtend('.b1', 'x svg|*');
        $this->resolver->registerExtend('.b2', 'x html|* y');

        expect($this->resolver->applyExtendsToSelector('.b1, .b2'))->toBe('.b1, x svg|*, .b2, x html|* y');
    });

    it('skips foreign extensions whose own target is missing from the local store', function () {
        $state = $this->ctx->outputState->extends;
        $state->resetCollection();

        $ghost = [
            'extender' => [new SelectorComponent('.q', '')],
            'target'   => '.ghost',
            'context'  => '',
            'optional' => false,
            'priority' => 1,
        ];

        $useless = [
            'extender' => [new SelectorComponent('.x', '', '> >')],
            'target'   => '.t',
            'context'  => '',
            'optional' => false,
            'priority' => 1,
        ];

        $store = [
            'selectors'         => ['.t' => [5 => true]],
            'extensions'        => [],
            'byExtender'        => ['.t' => [$ghost, $useless]],
            'contexts'          => [5 => ''],
            'sourceSpecificity' => [],
            'originals'         => [],
            'boxes'             => [5 => [[new SelectorComponent('.t', '')]]],
        ];

        $foreign = [
            'selectors'         => [],
            'extensions'        => ['.t' => [
                '.f' => [
                    'extender' => [new SelectorComponent('.f', '')],
                    'target'   => '.t',
                    'context'  => '',
                    'optional' => false,
                    'priority' => 1,
                ],
            ]],
            'byExtender'        => [],
            'contexts'          => [],
            'sourceSpecificity' => [],
            'originals'         => [],
            'boxes'             => [],
        ];

        $this->resolver->addForeignExtensionsToStore($store, [$foreign]);

        expect($store['extensions'])->toHaveKey('.t')
            ->and($store['extensions'])->not->toHaveKey('.ghost');
    });

    it('propagates a transitive extension from a chained extender', function () {
        $state = $this->ctx->outputState->extends;
        $state->resetCollection();

        $state->events = [
            ['type' => 'rule', 'boxId' => 0, 'rawParts' => ['.a'], 'resolvedParts' => ['.a'], 'context' => ''],
            ['type' => 'rule', 'boxId' => 1, 'rawParts' => ['.b'], 'resolvedParts' => ['.b'], 'context' => ''],
            ['type' => 'extend', 'boxId' => 1, 'target' => '.a', 'context' => '', 'optional' => false, 'priority' => 1],
            ['type' => 'rule', 'boxId' => 2, 'rawParts' => ['.c'], 'resolvedParts' => ['.c'], 'context' => ''],
            ['type' => 'extend', 'boxId' => 2, 'target' => '.b', 'context' => '', 'optional' => false, 'priority' => 2],
        ];

        $built = $this->resolver->buildExtensionStore();
        $result = $this->resolver->materializeExtensionStore($built['store'], $built['meta']);

        expect(array_keys($built['store']['extensions']['.a']))->toContain('.c')
            ->and($result['extendMap'])->toHaveKey('.a');
    });

    it('unifies extenders that carry different leading combinators', function () {
        $state = $this->ctx->outputState->extends;
        $state->resetCollection();

        $state->events = [
            ['type' => 'rule', 'boxId' => 0, 'rawParts' => ['.a.b'], 'resolvedParts' => ['.a.b'], 'context' => ''],
            ['type' => 'rule', 'boxId' => 1, 'rawParts' => ['> .x'], 'resolvedParts' => ['> .x'], 'context' => ''],
            ['type' => 'extend', 'boxId' => 1, 'target' => '.a', 'context' => '', 'optional' => false, 'priority' => 1],
            ['type' => 'rule', 'boxId' => 2, 'rawParts' => ['+ .z'], 'resolvedParts' => ['+ .z'], 'context' => ''],
            ['type' => 'extend', 'boxId' => 2, 'target' => '.b', 'context' => '', 'optional' => false, 'priority' => 2],
        ];

        $built = $this->resolver->buildExtensionStore();
        $result = $this->resolver->materializeExtensionStore($built['store'], $built['meta']);

        expect($result['extendMap'])->toHaveKey('.a');
    });

    it('unifies extenders that carry different trailing combinators', function () {
        $state = $this->ctx->outputState->extends;
        $state->resetCollection();

        $state->events = [
            ['type' => 'rule', 'boxId' => 0, 'rawParts' => ['.a.b'], 'resolvedParts' => ['.a.b'], 'context' => ''],
            ['type' => 'rule', 'boxId' => 1, 'rawParts' => ['.x >'], 'resolvedParts' => ['.x >'], 'context' => ''],
            ['type' => 'extend', 'boxId' => 1, 'target' => '.a', 'context' => '', 'optional' => false, 'priority' => 1],
            ['type' => 'rule', 'boxId' => 2, 'rawParts' => ['.z +'], 'resolvedParts' => ['.z +'], 'context' => ''],
            ['type' => 'extend', 'boxId' => 2, 'target' => '.b', 'context' => '', 'optional' => false, 'priority' => 2],
        ];

        $built = $this->resolver->buildExtensionStore();
        $result = $this->resolver->materializeExtensionStore($built['store'], $built['meta']);

        expect($result['extendMap'])->toHaveKey('.a');
    });

    it('expands a nested nth-of pseudo when its inner selector is extended', function () {
        $state = $this->ctx->outputState->extends;
        $state->resetCollection();

        $state->events = [
            ['type' => 'rule', 'boxId' => 0, 'rawParts' => ['.a'], 'resolvedParts' => ['.a'], 'context' => ''],
            ['type' => 'rule', 'boxId' => 1, 'rawParts' => [':nth-child(2 of :nth-child(2 of .a))'], 'resolvedParts' => [':nth-child(2 of :nth-child(2 of .a))'], 'context' => ''],
            ['type' => 'rule', 'boxId' => 2, 'rawParts' => ['.x'], 'resolvedParts' => ['.x'], 'context' => ''],
            ['type' => 'extend', 'boxId' => 2, 'target' => '.a', 'context' => '', 'optional' => false, 'priority' => 1],
        ];

        $built = $this->resolver->buildExtensionStore();
        $result = $this->resolver->materializeExtensionStore($built['store'], $built['meta']);

        expect($result['boxes'])->toBeArray();
    });

    it('skips a single-option extension candidate that is a useless complex', function () {
        $store = [
            'selectors'         => ['.a' => [0 => true]],
            'extensions'        => [],
            'byExtender'        => [],
            'contexts'          => [0 => ''],
            'sourceSpecificity' => [],
            'originals'         => [],
            'boxes'             => [0 => [[new SelectorComponent('.a', '')]]],
        ];

        $foreign = [
            'selectors'         => [],
            'extensions'        => ['.a' => [
                'useless' => [
                    'extender' => [new SelectorComponent('.x', '', '> >')],
                    'target'   => '.a',
                    'context'  => '',
                    'optional' => false,
                    'priority' => 1,
                ],
            ]],
            'byExtender'        => [],
            'contexts'          => [],
            'sourceSpecificity' => [],
            'originals'         => [],
            'boxes'             => [],
        ];

        $this->resolver->addForeignExtensionsToStore($store, [$foreign]);

        expect($store['extensions']['.a'])->toHaveKey('useless');
    });

    it('drops a unified path whose non-original extender is a useless complex', function () {
        $store = [
            'selectors'         => ['.a' => [0 => true], '.b' => [0 => true]],
            'extensions'        => [],
            'byExtender'        => [],
            'contexts'          => [0 => ''],
            'sourceSpecificity' => [],
            'originals'         => [],
            'boxes'             => [0 => [[new SelectorComponent('.a.b', '')]]],
        ];

        $foreign = [
            'selectors'         => [],
            'extensions'        => [
                '.a' => ['u' => ['extender' => [new SelectorComponent('.x', '', '> >')], 'target' => '.a', 'context' => '', 'optional' => false, 'priority' => 1]],
                '.b' => ['n' => ['extender' => [new SelectorComponent('.z', '')], 'target' => '.b', 'context' => '', 'optional' => false, 'priority' => 1]],
            ],
            'byExtender'        => [],
            'contexts'          => [],
            'sourceSpecificity' => [],
            'originals'         => [],
            'boxes'             => [],
        ];

        $this->resolver->addForeignExtensionsToStore($store, [$foreign]);

        expect($store['extensions']['.a'])->toHaveKey('u')
            ->and($store['extensions']['.b'])->toHaveKey('n');
    });

    it('rejects unification of extenders carrying different leading combinators', function () {
        $store = [
            'selectors'         => ['.a' => [0 => true], '.b' => [0 => true]],
            'extensions'        => [],
            'byExtender'        => [],
            'contexts'          => [0 => ''],
            'sourceSpecificity' => [],
            'originals'         => [],
            'boxes'             => [0 => [[new SelectorComponent('.a.b', '')]]],
        ];

        $foreign = [
            'selectors'         => [],
            'extensions'        => [
                '.a' => ['gt' => ['extender' => [new SelectorComponent('.x', '', '>')], 'target' => '.a', 'context' => '', 'optional' => false, 'priority' => 1]],
                '.b' => ['pl' => ['extender' => [new SelectorComponent('.z', '', '+')], 'target' => '.b', 'context' => '', 'optional' => false, 'priority' => 1]],
            ],
            'byExtender'        => [],
            'contexts'          => [],
            'sourceSpecificity' => [],
            'originals'         => [],
            'boxes'             => [],
        ];

        $this->resolver->addForeignExtensionsToStore($store, [$foreign]);

        expect($store['extensions']['.a'])->toHaveKey('gt')
            ->and($store['extensions']['.b'])->toHaveKey('pl');
    });

    it('rejects unification of extenders carrying different trailing combinators', function () {
        $store = [
            'selectors'         => ['.a' => [0 => true], '.b' => [0 => true]],
            'extensions'        => [],
            'byExtender'        => [],
            'contexts'          => [0 => ''],
            'sourceSpecificity' => [],
            'originals'         => [],
            'boxes'             => [0 => [[new SelectorComponent('.a.b', '')]]],
        ];

        $foreign = [
            'selectors'         => [],
            'extensions'        => [
                '.a' => ['gt' => ['extender' => [new SelectorComponent('.x', '>')], 'target' => '.a', 'context' => '', 'optional' => false, 'priority' => 1]],
                '.b' => ['pl' => ['extender' => [new SelectorComponent('.z', '+')], 'target' => '.b', 'context' => '', 'optional' => false, 'priority' => 1]],
            ],
            'byExtender'        => [],
            'contexts'          => [],
            'sourceSpecificity' => [],
            'originals'         => [],
            'boxes'             => [],
        ];

        $this->resolver->addForeignExtensionsToStore($store, [$foreign]);

        expect($store['extensions']['.a'])->toHaveKey('gt')
            ->and($store['extensions']['.b'])->toHaveKey('pl');
    });

    it('keeps a candidate with an unmatched token against a plain universal superselector', function () {
        $this->resolver->registerExtend('.g1', 'x .b');
        $this->resolver->registerExtend('.g2', 'x *.a y');

        expect($this->resolver->applyExtendsToSelector('.g1, .g2'))->toBe('.g1, x .b, .g2, x *.a y');
    });
});
