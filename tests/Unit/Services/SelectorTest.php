<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Utils\SelectorTokenizer;
use Tests\ReflectionAccessor;
use Tests\RuntimeFactory;

describe('Selector service', function () {
    beforeEach(function () {
        $this->runtime  = RuntimeFactory::createRuntime();
        $this->selector = $this->runtime->selector();
        $this->ctx      = (new ReflectionAccessor($this->runtime))->getProperty('ctx');
        $this->accessor = new ReflectionAccessor($this->selector);
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
                ['type' => 'directive'],
                ['type' => 'Media', 'name' => 'Screen', 'prelude' => '  ignored  '],
                ['type' => 'directive', 'name' => 'Screen', 'prelude' => '  (MIN-WIDTH: 10PX)  '],
                ['type' => 'supports', 'condition' => '  display:grid  '],
                ['type' => 'supports'],
            ]);

            expect($this->selector->getCurrentAtRuleStack($env))->toBe([
                ['type' => 'directive', 'name' => 'screen', 'prelude' => '(MIN-WIDTH: 10PX)'],
                ['type' => 'supports', 'condition' => 'display:grid'],
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
            $this->ctx->outputState->deferredAtRuleStack = [[
                ['levels' => 1, 'chunk' => ''],
            ]];

            expect($this->selector->drainDeferredAtRuleEscapes())->toBe([])
                ->and($this->ctx->outputState->deferredAtRuleStack)->toBe([]);
        });

        it('moves deeper deferred chunks to the parent stack level', function () {
            $this->ctx->outputState->deferredAtRuleStack = [
                [],
                [
                    ['levels' => 2, 'chunk' => 'nested'],
                ],
            ];

            $result = $this->selector->drainDeferredAtRuleEscapes();

            expect($result)->toBe([])
                ->and($this->ctx->outputState->deferredAtRuleStack)->toBe([
                    [
                        ['levels' => 1, 'chunk' => 'nested'],
                    ],
                ]);
        });

        it('returns deferred chunks outside when no parent at-rule level exists', function () {
            $this->ctx->outputState->deferredAtRuleStack = [[
                ['levels' => 2, 'chunk' => 'outside'],
            ]];

            expect($this->selector->drainDeferredAtRuleEscapes())->toBe(['outside'])
                ->and($this->ctx->outputState->deferredAtRuleStack)->toBe([]);
        });
    });

    describe('parent selector helpers', function () {
        it('returns null when current parent selector is not a string node', function () {
            $env = new Environment();
            $env->getCurrentScope()->setVariableLocal('__parent_selector', 'invalid');

            expect($this->selector->getCurrentParentSelector($env))->toBeNull();
        });
    });

    describe('nested property parsing', function () {
        it('accepts names with a leading hyphen and rejects invalid characters', function () {
            expect($this->selector->parseNestedPropertyBlockSelector('-foo: bar'))->toBe([
                'property' => '-foo',
                'value' => 'bar',
            ])->and($this->selector->parseNestedPropertyBlockSelector('foo!: bar'))->toBeNull()
                ->and($this->selector->parseNestedPropertyBlockSelector('-: bar'))->toBeNull();
        });
    });

    describe('compileNestedPropertyBlockChildren()', function () {
        beforeEach(function () {
            $this->assignedModuleVar = false;

            $this->testSelector = new Selector(
                $this->ctx,
                $this->runtime->render(),
                $this->runtime->text(),
                new SelectorTokenizer(),
                $this->runtime->dispatcher(),
                function ($node, Environment $env) {
                    if ($node instanceof StringNode && $node->value === 'nullish') {
                        return new StringNode('nullish');
                    }

                    return $node;
                },
                function (ModuleVarDeclarationNode $node, Environment $env): void {
                    $this->assignedModuleVar = true;
                },
                static fn($value): bool => $value instanceof StringNode && $value->value === 'nullish',
                static fn(string $property): bool => $property === 'border-color',
                static fn($value) => new StringNode('compressed'),
                static fn($node, Environment $env): string => $node instanceof StringNode ? $node->value : ''
            );
        });

        it('applies variable and module declarations without producing output', function () {
            $env = new Environment();

            $result = $this->testSelector->compileNestedPropertyBlockChildren([
                new VariableDeclarationNode('local-var', new StringNode('set')),
                new ModuleVarDeclarationNode('theme', 'accent', new StringNode('x')),
            ], $env, 0, 'border');

            expect($result)->toBe('')
                ->and($env->getCurrentScope()->getVariable('local-var'))->toBeInstanceOf(StringNode::class)
                ->and($this->assignedModuleVar)->toBeTrue();
        });

        it('skips null values and compresses named colors when required', function () {
            $env = new Environment();

            $result = $this->testSelector->compileNestedPropertyBlockChildren([
                new DeclarationNode('style', new StringNode('nullish')),
                new DeclarationNode('color', new StringNode('red')),
            ], $env, 0, 'border');

            expect($result)->toBe('border-color: compressed;');
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

            expect($result)->toBe("border: solid;\nborder-accent-shade: blue;");
        });
    });

    describe('internal helpers', function () {
        it('returns empty string when normalizing empty at-rule text', function () {
            expect($this->accessor->callMethod('normalizeAtRuleText', ['']))->toBe('');
        });

        it('returns original stack when at-root query rules normalize to an empty list', function () {
            $stack = [['type' => 'supports', 'condition' => '(display: grid)']];

            expect($this->accessor->callMethod('filterAtRootStackByQuery', [$stack, 'with', [' ', '']]))
                ->toBe($stack);
        });

        it('matches supports entries for at-root query rules', function () {
            $entry = ['type' => 'supports', 'condition' => '(display: grid)'];

            expect($this->accessor->callMethod('matchesAtRootQueryRule', [$entry, ['supports']]))->toBeTrue();
        });

        it('handles at-root rule matching and rule context flags', function () {
            expect($this->accessor->callMethod('matchesAtRootQueryRule', [
                ['type' => 'other'],
                ['supports'],
            ]))->toBeFalse()
                ->and($this->accessor->callMethod('matchesAtRootQueryRule', [
                    ['type' => 'directive', 'name' => 'media'],
                    ['media'],
                ]))->toBeTrue()
                ->and($this->accessor->callMethod('matchesAtRootQueryRule', [
                    ['type' => 'directive', 'name' => 'font-face'],
                    ['supports'],
                ]))->toBeFalse()
                ->and($this->accessor->callMethod('shouldKeepAtRootRuleContext', ['with', [' ', '']]))->toBeFalse()
                ->and($this->accessor->callMethod('shouldKeepAtRootRuleContext', ['invalid', ['rule']]))->toBeFalse();
        });

        it('normalizes at-root children and wraps nodes with at-rule stack entries', function () {
            $rule = new RuleNode('.child', []);
            $atRoot = new AtRootNode([]);
            $decl = new DeclarationNode('color', new StringNode('red'));

            $normalizedRule = $this->accessor->callMethod('normalizeAtRootChild', [$rule, '.parent', true]);
            $normalizedAtRoot = $this->accessor->callMethod('normalizeAtRootChild', [$atRoot, '.parent', true]);
            $wrappedDecl = $this->accessor->callMethod('normalizeAtRootChild', [$decl, '.parent', true]);
            $wrappedStack = $this->accessor->callMethod('wrapNodeWithAtRuleStack', [$decl, [
                ['type' => 'supports', 'condition' => '(display: grid)'],
            ]]);

            expect($normalizedRule)->toBe($rule)
                ->and($normalizedAtRoot)->toBe($atRoot)
                ->and($wrappedDecl)->toBeInstanceOf(RuleNode::class)
                ->and($wrappedDecl->selector)->toBe('.parent')
                ->and($wrappedStack)->toBeInstanceOf(SupportsNode::class)
                ->and($wrappedStack->condition)->toBe('(display: grid)')
                ->and($wrappedStack->body[0])->toBe($decl);
        });

        it('normalizes bubbling children and parent selector attachment decisions', function () {
            $rule = new RuleNode('.child', []);
            $unchangedRule = $this->accessor->callMethod('normalizeBubblingChild', [$rule, '.parent', false]);
            $wrappedDeclaration = $this->accessor->callMethod('normalizeBubblingChild', [
                new DeclarationNode('color', new StringNode('red')),
                '.parent',
                true,
            ]);

            expect($unchangedRule)->toBe($rule)
                ->and($wrappedDeclaration)->toBeInstanceOf(RuleNode::class)
                ->and($wrappedDeclaration->selector)->toBe('.parent')
                ->and($this->accessor->callMethod('shouldAttachParentSelectorToBubbledBody', [
                    new StringNode('plain'),
                ]))->toBeFalse();
        });
    });
});
