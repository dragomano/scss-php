<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Builtins\Color\Conversion\HexColorConverter;
use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Exceptions\DivisionByZeroException;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Exceptions\SassArgumentException;
use Bugo\SCSS\Exceptions\SassException;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StatementNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\ResolvedCallArguments;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyRegistry;
use Bugo\SCSS\Services\Evaluation\Strategy\ArgumentListNodeStrategy;
use Bugo\SCSS\Services\Evaluation\Strategy\DeprecatedExpressionStrategy;
use Bugo\SCSS\Services\Evaluation\Strategy\FunctionNodeStrategy;
use Bugo\SCSS\Services\Evaluation\Strategy\ListNodeStrategy;
use Bugo\SCSS\Services\Evaluation\Strategy\MapNodeStrategy;
use Bugo\SCSS\Services\Evaluation\Strategy\NamedArgumentNodeStrategy;
use Bugo\SCSS\Services\Evaluation\Strategy\PassthroughNodeStrategy;
use Bugo\SCSS\Services\Evaluation\Strategy\StringNodeStrategy;
use Bugo\SCSS\Services\Evaluation\Strategy\VariableReferenceStrategy;
use Bugo\SCSS\Services\Evaluation\ValueEvaluatorInterface;
use Bugo\SCSS\Style;
use Bugo\SCSS\Utils\NameHelper;
use Bugo\SCSS\Utils\NameNormalizer;
use Bugo\SCSS\Values\SassCalculation;
use Bugo\SCSS\Values\SassMap;
use LogicException;
use Psr\Log\LoggerInterface;

use function array_slice;
use function count;
use function in_array;
use function is_string;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trim;

final readonly class Evaluator implements AstValueEvaluatorInterface, AstValueFormatterInterface
{
    private ArithmeticEvaluator $arithmetic;

    private StringConcatenationEvaluator $concatenation;

    private HexColorConverter $hexColorConverter;

    private UserFunctionExecutor $userFunction;

    private CalculationEvaluator $calculation;

    private ConditionalEvaluator $conditional;

    private CssArgumentEvaluator $cssArgument;

    private CallableParameterBinder $parameterBinder;

    private VariableDeclarationApplierInterface $variableDeclarationApplier;

    private EachLoopBinderInterface $eachLoopBinder;

    private CallArgumentResolver $callArguments;

    private FunctionCallEvaluator $functionCalls;

    private EvaluationStrategyRegistry $registry;

    public function __construct(
        private CompilerContext $ctx,
        private CompilerOptions $options,
        private ParserInterface $parser,
        private Selector $selector,
        private Text $text,
        private Condition $condition,
        private ModuleVariableAssignerInterface $moduleVariableAssigner,
        private DiagnosticDirectiveHandlerInterface $diagnosticHandler,
        private LoggerInterface $logger,
    ) {
        $this->hexColorConverter = new HexColorConverter();
        $this->arithmetic        = new ArithmeticEvaluator();
        $this->parameterBinder   = new CallableParameterBinder();

        $this->concatenation              = $this->createStringConcatenationEvaluator();
        $this->variableDeclarationApplier = $this->createVariableDeclarationApplier();
        $this->eachLoopBinder             = $this->createEachLoopBinder();
        $this->userFunction               = $this->createUserFunctionExecutor();
        $this->calculation                = $this->createCalculationEvaluator();
        $this->conditional                = $this->createConditionalEvaluator();
        $this->cssArgument                = $this->createCssArgumentEvaluator();
        $this->callArguments              = $this->createCallArgumentResolver();

        $valueEvaluator     = $this->createEvaluationValueEvaluator();
        $this->functionCalls = $this->createFunctionCallEvaluator();
        $this->registry      = $this->createEvaluationStrategyRegistry($valueEvaluator);
    }

    public function interpolateText(string $text, Environment $env): string
    {
        return $this->text->interpolateText($text, $env);
    }

    public function evaluateValue(AstNode $node, Environment $env, bool $skipSlashArithmetic = false, ?EvaluationOptions $options = null): AstNode
    {
        return $this->registry->evaluate(
            $node,
            $env,
            $options
                ?? ($skipSlashArithmetic
                    ? EvaluationOptions::default()->withSkipSlashArithmetic()
                    : EvaluationOptions::default()),
        );
    }

    public function evaluate(AstNode $node, Environment $env): AstNode
    {
        return $this->evaluateValue($node, $env);
    }

    public function evaluateDeclarationValue(AstNode $value, string $property, Environment $env): AstNode
    {
        if ($this->shouldUseCompactSlashSpacing($property)) {
            return $this->evaluateValue($value, $env);
        }

        if ($value instanceof ListNode
            && $value->separator === '/'
            && count($value->items) === 3
        ) {
            return $this->evaluateValueWithSlashDivision($value, $env);
        }

        if ($value instanceof ListNode
            && $value->separator === 'space'
            && $this->isMultiSlashChain($value)
            && ($value->parenthesized > 0 || $this->containsVariableReference($value->items))
        ) {
            $evaluatedChain = $this->evaluateMultiSlashChain($value, $env);

            if ($evaluatedChain instanceof NumberNode) {
                return $evaluatedChain;
            }
        }

        if ($value instanceof ListNode
            && $value->separator === 'space'
            && $this->isSlashDivisionCandidate($value)
        ) {
            return $this->evaluateValueWithSlashDivision($value, $env);
        }

        $items = $value instanceof ListNode ? $value->items : [$value];

        foreach ($items as $item) {
            if ($item instanceof ListNode && $item->separator === 'space' && $this->containsSlashToken($item)) {
                return $this->evaluateValueWithSlashDivision($value, $env);
            }
        }

        return $this->evaluateValue($value, $env);
    }

    public function evaluateArithmeticList(ListNode $node, bool $strict, Environment $env, bool $insideCalc = false): ?AstNode
    {
        $callback = $strict ? null : function (array $items) use ($env): ?string {
            /** @var array<int, AstNode> $items */
            return $this->calculation->detectUnsupportedOperation($items, $env);
        };

        return $this->arithmetic->evaluate($node, $strict, $callback, $insideCalc);
    }

    public function shouldUseCompactSlashSpacing(string $property): bool
    {
        $lower = strtolower($property);

        return $lower === 'aspect-ratio' || $lower === 'font';
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

    public function evaluateValueWithSlashDivision(AstNode $node, Environment $env, ?EvaluationOptions $options = null): AstNode
    {
        $evaluated = $this->evaluateValue($node, $env, false, $options);

        if ($evaluated instanceof ListNode
            && ($evaluated->separator === 'space' || $evaluated->separator === '/')
            && count($evaluated->items) === 3
        ) {
            [$evalFirst, $evalMid, $evalLast] = $evaluated->items;

            if ($evalFirst instanceof NumberNode
                && $evalMid instanceof StringNode
                && $evalMid->value === '/'
                && $evalLast instanceof NumberNode
            ) {
                try {
                    return $this->arithmetic->applyOperator($evalFirst, '/', $evalLast);
                } catch (DivisionByZeroException) {
                    return $this->arithmetic->applyOperator($evalFirst, '/', $evalLast, true);
                }
            }

            if ($evalFirst instanceof NumberNode
                && $evalMid instanceof StringNode
                && $evalMid->value === '%'
                && $evalLast instanceof NumberNode
            ) {
                try {
                    return $this->arithmetic->applyOperator($evalFirst, '%', $evalLast);
                } catch (DivisionByZeroException) {
                    return $this->arithmetic->applyOperator($evalFirst, '%', $evalLast, true);
                } catch (IncompatibleUnitsException) {
                    return $evaluated;
                }
            }
        }

        return $evaluated;
    }

    public function applyVariableDeclaration(AstNode $node, Environment $env): bool
    {
        return $this->variableDeclarationApplier->apply($node, $env);
    }

    public function evaluateFunctionCondition(string $condition, Environment $env, ?int $line = null): bool
    {
        return $this->condition->evaluate($condition, $env, $line);
    }

    /**
     * @return array<int, AstNode>
     */
    public function eachIterableItems(AstNode $value): array
    {
        return $this->eachLoopBinder->items($value);
    }

    /**
     * @param array<int, string> $variables
     */
    public function assignEachVariables(array $variables, AstNode $item, Environment $env): void
    {
        $this->eachLoopBinder->assign($variables, $item, $env);
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

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function normalizeCalculationArguments(string $name, array $arguments): array
    {
        return $this->calculation->normalizeArguments($name, $arguments);
    }

    public function tryEvaluateFormattedDeclarationExpression(
        string $property,
        AstNode $value,
        Environment $env,
        ?string &$formattedValue = null,
    ): ?AstNode {
        if ($value instanceof FunctionNode && strtolower($value->name) === 'calc') {
            return null;
        }

        if ($value instanceof FunctionNode && in_array(strtolower($value->name), [
            'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'color', 'lab', 'lch', 'oklab', 'oklch',
        ], true)) {
            return null;
        }

        if ($this->shouldUseCompactSlashSpacing($property)) {
            return null;
        }

        $formattedValue = $this->format($value, $env);

        if (! str_contains($formattedValue, '(') || ! str_contains($formattedValue, '/')) {
            return null;
        }

        if (str_contains($formattedValue, 'url(')) {
            return null;
        }

        try {
            $valueNode = $this->parser->parseInlineExpression($formattedValue);

            if ($valueNode instanceof StringNode && $valueNode->value === $formattedValue) {
                return null;
            }

            $evaluatedValue = $this->evaluateValue($valueNode, $env);

            return $evaluatedValue !== $value ? $evaluatedValue : null;
        } catch (SassException|SassArgumentException) {
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

    public function format(AstNode $node, Environment $env): string
    {
        if ($node instanceof VariableReferenceNode) {
            $valueNode = $this->resolveVariable($node->name, $env);

            return $this->format($this->evaluateValue($valueNode, $env), $env);
        }

        if ($node instanceof BooleanNode) {
            return (string) $node;
        }

        if ($node instanceof NullNode) {
            return '';
        }

        if ($node instanceof NumberNode || $node instanceof ColorNode) {
            return $this->ctx->valueFactory->fromAst($node)->toCss();
        }

        if ($node instanceof StringNode) {
            if ($node->value === '&' && ! $node->isSelectorValue) {
                $selectorNode = $this->getCurrentParentSelector($env);

                if ($selectorNode !== null) {
                    return $selectorNode->value;
                }
            }

            return $this->ctx->valueFactory->fromAst($node)->toCss();
        }

        if ($node instanceof ListNode) {
            if ($this->calculation->isSlashChain($node)) {
                return $this->calculation->formatSlashChain($node, $env);
            }

            return $this->calculation->formatListValue($node->items, $node->separator, $node->bracketed, $env);
        }

        if ($node instanceof ArgumentListNode) {
            return $this->calculation->formatListValue($node->items, $node->separator, $node->bracketed, $env);
        }

        if ($node instanceof MapNode) {
            $pairs = [];

            foreach ($node->pairs as $pair) {
                $pairs[] = [
                    'key'   => $this->calculation->toSassValue($pair->key, $env),
                    'value' => $this->calculation->toSassValue($pair->value, $env),
                ];
            }

            return (string) new SassMap($pairs);
        }

        if ($node instanceof FunctionNode) {
            $node = $this->preserveHwbZeroHueUnit($node);

            if ($this->options->style === Style::COMPRESSED) {
                $compressedColor = $this->hexColorConverter->tryConvert($node);

                if ($compressedColor !== null) {
                    return $this->ctx->valueFactory->fromAst($compressedColor)->toCss();
                }
            }

            if (SassCalculation::isCalculationFunctionName($node->name)) {
                return $this->calculation->formatCalculationFunction($node, $env);
            }

            $formatted = $this->ctx->valueFactory->fromAst(
                $node,
                fn(AstNode $inner): string => $this->format($inner, $env),
            )->toCss();

            if (in_array($node->name, ['rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'color'], true)) {
                return $this->ctx->colorSerializer->serialize($formatted, $this->options->style === Style::COMPRESSED);
            }

            return $formatted;
        }

        if ($node instanceof MixinRefNode) {
            return $node->name;
        }

        if ($node instanceof NamedArgumentNode) {
            return '$' . $node->name . ': ' . $this->format($node->value, $env);
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
                && ! $item->quoted
                && in_array(trim($item->value), ['==', '!=', '>=', '<=', '>', '<'], true)
            ) {
                $comparisonIndex = $index;
                $operator        = trim($item->value);

                break;
            }
        }

        if (! is_string($operator)) {
            return null;
        }

        if (in_array($comparisonIndex, [null, 0, count($list->items) - 1], true)) {
            return null;
        }

        $count = count($list->items);

        $leftStart = $comparisonIndex - 1;

        while ($leftStart >= 2 && $this->isArithmeticOperatorItem($list->items[$leftStart - 1])) {
            $leftStart -= 2;
        }

        $left = $this->evaluateSpaceSeparatedItems(
            array_slice($list->items, $leftStart, $comparisonIndex - $leftStart),
            $env,
        );

        $rightEnd = $comparisonIndex + 2;

        while ($rightEnd + 1 < $count
            && $list->items[$rightEnd] instanceof StringNode
            && ! $list->items[$rightEnd]->quoted
            && in_array(trim($list->items[$rightEnd]->value), ['+', '-', '*', '/', '%'], true)
        ) {
            $rightEnd += 2;
        }

        $right = $this->evaluateSpaceSeparatedItems(
            array_slice($list->items, $comparisonIndex + 1, $rightEnd - $comparisonIndex - 1),
            $env,
        );

        $result = $this->createBooleanNode($this->condition->compare($left, $operator, $right, $env));

        $leading   = [];
        $remaining = [];

        if ($leftStart > 0) {
            $leading = array_slice($list->items, 0, $leftStart);
        }

        if ($rightEnd < $count) {
            $remaining = array_slice($list->items, $rightEnd);
        }

        if ($leading === []) {
            if ($remaining === []) {
                return $result;
            }

            $chained = $this->evaluateComparisonList(new ListNode([$result, ...$remaining], 'space'), $env);

            return $chained ?? new ListNode([$result, ...$remaining], 'space');
        }

        $tail = $remaining === [] ? [$result] : [$result, ...$remaining];

        return new ListNode([...$leading, ...$tail], 'space');
    }

    public function evaluateStringConcatenationList(ListNode $list, ?Environment $env = null, ?EvaluationOptions $options = null): ?AstNode
    {
        if ($options !== null && $options->skipConcatenation) {
            return null;
        }

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
     */
    public function resolveCallArguments(array $arguments, Environment $env): ResolvedCallArguments
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
        Scope $scope,
        Environment $env,
        string $restSeparator = 'comma',
    ): void {
        $this->userFunction->bindParametersToCurrentScope(
            $parameters,
            $resolvedPositional,
            $resolvedNamed,
            $scope,
            $env,
            $restSeparator,
        );
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

    private function isSlashDivisionCandidate(ListNode $value): bool
    {
        if (count($value->items) !== 3) {
            return false;
        }

        [$first, $mid, $last] = $value->items;

        if (! ($mid instanceof StringNode && $mid->value === '/')) {
            return false;
        }

        if ($first instanceof FunctionNode && strtolower($first->name) === 'calc') {
            return false;
        }

        if ($last instanceof FunctionNode && strtolower($last->name) === 'calc') {
            return false;
        }

        $firstLiteral = $first instanceof NumberNode && $first->isLiteral;
        $lastLiteral  = $last instanceof NumberNode && $last->isLiteral;

        return ! ($firstLiteral && $lastLiteral);
    }

    private function isMultiSlashChain(ListNode $value): bool
    {
        $items = $value->items;
        $count = count($items);

        if ($count < 5 || $count % 2 !== 1) {
            return false;
        }

        foreach ($items as $index => $item) {
            if ($index % 2 === 0) {
                if (! $item instanceof NumberNode && ! $item instanceof VariableReferenceNode) {
                    return false;
                }
            } elseif (! $item instanceof StringNode || $item->value !== '/') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, AstNode> $items
     */
    private function containsVariableReference(array $items): bool
    {
        foreach ($items as $item) {
            if ($item instanceof VariableReferenceNode) {
                return true;
            }
        }

        return false;
    }

    private function evaluateMultiSlashChain(ListNode $value, Environment $env): ?AstNode
    {
        $operands = [];

        foreach ($value->items as $index => $item) {
            if ($index % 2 === 0) {
                $operand = $this->evaluateValue($item, $env);

                if (! $operand instanceof NumberNode) {
                    return null;
                }

                $operands[] = $operand;
            }
        }

        $result = $operands[0];

        for ($i = 1, $count = count($operands); $i < $count; $i++) {
            $result = $this->arithmetic->applyDivision($result, $operands[$i]);
        }

        return $result;
    }

    private function isArithmeticOperatorItem(AstNode $node): bool
    {
        return $node instanceof StringNode
            && ! $node->quoted
            && in_array(trim($node->value), ['+', '-', '*', '/', '%'], true);
    }

    private function preserveHwbZeroHueUnit(FunctionNode $node): FunctionNode
    {
        if (strtolower($node->name) !== 'hwb' || count($node->arguments) !== 1) {
            return $node;
        }

        $argument = $node->arguments[0];

        if (! $argument instanceof ListNode || ! isset($argument->items[0])) {
            return $node;
        }

        $hue = $argument->items[0];

        if (! $hue instanceof NumberNode || $hue->unit !== null || $hue->value != 0) {
            return $node;
        }

        $hasMissingChannel = false;

        foreach ($argument->items as $item) {
            if ($item instanceof StringNode && strtolower(trim($item->value)) === 'none') {
                $hasMissingChannel = true;

                break;
            }
        }

        if (! $hasMissingChannel) {
            return $node;
        }

        $items    = $argument->items;
        $items[0] = new NumberNode(0, 'deg');

        return new FunctionNode(
            $node->name,
            [new ListNode($items, $argument->separator, $argument->bracketed)],
            $node->line,
            $node->modernSyntax,
            $node->capturedScope,
            $node->lockedDefinition,
        );
    }

    private function getCurrentParentSelector(Environment $env): ?StringNode
    {
        return $env->getCurrentScope()->getStringVariable('__parent_selector');
    }

    /**
     * @param array<int, AstNode> $items
     */
    private function evaluateSpaceSeparatedItems(array $items, Environment $env): AstNode
    {
        if (count($items) === 1) {
            return $items[0] instanceof ListNode
                ? $this->evaluateValueWithSlashDivision($items[0], $env)
                : $this->evaluateValue($items[0], $env);
        }

        return $this->evaluateValueWithSlashDivision(new ListNode($items, 'space'), $env);
    }

    private function resolveVariable(string $name, Environment $env): AstNode
    {
        $currentScope = $env->getCurrentScope();

        if (NameHelper::hasNamespace($name)) {
            $parts = NameHelper::splitNamespacedName($name);

            $moduleName  = $parts['namespace'];
            $varName     = $parts['member'];
            $moduleScope = $currentScope->getModule($moduleName);

            if ($varName === '') {
                throw new LogicException('Qualified variable reference must include a member name.');
            }

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

        $value = $currentScope->getAstVariable($name) ?? $env->findAstVariableInStackGlobals($name);

        if ($value === null) {
            throw UndefinedSymbolException::variable($name);
        }

        return $value;
    }

    private function createSlashDivisionValueEvaluator(): AstValueEvaluatorInterface
    {
        return new class ($this) implements AstValueEvaluatorInterface {
            public function __construct(private readonly Evaluator $evaluator) {}

            public function evaluate(AstNode $node, Environment $env): AstNode
            {
                return $this->evaluator->evaluateValueWithSlashDivision($node, $env);
            }
        };
    }

    private function createStringConcatenationEvaluator(): StringConcatenationEvaluator
    {
        return new StringConcatenationEvaluator(
            $this,
            $this->arithmetic,
            fn(AstNode $value): bool => $this->condition->isTruthy($value),
        );
    }

    private function createUserFunctionExecutor(): UserFunctionExecutor
    {
        return new UserFunctionExecutor(
            $this->condition,
            $this->parameterBinder,
            $this,
            $this->variableDeclarationApplier,
            $this->eachLoopBinder,
            $this->createSlashDivisionValueEvaluator(),
            $this->diagnosticHandler,
            new LoopIterator(),
        );
    }

    private function createCalculationEvaluator(): CalculationEvaluator
    {
        return new CalculationEvaluator(
            $this,
            new ArithmeticListEvaluator($this),
            new AstToSassValueConverter($this->ctx->valueFactory, $this),
        );
    }

    private function createVariableDeclarationApplier(): VariableDeclarationApplierInterface
    {
        return new VariableDeclarationApplier(
            $this->moduleVariableAssigner,
            $this->createSlashDivisionValueEvaluator(),
        );
    }

    private function createEachLoopBinder(): EachLoopBinderInterface
    {
        return new EachLoopBinder($this->ctx->valueFactory, $this->createSlashDivisionValueEvaluator());
    }

    private function createConditionalEvaluator(): ConditionalEvaluator
    {
        return new ConditionalEvaluator(
            $this->condition,
            $this->text,
            $this->createSlashDivisionValueEvaluator(),
            $this,
            new ComparisonListEvaluator($this),
            $this->ctx->valueFactory,
        );
    }

    private function createCssArgumentEvaluator(): CssArgumentEvaluator
    {
        return new CssArgumentEvaluator(
            $this->createSlashDivisionValueEvaluator(),
            new CalculationArgumentNormalizer($this),
            $this->createSkipConcatenationValueEvaluator(),
        );
    }

    private function createSkipConcatenationValueEvaluator(): AstValueEvaluatorInterface
    {
        return new class ($this) implements AstValueEvaluatorInterface {
            public function __construct(private readonly Evaluator $evaluator) {}

            public function evaluate(AstNode $node, Environment $env): AstNode
            {
                return $this->evaluator->evaluateValueWithSlashDivision(
                    $node,
                    $env,
                    EvaluationOptions::default()->withSkipConcatenation(),
                );
            }
        };
    }

    private function createCallArgumentResolver(): CallArgumentResolver
    {
        return new CallArgumentResolver(
            $this->parser,
            $this->cssArgument,
            $this->createSlashDivisionValueEvaluator(),
        );
    }

    private function createEvaluationValueEvaluator(): ValueEvaluatorInterface
    {
        return new class ($this) implements ValueEvaluatorInterface {
            public function __construct(private readonly Evaluator $evaluator) {}

            public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
            {
                return $this->evaluator->evaluateValue($node, $env, $options->skipSlashArithmetic, $options);
            }
        };
    }

    private function createFunctionCallEvaluator(): FunctionCallEvaluator
    {
        return new FunctionCallEvaluator(
            $this->ctx,
            $this->options,
            $this->userFunction,
            $this->callArguments,
            $this->calculation,
            $this->conditional,
            $this->hexColorConverter,
            $this->diagnosticHandler,
            $this,
            $this,
            $this->createSlashDivisionValueEvaluator(),
            $this->logger,
        );
    }

    private function createEvaluationStrategyRegistry(ValueEvaluatorInterface $valueEvaluator): EvaluationStrategyRegistry
    {
        return new EvaluationStrategyRegistry([
            new PassthroughNodeStrategy(),
            new FunctionNodeStrategy($this->functionCalls),
            new VariableReferenceStrategy(
                $valueEvaluator,
                fn(string $name, Environment $env): AstNode => $this->resolveVariable($name, $env),
            ),
            new StringNodeStrategy(
                fn(Environment $env): ?StringNode => $this->getCurrentParentSelector($env),
                fn(): AstNode => $this->ctx->valueFactory->createNullNode(),
                fn(string $value, Environment $env, bool $decoded = false): string => $this->text->replaceInterpolations($value, $env, $decoded),
            ),
            new ListNodeStrategy(
                $valueEvaluator,
                fn(ListNode $list, Environment $env): ?AstNode => $this->conditional->evaluateLogicalList($list, $env),
                fn(ListNode $list, bool $strict, Environment $env): ?AstNode => $this->evaluateArithmeticList($list, $strict, $env),
                fn(ListNode $list, ?Environment $env, EvaluationOptions $opts): ?AstNode => $this->evaluateStringConcatenationList($list, $env, $opts),
            ),
            new ArgumentListNodeStrategy($valueEvaluator),
            new MapNodeStrategy($valueEvaluator),
            new NamedArgumentNodeStrategy($valueEvaluator),
            new DeprecatedExpressionStrategy(
                $valueEvaluator,
                $this->diagnosticHandler,
            ),
        ]);
    }
}
