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
use Bugo\SCSS\Services\ExtendsResolver;
use Bugo\SCSS\Services\Text;
use Bugo\SCSS\Utils\SelectorTokenizer;
use Tests\ReflectionAccessor;

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

        $text = new Text($parser, $evaluateValue, $format);

        $this->resolver = new ExtendsResolver(
            $this->ctx,
            $text,
            new SelectorTokenizer(),
            $evaluateValue,
            fn(string $condition, Environment $env): bool => $this->conditionResults[$condition] ?? false,
            function (AstNode $node, Environment $env): bool {
                if (! $node instanceof StringNode || $node->value !== 'tick') {
                    return false;
                }

                $value = $env->getCurrentScope()->getAstVariable('i');

                if ($value instanceof NumberNode) {
                    $this->iterationValues[] = $value->value;
                }

                return true;
            },
            static fn(AstNode $node): array => [],
            static function (array $variables, AstNode $item, Environment $env): void {},
            $format,
        );

        $this->accessor = new ReflectionAccessor($this->resolver);
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
        $assigned = [];

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
                static fn(AstNode $node, Environment $env): AstNode => $node,
                static fn(AstNode $node, Environment $env): string => $node instanceof StringNode ? $node->value : '',
            ),
            new SelectorTokenizer(),
            static fn(AstNode $node, Environment $env): AstNode => $node,
            static fn(string $condition, Environment $env): bool => false,
            static fn(AstNode $node, Environment $env): bool => false,
            static fn(AstNode $node): array => [new StringNode('first'), new StringNode('second')],
            function (array $variables, AstNode $item, Environment $env) use (&$assigned): void {
                if ($item instanceof StringNode) {
                    $assigned[] = $item->value;
                    $env->getCurrentScope()->setVariableLocal($variables[0] ?? 'item', $item);
                }
            },
            static fn(AstNode $node, Environment $env): string => $node instanceof StringNode ? $node->value : '',
        );

        $resolver->collectExtends(
            new EachNode(['item'], new StringNode('list'), [
                new RuleNode('.item', [new ExtendNode('%item-target')]),
            ]),
            new Environment(),
        );

        expect($assigned)->toBe(['first', 'second'])
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

    it('formats non-number loop boundaries and rejects invalid formatted values', function () {
        $env = new Environment();

        expect($this->accessor->callMethod('toLoopNumber', [new StringNode('12.5'), $env]))
            ->toBe(12.5)
            ->and(fn() => $this->accessor->callMethod('toLoopNumber', [new StringNode('oops'), $env]))
            ->toThrow(InvalidLoopBoundaryException::class);
    });

    it('ignores empty extend registrations and empty extend target lists', function () {
        $this->resolver->registerExtend('', '.source');
        $this->resolver->registerExtend('.target', '');

        expect($this->ctx->outputState->extends->extendMap)->toBe([])
            ->and($this->resolver->extractSimpleExtendTargetSelectors('   '))->toBe([]);
    });

    it('allows empty simple targets and rejects complex extend selectors', function () {
        expect($this->accessor->callMethod('assertSimpleExtendTargetSelector', ['   ']))->toBeNull()
            ->and(fn() => $this->resolver->extractSimpleExtendTargetSelectors('.foo > .bar'))
            ->toThrow(SassErrorException::class, 'Complex selectors may not be extended. Use a simple selector target in @extend.');
    });

    it('returns null for empty structured replacement targets and short fallback matches', function () {
        expect($this->accessor->callMethod('replaceExtendTargetInStructuredSelectorPart', ['.foo', '', '.bar']))
            ->toBeNull()
            ->and($this->accessor->callMethod('replaceExtendTargetInSelectorPartFallback', ['.a', '.ab', '.x']))
            ->toBeNull();
    });

    it('returns null when fallback replacement never finds a valid boundary or weaving cannot apply', function () {
        expect($this->accessor->callMethod('replaceExtendTargetInSelectorPartFallback', ['.foobar', 'bar', '.baz']))
            ->toBeNull()
            ->and($this->accessor->callMethod('weaveFallbackExtendedSelector', ['.parent', '.child', '.scope .item']))
            ->toBeNull();
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
        // .a extended by .b and .c; .c also extended by .b
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

    it('returns the matching if body or else body from resolveIfBranch', function () {
        $env = new Environment();

        $this->conditionResults['if-true'] = true;
        $this->conditionResults['if-false'] = false;

        $ifBodyNode   = new RuleNode('.if-body', []);
        $elseBodyNode = new RuleNode('.else-body', []);

        $matching = $this->accessor->callMethod('resolveIfBranch', [
            new IfNode('if-true', [$ifBodyNode], [new ElseIfNode('elseif', [new RuleNode('.elseif', [])])], [$elseBodyNode]),
            $env,
        ]);

        $fallback = $this->accessor->callMethod('resolveIfBranch', [
            new IfNode('if-false', [$ifBodyNode], [new ElseIfNode('elseif-false', [new RuleNode('.elseif', [])])], [$elseBodyNode]),
            $env,
        ]);

        expect($matching)->toBe([$ifBodyNode])
            ->and($fallback)->toBe([$elseBodyNode]);
    });
});
