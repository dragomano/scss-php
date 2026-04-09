<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\Exceptions\InvalidLoopBoundaryException;
use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ElseIfNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
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
                'target' => '%picked-target',
                'source' => '.picked',
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
});
