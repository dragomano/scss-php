<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Builtins\Color\Conversion\HexColorConverter;
use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Exceptions\SassThrowable;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StatementNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Style;
use Bugo\SCSS\Utils\NameHelper;
use Bugo\SCSS\Utils\NameNormalizer;
use Bugo\SCSS\Values\SassCalculation;
use Bugo\SCSS\Values\SassMap;
use Bugo\SCSS\Values\SassValue;
use Closure;

use function array_slice;
use function count;
use function implode;
use function in_array;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

final readonly class Evaluator
{
    private ArithmeticEvaluator $arithmetic;

    private StringConcatenationEvaluator $concatenation;

    private HexColorConverter $hexColorConverter;

    private UserFunctionExecutor $userFunction;

    private CalculationEvaluator $calculation;

    private ConditionalEvaluator $conditional;

    private CssArgumentEvaluator $cssArgument;

    private CallArgumentResolver $callArguments;

    /**
     * @param Closure(ModuleVarDeclarationNode, Environment): void $assignModuleVariable
     * @param Closure(string, AstNode, Environment, AstNode|null): void $handleDiagnosticDirective
     */
    public function __construct(
        private CompilerContext $ctx,
        private CompilerOptions $options,
        private ParserInterface $parser,
        private Selector $selector,
        private Text $text,
        private Condition $condition,
        private Closure $assignModuleVariable,
        private Closure $handleDiagnosticDirective
    ) {
        $this->hexColorConverter = new HexColorConverter();
        $this->arithmetic        = new ArithmeticEvaluator();

        $this->concatenation = new StringConcatenationEvaluator(
            fn(AstNode $node, Environment $env): string => $this->format($node, $env)
        );

        $this->userFunction = new UserFunctionExecutor(
            $this->condition,
            fn(AstNode $node, Environment $env): AstNode => $this->evaluateValue($node, $env),
            fn(AstNode $node, Environment $env): bool => $this->applyVariableDeclaration($node, $env),
            fn(AstNode $value): array => $this->eachIterableItems($value),
            fn(array $vars, AstNode $item, Environment $env) => $this->assignEachVariables($vars, $item, $env),
            fn(AstNode $node, Environment $env): AstNode => $this->evaluateValueWithSlashDivision($node, $env),
            $this->handleDiagnosticDirective
        );

        $this->calculation = new CalculationEvaluator(
            fn(AstNode $node, Environment $env): string => $this->format($node, $env),
            fn(ListNode $node, bool $strict, Environment $env): ?AstNode => $this->evaluateArithmeticList(
                $node,
                $strict,
                $env
            ),
            /** @param callable(AstNode): string $innerFormat */
            fn(AstNode $node, callable $innerFormat): SassValue => $this->ctx->valueFactory->fromAst(
                $node,
                $innerFormat
            )
        );

        $this->conditional = new ConditionalEvaluator(
            $this->condition,
            $this->text,
            fn(AstNode $node, Environment $env): AstNode => $this->evaluateValue($node, $env),
            fn(AstNode $node, Environment $env): string => $this->format($node, $env),
            fn(ListNode $node, Environment $env): ?AstNode => $this->evaluateComparisonList($node, $env),
            fn(bool $value): AstNode => $this->createBooleanNode($value),
            fn(): AstNode => $this->ctx->valueFactory->createNullNode()
        );

        $this->cssArgument = new CssArgumentEvaluator(
            fn(AstNode $node, Environment $env): AstNode => $this->evaluateValue($node, $env),
            fn(string $name, Environment $env): AstNode => $this->resolveVariable($name, $env),
            fn(string $name, array $args): array => $this->calculation->normalizeArguments($name, $args)
        );

        $this->callArguments = new CallArgumentResolver(
            $this->parser,
            $this->cssArgument,
            $this->userFunction,
            fn(AstNode $node, Environment $env): AstNode => $this->evaluateValue($node, $env)
        );
    }

    public function interpolateText(string $text, Environment $env): string
    {
        return $this->text->interpolateText($text, $env);
    }

    public function evaluateValue(AstNode $node, Environment $env, bool $skipSlashArithmetic = false): AstNode
    {
        if ($node instanceof VariableReferenceNode) {
            return $this->evaluateValue($this->resolveVariable($node->name, $env), $env, $skipSlashArithmetic);
        }

        if (
            $node instanceof BooleanNode
            || $node instanceof ColorNode
            || $node instanceof MixinRefNode
            || $node instanceof NullNode
            || $node instanceof NumberNode
        ) {
            return $node;
        }

        if ($node instanceof StringNode) {
            if (! $node->quoted && $node->value === '&') {
                $selectorValue = $this->getCurrentParentSelector($env);

                if ($selectorValue !== null) {
                    return $selectorValue;
                }

                return $this->ctx->valueFactory->createNullNode();
            }

            if (! str_contains($node->value, '#{')) {
                return $node;
            }

            return new StringNode(
                $this->text->replaceInterpolations($node->value, $env),
                $node->quoted
            );
        }

        if ($node instanceof ListNode) {
            $items   = null;
            $itemIdx = 0;

            foreach ($node->items as $item) {
                if ($item instanceof ListNode
                    && $item->separator === 'space'
                    && count($item->items) === 3
                ) {
                    [$itemFirst, $itemMid, $itemLast] = $item->items;

                    if ($itemFirst instanceof NumberNode
                        && $itemMid instanceof StringNode
                        && $itemMid->value === '/'
                        && $itemLast instanceof NumberNode
                    ) {
                        if ($items !== null) {
                            $items[] = $item;
                        }

                        $itemIdx++;

                        continue;
                    }
                }

                $evaluatedItem = $this->evaluateValue($item, $env, $skipSlashArithmetic);

                if ($items !== null) {
                    $items[] = $evaluatedItem;
                } elseif ($evaluatedItem !== $item) {
                    $items   = $itemIdx > 0 ? array_slice($node->items, 0, $itemIdx) : [];
                    $items[] = $evaluatedItem;
                }

                $itemIdx++;
            }

            $evaluated = $items !== null
                ? new ListNode($items, $node->separator, $node->bracketed)
                : $node;

            $logical = $this->conditional->evaluateLogicalList($evaluated, $env);

            if ($logical !== null) {
                return $logical;
            }

            if (! $skipSlashArithmetic) {
                $arithmetic = $this->evaluateArithmeticList($evaluated, true, $env);

                if ($arithmetic !== null) {
                    return $arithmetic;
                }
            }

            $concatenation = $this->evaluateStringConcatenationList($evaluated, $env);

            return $concatenation ?? $evaluated;
        }

        if ($node instanceof ArgumentListNode) {
            $items   = null;
            $itemIdx = 0;

            foreach ($node->items as $item) {
                $evaluatedItem = $this->evaluateValue($item, $env);

                if ($items !== null) {
                    $items[] = $evaluatedItem;
                } elseif ($evaluatedItem !== $item) {
                    $items   = $itemIdx > 0 ? array_slice($node->items, 0, $itemIdx) : [];
                    $items[] = $evaluatedItem;
                }

                $itemIdx++;
            }

            $keywords = null;

            foreach ($node->keywords as $name => $keywordValue) {
                $evaluatedKeywordValue = $this->evaluateValue($keywordValue, $env);

                if ($keywords !== null) {
                    $keywords[$name] = $evaluatedKeywordValue;
                } elseif ($evaluatedKeywordValue !== $keywordValue) {
                    $keywords        = $node->keywords;
                    $keywords[$name] = $evaluatedKeywordValue;
                }
            }

            if ($items === null && $keywords === null) {
                return $node;
            }

            return new ArgumentListNode(
                $items ?? $node->items,
                $node->separator,
                $node->bracketed,
                $keywords ?? $node->keywords
            );
        }

        if ($node instanceof MapNode) {
            // Lazily allocate $pairs only on first change
            $pairs   = null;
            $pairIdx = 0;

            foreach ($node->pairs as $pair) {
                $evaluatedKey   = $this->evaluateValue($pair['key'], $env);
                $evaluatedValue = $this->evaluateValue($pair['value'], $env);

                if ($pairs !== null) {
                    $pairs[] = ['key' => $evaluatedKey, 'value' => $evaluatedValue];
                } elseif ($evaluatedKey !== $pair['key'] || $evaluatedValue !== $pair['value']) {
                    $pairs   = $pairIdx > 0 ? array_slice($node->pairs, 0, $pairIdx) : [];
                    $pairs[] = ['key' => $evaluatedKey, 'value' => $evaluatedValue];
                }

                $pairIdx++;
            }

            if ($pairs === null) {
                return $node;
            }

            return new MapNode($pairs);
        }

        if ($node instanceof NamedArgumentNode) {
            $value = $this->evaluateValue($node->value, $env);

            if ($value === $node->value) {
                return $node;
            }

            return new NamedArgumentNode($node->name, $value);
        }

        if ($node instanceof FunctionNode) {
            if ($node->capturedScope !== null && $node->arguments === []) {
                return $node;
            }

            $currentScope     = $env->getCurrentScope();
            $userFunction     = null;
            $userFunctionName = $node->name;

            if (NameHelper::hasNamespace($node->name)) {
                $parts = NameHelper::splitQualifiedName($node->name);

                $namespace    = $parts['namespace'];
                $functionName = $parts['member'] ?? '';
                $moduleScope  = $currentScope->getModule($namespace);

                if ($functionName !== '' && $moduleScope !== null) {
                    $userFunction = $moduleScope->findFunction($functionName)?->definition;
                }

                if ($userFunction !== null) {
                    $userFunctionName  = $functionName;
                }
            } elseif ($node->capturedScope !== null) {
                $userFunction = $node->capturedScope->findFunction($node->name)?->definition;
            }

            if ($userFunction === null) {
                $userFunction = $currentScope->findFunction($node->name)?->definition;
            }

            if ($userFunction !== null) {
                [$positionalArguments, $namedArguments] = $this->resolveCallArguments(
                    $node->arguments,
                    $env
                );

                if (++$this->ctx->moduleState->callDepth > 100) {
                    $this->ctx->moduleState->callDepth--;

                    throw new MaxIterationsExceededException('@function');
                }

                try {
                    return $this->userFunction->executeDefinition(
                        $userFunctionName,
                        $userFunction,
                        $positionalArguments,
                        $namedArguments,
                        $env
                    );
                } finally {
                    $this->ctx->moduleState->callDepth--;
                }
            }

            $arguments = $this->expandCallArguments($node->arguments, $env);
            $arguments = $this->calculation->normalizeArguments($node->name, $arguments);

            if (strtolower($node->name) === 'if' && count($arguments) >= 2 && ! $node->modernSyntax) {
                $rawCond = $node->arguments[0] ?? $arguments[0];
                $condStr = $rawCond instanceof VariableReferenceNode
                    ? '$' . $rawCond->name
                    : $this->format($arguments[0], $env);

                $suggestion = 'if(sass(' . $condStr . '): ' . $this->format($arguments[1], $env);

                if (isset($arguments[2])) {
                    $suggestion .= '; else: ' . $this->format($arguments[2], $env);
                }

                $suggestion .= ')';

                ($this->handleDiagnosticDirective)(
                    'warn',
                    new StringNode(implode(' ', [
                        'The Sass if() syntax is deprecated in favor of the modern CSS syntax.',
                        'Use `' . $suggestion . '` instead.',
                    ])),
                    $env,
                    $node
                );
            }

            $inlineIf  = $this->conditional->evaluateInlineIfFunction($node->name, $arguments, $env);

            if ($inlineIf !== null) {
                return $inlineIf;
            }

            $urlFunction = $this->conditional->evaluateSpecialUrlFunction($node->name, $arguments, $env);

            if ($urlFunction !== null) {
                return $urlFunction;
            }

            $simplifiedFunction = $this->calculation->simplifyFunction($node->name, $arguments, $env);

            $context = new BuiltinCallContext(
                $env,
                $this->ctx->functionRegistry,
                fn(string $msg) => ($this->handleDiagnosticDirective)('warn', new StringNode($msg), $env, $node),
                null,
                $node->arguments,
                $node->line
            );

            $preferBuiltin = in_array(strtolower($node->name), ['max', 'min', 'clamp'], true);

            if (! $preferBuiltin && $simplifiedFunction !== null) {
                return $simplifiedFunction;
            }

            $resolved = $this->ctx->functionRegistry->tryCall($node->name, $arguments, $context);

            if ($resolved !== null) {
                if ($resolved instanceof FunctionNode && $resolved->name !== $node->name) {
                    return $this->evaluateValue($resolved, $env);
                }

                return $resolved;
            }

            if ($preferBuiltin && $simplifiedFunction !== null) {
                return $simplifiedFunction;
            }

            $fallbackArguments = $this->cssArgument->expandCssCallArguments($node->arguments, $env);

            $fallback = new FunctionNode(
                $node->name,
                $this->calculation->normalizeArguments($node->name, $fallbackArguments)
            );

            if ($this->options->style === Style::COMPRESSED) {
                $compressedColor = $this->hexColorConverter->tryConvert($fallback);

                if ($compressedColor !== null) {
                    return $compressedColor;
                }
            }

            return $fallback;
        }

        return $node;
    }

    public function evaluateDeclarationValue(AstNode $value, string $property, Environment $env): AstNode
    {
        if (
            ! $this->shouldUseCompactSlashSpacing($property)
            || ! ($value instanceof ListNode)
            || ! $this->containsSlashToken($value)
        ) {
            return $this->evaluateValue($value, $env);
        }

        $items   = [];
        $changed = false;

        foreach ($value->items as $item) {
            $evaluatedItem = $this->evaluateValue($item, $env);

            if ($evaluatedItem !== $item) {
                $changed = true;
            }

            $items[] = $evaluatedItem;
        }

        return $changed
            ? new ListNode($items, $value->separator, $value->bracketed)
            : $value;
    }

    public function evaluateArithmeticList(ListNode $node, bool $strict, Environment $env): ?AstNode
    {
        $callback = $strict ? null : function (array $items) use ($env): ?string {
            /** @var array<int, AstNode> $items */
            return $this->calculation->detectUnsupportedOperation($items, $env);
        };

        return $this->arithmetic->evaluate($node, $strict, $callback);
    }

    public function shouldUseCompactSlashSpacing(string $property): bool
    {
        return in_array(strtolower($property), [
            'aspect-ratio',
            'font',
        ], true);
    }

    public function containsSlashToken(ListNode $value, bool $topLevelOnly = false): bool
    {
        if ($value->separator !== 'space' || count($value->items) < 3) {
            return false;
        }

        foreach ($value->items as $index => $item) {
            if ($topLevelOnly && $index % 2 !== 0) {
                continue;
            }

            if ($item instanceof StringNode && trim($item->value) === '/') {
                return true;
            }
        }

        return false;
    }

    public function isSassNullValue(AstNode $value): bool
    {
        return $value instanceof NullNode;
    }

    public function evaluateValueWithoutSlashArithmetic(AstNode $node, Environment $env): AstNode
    {
        return $this->evaluateValue($node, $env, true);
    }

    public function evaluateValueWithSlashDivision(AstNode $node, Environment $env): AstNode
    {
        $evaluated = $this->evaluateValue($node, $env);

        if ($evaluated instanceof ListNode
            && $evaluated->separator === 'space'
            && count($evaluated->items) === 3
        ) {
            [$evalFirst, $evalMid, $evalLast] = $evaluated->items;

            if ($evalFirst instanceof NumberNode
                && $evalMid instanceof StringNode
                && $evalMid->value === '/'
                && $evalLast instanceof NumberNode
            ) {
                return $this->arithmetic->applyOperator($evalFirst, '/', $evalLast);
            }
        }

        return $evaluated;
    }

    public function applyVariableDeclaration(AstNode $node, Environment $env): bool
    {
        if ($node instanceof VariableDeclarationNode) {
            $evaluatedValue = $this->evaluateValueWithSlashDivision($node->value, $env);
            $currentScope = $env->getCurrentScope();

            if ($node->global) {
                $moduleScopeTarget = $currentScope->getScopeVariable('__module_global_target');

                if ($moduleScopeTarget !== null && $moduleScopeTarget->hasVariable($node->name)) {
                    $moduleScopeTarget->setVariableLocal($node->name, $evaluatedValue, $node->default);

                    return true;
                }

                $currentScope->setVariable(
                    $node->name,
                    $evaluatedValue,
                    true,
                    $node->default
                );
            } else {
                $currentScope->setVariableLocal(
                    $node->name,
                    $evaluatedValue,
                    $node->default
                );
            }

            return true;
        }

        if ($node instanceof ModuleVarDeclarationNode) {
            ($this->assignModuleVariable)($node, $env);

            return true;
        }

        return false;
    }

    public function evaluateFunctionCondition(string $condition, Environment $env): bool
    {
        return $this->condition->evaluate($condition, $env);
    }

    /**
     * @return array<int, AstNode>
     */
    public function eachIterableItems(AstNode $value): array
    {
        if ($value instanceof ListNode || $value instanceof ArgumentListNode) {
            return $value->items;
        }

        if ($value instanceof MapNode) {
            $items = [];

            foreach ($value->pairs as $pair) {
                $items[] = new ListNode([$pair['key'], $pair['value']], 'space');
            }

            return $items;
        }

        return [$value];
    }

    /**
     * @param array<int, string> $variables
     */
    public function assignEachVariables(array $variables, AstNode $item, Environment $env): void
    {
        if (count($variables) === 1) {
            $env->getCurrentScope()->setVariable($variables[0], $item);

            return;
        }

        $values = [$item];

        if ($item instanceof ListNode || $item instanceof ArgumentListNode) {
            $values = $item->items;
        }

        foreach ($variables as $index => $name) {
            $env->getCurrentScope()->setVariable(
                $name,
                $values[$index] ?? $this->ctx->valueFactory->createNullNode()
            );
        }
    }

    public function shouldCompressNamedColorForProperty(string $property): bool
    {
        return $this->options->style === Style::COMPRESSED
            && ! str_starts_with($property, '--');
    }

    public function compressNamedColorsForOutput(AstNode $value): AstNode
    {
        return $this->cssArgument->compressNamedColorsForOutput($value);
    }

    public function tryEvaluateFormattedDeclarationExpression(
        string $property,
        AstNode $value,
        Environment $env
    ): ?AstNode {
        if ($value instanceof FunctionNode && strtolower($value->name) === 'calc') {
            return null;
        }

        if ($this->shouldUseCompactSlashSpacing($property)) {
            return null;
        }

        $formattedValue = $this->format($value, $env);

        if (! str_contains($formattedValue, '(') || ! str_contains($formattedValue, '/')) {
            return null;
        }

        try {
            $ast = $this->parser->parse(".__tmp__ { __tmp__: $formattedValue; }");

            $firstChild       = $ast->children[0] ?? null;
            $firstDeclaration = $firstChild instanceof RuleNode
                ? $firstChild->children[0] ?? null
                : null;

            if (! $firstDeclaration instanceof DeclarationNode) {
                return null;
            }

            $evaluatedValue = $this->evaluateValue($firstDeclaration->value, $env);

            if ($evaluatedValue instanceof ListNode) {
                $strictArithmetic = $this->evaluateArithmeticList($evaluatedValue, true, $env);

                if ($strictArithmetic instanceof AstNode) {
                    return $strictArithmetic;
                }
            }

            return $evaluatedValue !== $value ? $evaluatedValue : null;
        } catch (SassThrowable) {
            // Reparsing the formatted value failed (e.g. invalid slash expression).
            // Return null to signal "no rewrite needed" — the caller keeps the original value.
            return null;
        }
    }

    public function normalizeDeclarationSlashSpacing(string $property, string $value): string
    {
        if (! $this->shouldUseCompactSlashSpacing($property)) {
            return $value;
        }

        return str_replace(' / ', '/', $value);
    }

    public function createBooleanNode(bool $value): AstNode
    {
        return $this->ctx->valueFactory->createBooleanNode($value);
    }

    public function format(AstNode $value, Environment $env): string
    {
        if ($value instanceof VariableReferenceNode) {
            $valueNode = $this->resolveVariable($value->name, $env);

            return $this->format($this->evaluateValue($valueNode, $env), $env);
        }

        if ($value instanceof BooleanNode) {
            return $value->value ? 'true' : 'false';
        }

        if ($value instanceof NullNode) {
            return '';
        }

        if ($value instanceof NumberNode || $value instanceof ColorNode) {
            return $this->ctx->valueFactory->fromAst($value)->toCss();
        }

        if ($value instanceof StringNode) {
            if ($value->value === '&') {
                $selectorNode = $this->getCurrentParentSelector($env);

                if ($selectorNode !== null) {
                    return $selectorNode->value;
                }
            }

            return $this->ctx->valueFactory->fromAst($value)->toCss();
        }

        if ($value instanceof ListNode || $value instanceof ArgumentListNode) {
            return $this->calculation->formatListValue($value->items, $value->separator, $value->bracketed, $env);
        }

        if ($value instanceof MapNode) {
            $pairs = [];

            foreach ($value->pairs as $pair) {
                $pairs[] = [
                    'key'   => $this->calculation->toSassValue($pair['key'], $env),
                    'value' => $this->calculation->toSassValue($pair['value'], $env),
                ];
            }

            return (string) new SassMap($pairs);
        }

        if ($value instanceof FunctionNode) {
            if ($this->options->style === Style::COMPRESSED) {
                $compressedColor = $this->hexColorConverter->tryConvert($value);

                if ($compressedColor !== null) {
                    return $this->ctx->valueFactory->fromAst($compressedColor)->toCss();
                }
            }

            if (SassCalculation::isCalculationFunctionName($value->name)) {
                return $this->calculation->formatCalculationFunction($value, $env);
            }

            $formatted = $this->ctx->valueFactory->fromAst(
                $value,
                fn(AstNode $inner): string => $this->format($inner, $env)
            )->toCss();

            if (in_array($value->name, ['rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'color'], true)) {
                return $this->ctx->colorSerializer->serialize($formatted, $this->options->outputHexColors);
            }

            return $formatted;
        }

        if ($value instanceof MixinRefNode) {
            return $value->name;
        }

        if ($value instanceof NamedArgumentNode) {
            return '$' . $value->name . ': ' . $this->format($value->value, $env);
        }

        return '';
    }

    public function evaluateComparisonList(ListNode $list, Environment $env): ?AstNode
    {
        if ($list->separator !== 'space' || count($list->items) < 3) {
            return null;
        }

        $comparisonIndex = null;
        $operator        = null;

        foreach ($list->items as $index => $item) {
            if (
                $item instanceof StringNode
                && in_array(trim($item->value), ['==', '!=', '>=', '<=', '>', '<'], true)
            ) {
                $comparisonIndex = $index;
                $operator        = trim($item->value);

                break;
            }
        }

        if (in_array($comparisonIndex, [null, 0, count($list->items) - 1], true)) {
            return null;
        }

        if ($operator === null) {
            return null;
        }

        $leftItems  = array_slice($list->items, 0, $comparisonIndex);
        $rightItems = array_slice($list->items, $comparisonIndex + 1);

        $left   = $this->evaluateSpaceSeparatedItems($leftItems, $env);
        $right  = $this->evaluateSpaceSeparatedItems($rightItems, $env);
        $result = $this->condition->compare($left, $operator, $right, $env);

        return $this->createBooleanNode($result);
    }

    public function evaluateStringConcatenationList(ListNode $list, ?Environment $env = null): ?AstNode
    {
        return $this->concatenation->evaluate($list, $env);
    }

    /**
     * @return array<int, AstNode>
     */
    public function parseContentCallArguments(string $prelude): array
    {
        return $this->callArguments->parseContentCallArguments($prelude);
    }

    /**
     * @param mixed $value
     * @return array<int, AstNode>
     */
    public function extractAstNodes(mixed $value): array
    {
        return $this->callArguments->extractAstNodes($value);
    }

    /**
     * @param mixed $value
     * @return array<int, ArgumentNode>
     */
    public function extractArgumentNodes(mixed $value): array
    {
        return $this->callArguments->extractArgumentNodes($value);
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array{0: array<int, AstNode>, 1: array<string, AstNode>}
     */
    public function resolveCallArguments(array $arguments, Environment $env): array
    {
        return $this->callArguments->resolveCallArguments($arguments, $env);
    }

    /**
     * @param array<int, ArgumentNode> $parameters
     * @param array<int, AstNode> $resolvedPositional
     * @param array<string, AstNode> $resolvedNamed
     */
    public function bindParametersToCurrentScope(
        array $parameters,
        array $resolvedPositional,
        array $resolvedNamed,
        Scope $scope
    ): void {
        $this->callArguments->bindParametersToCurrentScope($parameters, $resolvedPositional, $resolvedNamed, $scope);
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function expandCallArguments(array $arguments, Environment $env): array
    {
        return $this->callArguments->expandCallArguments($arguments, $env);
    }

    public function isBubblingAtRuleNode(AstNode $node): bool
    {
        return $this->selector->isBubblingAtRuleNode($node);
    }

    public function normalizeBubblingNodeForSelector(StatementNode $node, string $selector): StatementNode
    {
        return $this->selector->normalizeBubblingNodeForSelector($node, $selector);
    }

    protected function getCurrentParentSelector(Environment $env): ?StringNode
    {
        return $env->getCurrentScope()->getStringVariable('__parent_selector');
    }

    /**
     * @param array<int, AstNode> $items
     */
    protected function evaluateSpaceSeparatedItems(array $items, Environment $env): AstNode
    {
        if (count($items) === 1) {
            return $this->evaluateValue($items[0], $env);
        }

        return $this->evaluateValue(new ListNode($items, 'space'), $env);
    }

    protected function resolveVariable(string $name, Environment $env): AstNode
    {
        $currentScope = $env->getCurrentScope();

        if (NameHelper::hasNamespace($name)) {
            $parts = NameHelper::splitQualifiedName($name);

            if ($parts['member'] === null) {
                throw UndefinedSymbolException::variable($name);
            }

            $moduleName  = $parts['namespace'];
            $varName     = $parts['member'];
            $moduleScope = $currentScope->getModule($moduleName);

            if (! $moduleScope) {
                throw ModuleResolutionException::notFound($moduleName);
            }

            if (NameNormalizer::isPrivate($varName)) {
                throw UndefinedSymbolException::variableInModule($moduleName, $varName);
            }

            $moduleValue = $moduleScope->getAstVariable($varName);

            if ($moduleValue === null) {
                throw UndefinedSymbolException::variableInModule($moduleName, $varName);
            }

            return $moduleValue;
        }

        $value = $currentScope->getAstVariable($name);

        if ($value === null) {
            throw UndefinedSymbolException::variable($name);
        }

        return $value;
    }
}
