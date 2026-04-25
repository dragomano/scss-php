<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Runtime\DeferredAtRuleChunk;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Services\AstValueEvaluatorInterface;
use Bugo\SCSS\Services\AstValueFormatterInterface;
use Bugo\SCSS\Services\CalculationArgumentNormalizerInterface;
use Bugo\SCSS\Services\CssArgumentEvaluator;
use Bugo\SCSS\Services\ModuleVariableAssigner;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Style;
use Bugo\SCSS\Utils\SelectorTokenizer;
use Tests\RuntimeFactory;

describe('Selector', function () {
    beforeEach(function () {
        $this->ctx      = new CompilerContext();
        $this->runtime  = RuntimeFactory::createRuntime(context: $this->ctx);
        $this->selector = $this->runtime->selector();
    });

    describe('getCurrentAtRuleStack()', function () {
        it('returns empty array when at-rule stack variable is not an array', function () {
            $env = new Environment();
            $env->getCurrentScope()->setVariableLocal('__at_rule_stack', 'invalid');

            expect($this->selector->getCurrentAtRuleStack($env))->toBe([]);
        });

        it('normalizes directive and supports entries and skips invalid supports payloads', function () {
            $env = new Environment();
            $env->getCurrentScope()->setVariableLocal('__at_rule_stack', [
                'broken',
                ['type' => ''],
                ['type' => 'directive'],
                ['type' => 'Media', 'name' => 'Screen', 'prelude' => '  ignored  '],
                ['type' => 'directive', 'name' => 'Screen', 'prelude' => '  (MIN-WIDTH: 10PX)  '],
                ['type' => 'supports', 'condition' => '  display:grid  '],
                ['type' => 'supports'],
            ]);

            expect($this->selector->getCurrentAtRuleStack($env))->toEqual([
                AtRuleContextEntry::directive('screen', '(MIN-WIDTH: 10PX)'),
                AtRuleContextEntry::supports('display:grid'),
            ]);
        });
    });

    describe('combineMediaQueryPreludes()', function () {
        it('joins outer and inner with "and"', function () {
            expect($this->selector->combineMediaQueryPreludes('screen', '(min-width: 10px)'))
                ->toBe('screen and (min-width: 10px)');
        });

        it('returns inner when outer is empty', function () {
            expect($this->selector->combineMediaQueryPreludes('', '(max-width: 600px)'))
                ->toBe('(max-width: 600px)');
        });

        it('returns outer when inner is empty', function () {
            expect($this->selector->combineMediaQueryPreludes('print', ''))
                ->toBe('print');
        });

        it('joins two feature queries', function () {
            expect($this->selector->combineMediaQueryPreludes('(min-width: 600px)', '(orientation: landscape)'))
                ->toBe('(min-width: 600px) and (orientation: landscape)');
        });

        it('combines non-empty media prelude parts after selector list normalization', function () {
            expect($this->selector->combineMediaQueryPreludes('screen, ', ', (color)'))
                ->toBe('screen and (color)');
        });
    });

    describe('resolveNestedSelector()', function () {
        it('returns selector unchanged when selector list or parent list is empty', function () {
            expect($this->selector->resolveNestedSelector('', '.button'))->toBe('')
                ->and($this->selector->resolveNestedSelector('.icon', ''))->toBe('.icon');
        });

        it('replaces & with parent selector', function () {
            expect($this->selector->resolveNestedSelector('&:hover', '.button'))
                ->toBe('.button:hover');
        });

        it('returns child unchanged when no & present', function () {
            expect($this->selector->resolveNestedSelector('.icon', '.button'))
                ->toBe('.icon');
        });

        it('handles multiple & references', function () {
            expect($this->selector->resolveNestedSelector('&.active, &:focus', '.btn'))
                ->toContain('.btn.active');
        });
    });

    describe('combineNestedSelectorWithParent()', function () {
        it('returns selector unchanged when selector list or parent list is empty', function () {
            expect($this->selector->combineNestedSelectorWithParent('', '.button'))->toBe('')
                ->and($this->selector->combineNestedSelectorWithParent('.icon', ''))->toBe('.icon');
        });

        it('appends child after parent with space', function () {
            expect($this->selector->combineNestedSelectorWithParent('.icon', '.button'))
                ->toBe('.button .icon');
        });

        it('prepends parent to element child', function () {
            expect($this->selector->combineNestedSelectorWithParent('span', '.nav'))
                ->toBe('.nav span');
        });

    });

    describe('splitTopLevelSelectorList()', function () {
        it('splits comma-separated selectors', function () {
            expect($this->selector->splitTopLevelSelectorList('.a, .b, .c'))
                ->toBe(['.a', '.b', '.c']);
        });

        it('does not split inside :not()', function () {
            expect($this->selector->splitTopLevelSelectorList('.a, :not(.b, .c), .d'))
                ->toBe(['.a', ':not(.b, .c)', '.d']);
        });

        it('returns single selector as one-element array', function () {
            expect($this->selector->splitTopLevelSelectorList('.only'))
                ->toBe(['.only']);
        });
    });

    describe('optimizeRuleBlock()', function () {
        it('returns short rule blocks unchanged', function () {
            $input = ".a {\n}";

            expect($this->selector->optimizeRuleBlock($input))->toBe($input);
        });

        it('returns two-line rule blocks unchanged even when they contain multiple declarations on one line', function () {
            $input = ".a {\n  color: red; margin: 0;";

            expect($this->selector->optimizeRuleBlock($input))->toBe($input);
        });

        it('deduplicates repeated declarations', function () {
            $result = $this->selector->optimizeRuleBlock(".a {\n  color: red;\n  color: red;\n}");

            expect($result)->toBe(".a {\n  color: red;\n}");
        });

        it('keeps last value when property repeated with different values', function () {
            $result = $this->selector->optimizeRuleBlock(".a {\n  color: red;\n  color: blue;\n}");

            expect($result)->toContain('color: blue')
                ->and($result)->not->toContain('color: red');
        });

        it('leaves block unchanged when no duplicates', function () {
            $input  = ".a {\n  color: red;\n  margin: 0;\n}";
            $result = $this->selector->optimizeRuleBlock($input);

            expect($result)->toBe($input);
        });

        it('returns blocks without declarations unchanged', function () {
            $input = ".a {\n  .b {\n  }\n}";

            expect($this->selector->optimizeRuleBlock($input))->toBe($input);
        });

        it('returns nested-only blocks with inner blank lines unchanged when no declarations were collected', function () {
            $input = ".a {\n\n  .b {\n  }\n}";

            expect($this->selector->optimizeRuleBlock($input))->toBe($input);
        });

        it('removes inner blank lines when optimizing duplicate declarations', function () {
            $input = ".a {\n  color: red;\n\n  color: red;\n}";

            expect($this->selector->optimizeRuleBlock($input))->toBe(".a {\n  color: red;\n}");
        });
    });

    describe('optimizeAdjacentSiblingRuleBlocks()', function () {
        it('keeps a trailing sibling block without closing brace unchanged', function () {
            $input = ".a {\n  color: red;\n  margin: 0;";

            expect($this->selector->optimizeAdjacentSiblingRuleBlocks($input))->toBe($input);
        });

        it('keeps adjacent sibling blocks separate when selectors do not match', function () {
            $input = ".a {\n  color: red;\n}\n\n.b {\n  color: blue;\n}";

            expect($this->selector->optimizeAdjacentSiblingRuleBlocks($input))->toBe($input);
        });
    });

    describe('normalizeBubblingNodeForSelector()', function () {
        it('returns nodes unchanged when selector is empty', function () {
            $node = new DirectiveNode('media', '(width > 10px)', [], true);

            expect($this->selector->normalizeBubblingNodeForSelector($node, ''))->toBe($node)
                ->and($this->selector->normalizeBubblingNodeForSelector(new SupportsNode('(display: grid)'), ''))
                ->toEqual(new SupportsNode('(display: grid)'));
        });

        it('returns non-bubbling block directives unchanged when selector is not empty', function () {
            $node = new DirectiveNode('custom', 'value', [], false);

            expect($this->selector->normalizeBubblingNodeForSelector($node, '.parent'))->toBe($node);
        });
    });

    describe('drainDeferredAtRuleEscapes()', function () {
        it('skips empty deferred chunks', function () {
            $this->ctx->outputState->deferral->atRuleStack = [[new DeferredAtRuleChunk(1, '')]];

            expect($this->selector->drainDeferredAtRuleEscapes())->toBe([])
                ->and($this->ctx->outputState->deferral->atRuleStack)->toBe([]);
        });

        it('moves deeper deferred chunks to the parent stack level', function () {
            $this->ctx->outputState->deferral->atRuleStack = [
                [],
                [
                    new DeferredAtRuleChunk(2, 'nested'),
                ],
            ];

            $result = $this->selector->drainDeferredAtRuleEscapes();

            expect($result)->toBe([])
                ->and($this->ctx->outputState->deferral->atRuleStack)->toEqual([
                    [new DeferredAtRuleChunk(1, 'nested')],
                ]);
        });

        it('returns deferred chunks outside when no parent at-rule level exists', function () {
            $this->ctx->outputState->deferral->atRuleStack = [[new DeferredAtRuleChunk(2, 'outside')]];

            expect($this->selector->drainDeferredAtRuleEscapes())->toBe(['outside'])
                ->and($this->ctx->outputState->deferral->atRuleStack)->toBe([]);
        });
    });

    describe('getCurrentParentSelector()', function () {
        it('returns null when current parent selector is not a string node', function () {
            $env = new Environment();
            $env->getCurrentScope()->setVariableLocal('__parent_selector', 'invalid');

            expect($this->selector->getCurrentParentSelector($env))->toBeNull();
        });
    });

    describe('parseNestedPropertyBlockSelector()', function () {
        it('accepts names with a leading hyphen and rejects invalid characters', function () {
            expect($this->selector->parseNestedPropertyBlockSelector('-foo: bar'))->toBe([
                'property' => '-foo',
                'value'    => 'bar',
            ])->and($this->selector->parseNestedPropertyBlockSelector('foo!: bar'))->toBeNull()
                ->and($this->selector->parseNestedPropertyBlockSelector('-: bar'))->toBeNull();
        });
    });

    describe('compileNestedPropertyBlockChildren()', function () {
        beforeEach(function () {
            $valueEvaluator = new class implements AstValueEvaluatorInterface {
                public function evaluate($node, Environment $env): Bugo\SCSS\Nodes\AstNode
                {
                    if ($node instanceof StringNode && $node->value === 'nullish') {
                        return new NullNode();
                    }

                    return $node;
                }
            };

            $this->testSelector = new Selector(
                $this->ctx,
                new CompilerOptions(style: Style::COMPRESSED),
                $this->runtime->render(),
                $this->runtime->text(),
                new SelectorTokenizer(),
                $this->runtime->dispatcher(),
                $this->runtime->extends(),
                new ModuleVariableAssigner($this->runtime),
                new CssArgumentEvaluator(
                    $valueEvaluator,
                    new class implements CalculationArgumentNormalizerInterface {
                        public function normalize(string $name, array $arguments): array
                        {
                            return $arguments;
                        }
                    },
                ),
                $valueEvaluator,
                new class implements AstValueFormatterInterface {
                    public function format($node, Environment $env): string
                    {
                        if ($node instanceof StringNode || $node instanceof ColorNode) {
                            return $node->value;
                        }

                        return '';
                    }
                },
            );
        });

        it('applies variable and module declarations without producing output', function () {
            $env         = new Environment();
            $moduleScope = new Scope();

            $env->getCurrentScope()->addModule('theme', $moduleScope);

            $result = $this->testSelector->compileNestedPropertyBlockChildren([
                new VariableDeclarationNode('local-var', new StringNode('set')),
                new ModuleVarDeclarationNode('theme', 'accent', new StringNode('x')),
            ], $env, 0, 'border');

            /** @var StringNode $assignedValue */
            $assignedValue = $moduleScope->getAstVariable('accent');

            expect($result)->toBe('')
                ->and($env->getCurrentScope()->getVariable('local-var'))->toBeInstanceOf(StringNode::class)
                ->and($assignedValue)->toBeInstanceOf(StringNode::class)
                ->and($assignedValue->value)->toBe('x');
        });

        it('skips null values and compresses named colors when required', function () {
            $env = new Environment();

            $result = $this->testSelector->compileNestedPropertyBlockChildren([
                new DeclarationNode('style', new StringNode('nullish')),
                new DeclarationNode('color', new StringNode('red')),
            ], $env, 0, 'border');

            expect($result)->toBe('border-color: #f00;');
        });

        it('ignores non-rule children and rules without nested-property selectors', function () {
            $env = new Environment();

            $result = $this->testSelector->compileNestedPropertyBlockChildren([
                new StringNode('ignored'),
                new RuleNode('.child', [
                    new DeclarationNode('shade', new StringNode('blue')),
                ]),
            ], $env, 0, 'border');

            expect($result)->toBe('');
        });

        it('skips empty nested-property rule output and appends non-empty nested chunks', function () {
            $env = new Environment();

            $result = $this->testSelector->compileNestedPropertyBlockChildren([
                new RuleNode('accent:', []),
                new RuleNode('accent:', [
                    new DeclarationNode('shade', new StringNode('blue')),
                ]),
            ], $env, 0, 'border', 'solid');

            expect($result)->toBe("border: solid;\nborder-accent-shade: #00f;");
        });
    });

    describe('compileAtRootBody()', function () {
        it('preserves the current stack when at-root query rules normalize to an empty list', function () {
            $env = new Environment();
            $env->getCurrentScope()->setVariableLocal('__at_rule_stack', [
                ['type' => 'directive', 'name' => 'media'],
            ]);

            $result = $this->selector->compileAtRootBody(
                new AtRootNode(
                    [new DeclarationNode('color', new StringNode('red'))],
                    'with',
                    [' ', ''],
                ),
                $env,
            );

            expect($result)->toBe([
                'chunk' => "@media {\n  color: red;\n}",
                'escapeLevels' => 1,
            ]);
        });

        it('keeps rule context only for matching at-root query modes and rules', function () {
            $env = new Environment();
            $env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode('.parent'));
            $env->getCurrentScope()->setVariableLocal('__at_rule_stack', [
                ['type' => 'other', 'name' => '  media  ', 'prelude' => '  screen  '],
                ['type' => 'supports', 'condition' => '  (display: grid)  '],
            ]);

            $withSupports = $this->selector->compileAtRootBody(
                new AtRootNode(
                    [new DeclarationNode('color', new StringNode('red'))],
                    'with',
                    ['supports'],
                ),
                $env,
            );

            $withRule = $this->selector->compileAtRootBody(
                new AtRootNode(
                    [new DeclarationNode('color', new StringNode('red'))],
                    'with',
                    ['rule'],
                ),
                $env,
            );

            $withoutRule = $this->selector->compileAtRootBody(
                new AtRootNode(
                    [new DeclarationNode('color', new StringNode('red'))],
                    'without',
                    ['rule'],
                ),
                $env,
            );

            $invalidMode = $this->selector->compileAtRootBody(
                new AtRootNode(
                    [new DeclarationNode('color', new StringNode('red'))],
                    'invalid',
                    ['rule'],
                ),
                $env,
            );

            expect($withSupports['chunk'])->toBe("@supports (display: grid) {\n  color: red;\n}")
                ->and($withRule['chunk'])->toBe('.parent {' . "\n  color: red;\n}")
                ->and($withoutRule['chunk'])->toBe("@supports (display: grid) {\n  color: red;\n}")
                ->and($invalidMode['chunk'])->toBe("@supports (display: grid) {\n  color: red;\n}");
        });

        it('drops unmatched directive entries from at-root query filtering', function () {
            $env = new Environment();
            $env->getCurrentScope()->setVariableLocal('__at_rule_stack', [
                ['type' => 'directive', 'name' => 'font-face'],
            ]);

            $result = $this->selector->compileAtRootBody(
                new AtRootNode(
                    [new DeclarationNode('color', new StringNode('red'))],
                    'with',
                    ['media'],
                ),
                $env,
            );

            expect($result)->toBe([
                'chunk'        => 'color: red;',
                'escapeLevels' => 1,
            ]);
        });

        it('wraps declarations with the parent selector but leaves rules and nested at-root nodes unchanged', function () {
            $env = new Environment();
            $env->getCurrentScope()->setVariableLocal('__parent_selector', new StringNode('.parent'));

            $result = $this->selector->compileAtRootBody(
                new AtRootNode([
                    new RuleNode('.child', []),
                    new AtRootNode([]),
                    new DeclarationNode('color', new StringNode('red')),
                ], 'with', ['rule']),
                $env,
            );

            expect($result['chunk'])->toBe(".parent {\n  color: red;\n}")
                ->and($result['escapeLevels'])->toBe(0);
        });
    });

    it('normalizes supports and media children with the parent selector and wraps declarations', function () {
        $supports = $this->selector->normalizeBubblingNodeForSelector(
            new SupportsNode('(display: grid)', [
                new RuleNode('.child', []),
                new DeclarationNode('color', new StringNode('red')),
            ]),
            '.parent',
        );

        $media = $this->selector->normalizeBubblingNodeForSelector(
            new DirectiveNode('media', 'screen', [
                new RuleNode('&:hover', []),
                new DeclarationNode('color', new StringNode('red')),
            ], true),
            '.parent',
        );

        expect($supports)->toBeInstanceOf(SupportsNode::class)
            ->and($supports->body[0])->toBeInstanceOf(RuleNode::class)
            ->and($supports->body[0]->selector)->toBe('.parent .child')
            ->and($supports->body[1])->toBeInstanceOf(RuleNode::class)
            ->and($supports->body[1]->selector)->toBe('.parent')
            ->and($media)->toBeInstanceOf(DirectiveNode::class)
            ->and($media->body[0])->toBeInstanceOf(RuleNode::class)
            ->and($media->body[0]->selector)->toBe('.parent:hover')
            ->and($media->body[1])->toBeInstanceOf(RuleNode::class)
            ->and($media->body[1]->selector)->toBe('.parent');
    });

    it('does not attach the parent selector for non-bubbling directive bodies', function () {
        $directive = $this->selector->normalizeBubblingNodeForSelector(
            new DirectiveNode('font-face', '', [
                new RuleNode('.child', []),
                new DeclarationNode('color', new StringNode('red')),
            ], true),
            '.parent',
        );

        expect($directive)->toBeInstanceOf(DirectiveNode::class)
            ->and($directive->body[0])->toBeInstanceOf(RuleNode::class)
            ->and($directive->body[0]->selector)->toBe('.child')
            ->and($directive->body[1])->toBeInstanceOf(RuleNode::class)
            ->and($directive->body[1]->selector)->toBe('.parent');
    });

    it('leaves non-directive non-supports bubbling nodes unchanged', function () {
        $node = new AtRootNode([]);

        expect($this->selector->normalizeBubblingNodeForSelector($node, '.parent'))->toBe($node);
    });
});
