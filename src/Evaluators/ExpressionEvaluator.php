<?php

declare(strict_types=1);

namespace DartSass\Evaluators;

use Closure;
use DartSass\Exceptions\CompilationException;
use DartSass\Handlers\FunctionHandler;
use DartSass\Handlers\ModuleHandler;
use DartSass\Handlers\VariableHandler;
use DartSass\Parsers\Nodes\AstNode;
use DartSass\Parsers\Nodes\ConditionNode;
use DartSass\Parsers\Nodes\CssCustomPropertyNode;
use DartSass\Parsers\Nodes\CssPropertyNode;
use DartSass\Parsers\Nodes\FunctionNode;
use DartSass\Parsers\Nodes\IdentifierNode;
use DartSass\Parsers\Nodes\InterpolationNode;
use DartSass\Parsers\Nodes\ListNode;
use DartSass\Parsers\Nodes\MapNode;
use DartSass\Parsers\Nodes\NodeType;
use DartSass\Parsers\Nodes\NumberNode;
use DartSass\Parsers\Nodes\OperationNode;
use DartSass\Parsers\Nodes\OperatorNode;
use DartSass\Parsers\Nodes\PropertyAccessNode;
use DartSass\Parsers\Nodes\SelectorNode;
use DartSass\Parsers\Nodes\StringNode;
use DartSass\Parsers\Nodes\UnaryNode;
use DartSass\Parsers\Nodes\VariableNode;
use DartSass\Utils\ArithmeticCalculator;
use DartSass\Utils\ResultFormatterInterface;
use DartSass\Utils\SpreadHelper;
use DartSass\Utils\StringFormatter;
use DartSass\Utils\ValueComparator;
use DartSass\Values\SassList;
use DartSass\Values\SassMap;
use DartSass\Values\SassNumber;

use function end;
use function explode;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

readonly class ExpressionEvaluator implements EvaluatorInterface
{
    public function __construct(
        private VariableHandler          $variableHandler,
        private ModuleHandler            $moduleHandler,
        private FunctionHandler          $functionHandler,
        private InterpolationEvaluator   $interpolationEvaluator,
        private ResultFormatterInterface $resultFormatter,
        private Closure                  $operationEvaluator
    ) {}

    public function supports(mixed $expression): bool
    {
        if ($expression instanceof AstNode) {
            return true;
        }

        if (is_string($expression)) {
            return true;
        }

        return is_numeric($expression) || is_array($expression) || $expression === null || is_bool($expression);
    }

    public function evaluate(mixed $expression): mixed
    {
        if ($expression instanceof OperationNode) {
            return ($this->operationEvaluator)($expression);
        }

        if ($expression instanceof AstNode) {
            return $this->evaluateAstNode($expression);
        }

        if (is_string($expression) && str_starts_with($expression, '$')) {
            return $this->evaluateVariableString($expression);
        }

        if (is_string($expression)) {
            return $this->tryParseNumericString($expression);
        }

        return $expression;
    }

    private function evaluateAstNode(AstNode $node): mixed
    {
        if ($node instanceof SelectorNode || $node instanceof OperatorNode) {
            return $node->value;
        }

        if ($node instanceof ConditionNode) {
            return $this->evaluate($node->expression);
        }

        if ($node instanceof InterpolationNode) {
            return $this->unwrapInterpolationResult($this->evaluate($node->expression));
        }

        $type = $node->type;

        $values = ['Unknown expression type: ', $type->value, ' at line ', $node->line ?? 0];

        return match ($type) {
            NodeType::COLOR,
            NodeType::HEX_COLOR           => $node,
            NodeType::FUNCTION            => $this->evaluateFunctionNode($node),
            NodeType::NUMBER              => $this->evaluateNumberNode($node),
            NodeType::STRING              => $this->evaluateStringNode($node),
            NodeType::LIST                => $this->evaluateListNode($node),
            NodeType::MAP                 => $this->evaluateMapNode($node),
            NodeType::IDENTIFIER          => $this->evaluateIdentifierNode($node),
            NodeType::VARIABLE            => $this->evaluateVariableNode($node),
            NodeType::CSS_CUSTOM_PROPERTY => $this->evaluateCssCustomPropertyNode($node),
            NodeType::PROPERTY_ACCESS     => $this->evaluatePropertyAccessNode($node),
            NodeType::CSS_PROPERTY        => $this->evaluateCssPropertyNode($node),
            NodeType::UNARY               => $this->evaluateUnaryNode($node),
            default                       => throw new CompilationException(StringFormatter::concatMultiple($values)),
        };
    }

    private function evaluateVariableString(string $expr): mixed
    {
        if (str_contains($expr, '.')) {
            [$namespace, $name] = explode('.', $expr, 2);

            $propertyName = '$' . $name;

            try {
                return $this->moduleHandler->getProperty($namespace, $propertyName, $this->evaluate(...));
            } catch (CompilationException) {
                try {
                    return $this->variableHandler->get($expr);
                } catch (CompilationException) {
                    throw new CompilationException("Undefined property: $propertyName in module $namespace");
                }
            }
        }

        return $this->variableHandler->get($expr);
    }

    private function tryParseNumericString(string $expr): string|array|float
    {
        if (preg_match('/^(\d+\.?\d*)\s*(px|em|rem|%)?$/', $expr, $matches)) {
            $value = (float) $matches[1];
            $unit  = $matches[2] ?? '';

            if ($unit === '') {
                return $value;
            }

            return SassNumber::tryFrom(['value' => $value, 'unit' => $unit])?->toArray()
                ?? ['value' => $value, 'unit' => $unit];
        }

        return $expr;
    }

    private function evaluateFunctionNode(FunctionNode|AstNode $node): mixed
    {
        $name = $node->name;
        $args = $node->args ?? [];

        if ($name === 'calc') {
            return $this->evaluateCalcFunction($args);
        }

        if ($this->hasSlashSeparator($args)) {
            return $this->evaluateFunctionWithSlashSeparator($name, $args);
        }

        return match (true) {
            $name === 'if'   => $this->evaluateIfFunction($args),
            $name === 'url'  => $this->evaluateUrlFunction($args),
            default          => $this->evaluateStandardFunction($name, $args),
        };
    }

    private function evaluateNumberNode(NumberNode|AstNode $node): string|array|int|float
    {
        $value = $node->value;
        $unit  = $node->unit ?? '';

        if ($unit === '') {
            return $value;
        }

        $sassNumber = new SassNumber($value, $unit);

        return $sassNumber->toArray();
    }

    private function evaluateStringNode(StringNode|AstNode $node): string
    {
        $value = $node->value;
        $value = $this->interpolationEvaluator->evaluate($value, $this->evaluate(...));

        if (preg_match('/^[a-zA-Z_-][a-zA-Z0-9_-]*$/', $value)) {
            return $value;
        }

        if ($node->quoted) {
            return StringFormatter::forceQuoteString(StringFormatter::unquoteString($value));
        }

        return StringFormatter::forceQuoteString($value);
    }

    private function evaluateListNode(ListNode|AstNode $node): SassList
    {
        return new SassList(
            $this->evaluateArguments($node->values),
            $node->separator ?? 'comma',
            $node->bracketed ?? false
        );
    }

    private function evaluateMapNode(MapNode|AstNode $node): SassMap
    {
        $pairs = $node->pairs ?? [];
        $map   = [];

        foreach ($pairs as [$key, $value]) {
            $evaluatedKey   = $this->evaluate($key);
            $evaluatedValue = $this->evaluate($value);

            $keyString = $this->convertKeyToString($evaluatedKey);

            if ($keyString !== null) {
                $map[$keyString] = $evaluatedValue;
            }
        }

        return new SassMap($map);
    }

    private function evaluateIdentifierNode(IdentifierNode|AstNode $node): mixed
    {
        $value = $node->value;

        return match (strtolower($value)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => $value,
        };
    }

    private function evaluateVariableNode(VariableNode|AstNode $node): mixed
    {
        return $this->evaluate($this->variableHandler->get($node->name));
    }

    private function evaluateCssCustomPropertyNode(CssCustomPropertyNode|AstNode $node): mixed
    {
        return $node->name;
    }

    private function evaluatePropertyAccessNode(PropertyAccessNode|AstNode $node): mixed
    {
        $namespace = $this->evaluate($node->namespace);

        $propertyNode = $node->property;
        if ($propertyNode instanceof VariableNode) {
            $propertyName = $propertyNode->name;
        } else {
            $propertyName = $this->evaluate($propertyNode);
        }

        if (is_string($namespace) && is_string($propertyName) && str_starts_with($propertyName, '$')) {
            return $this->moduleHandler->getProperty($namespace, $propertyName, $this->evaluate(...));
        }

        if (is_string($namespace) && ! str_starts_with($namespace, '$')) {
            return $namespace;
        }

        throw new CompilationException("Invalid property access: $namespace.$propertyName");
    }

    private function evaluateCssPropertyNode(CssPropertyNode|AstNode $node): string
    {
        $property = $node->property;
        $value    = $this->evaluate($node->value);

        return StringFormatter::concatMultiple([$property, ': ', $this->formatValue($value)]);
    }

    private function evaluateUnaryNode(UnaryNode|AstNode $node): string|array|bool|float
    {
        $operand  = $this->evaluate($node->operand);
        $operator = $node->operator;

        // Try to use SassNumber for numeric operations
        $sassNumber = SassNumber::tryFrom($operand);

        if ($sassNumber !== null) {
            return match ($operator) {
                '+'     => $sassNumber->toArray()['unit'] === '' ? $sassNumber->getValue() : $sassNumber->toArray(),
                '-'     => $this->negateNumber($sassNumber),
                'not'   => ValueComparator::not($sassNumber->getValue()),
                default => throw new CompilationException("Unknown unary operator: $operator"),
            };
        }

        // Handle non-numeric operands
        return match ($operator) {
            'not'   => ValueComparator::not($operand),
            default => StringFormatter::concat($operator, $this->formatValue($operand)),
        };
    }

    private function evaluateArguments(array $args): array
    {
        foreach ($args as $key => $arg) {
            $args[$key] = $this->evaluate($arg);
        }

        return $args;
    }

    private function convertKeyToString(mixed $key): ?string
    {
        if (is_string($key)) {
            return trim($key, "'\"");
        }

        if (is_numeric($key)) {
            return (string) $key;
        }

        return null;
    }

    private function evaluateCalcFunction(array $args): mixed
    {
        $evaluator = new CalcFunctionEvaluator($this->resultFormatter);

        return $evaluator->evaluate($args, $this->evaluate(...));
    }

    private function evaluateIfFunction(array $args): mixed
    {
        $result = $this->functionHandler->call('if', $args);

        return $this->evaluate($result);
    }

    private function evaluateUrlFunction(array $args): mixed
    {
        $args = $this->evaluateUrlArguments($args);

        return $this->functionHandler->call('url', $args);
    }

    private function evaluateStandardFunction(string $name, array $args): mixed
    {
        if ($this->functionHandler->shouldPreserveQuotedStringArguments($name)) {
            $args = $this->evaluateArgumentsPreservingQuotedStrings($args);

            return $this->functionHandler->call($name, $args);
        }

        $args = SpreadHelper::expand(
            $this->evaluateArguments($args),
            $this->evaluate(...)
        );

        return $this->functionHandler->call($name, $args);
    }

    private function hasSlashSeparator(array $args): bool
    {
        if (empty($args)) {
            return false;
        }

        $lastArg = end($args);

        return $this->containsDivisionOperation($lastArg);
    }

    private function evaluateFunctionWithSlashSeparator(string $name, array $args): mixed
    {
        $args = $this->evaluateArgumentsWithSlashSeparator($args);

        return $this->functionHandler->call($name, $args);
    }

    private function evaluateUrlArguments(array $args): array
    {
        $processedArgs = [];

        foreach ($args as $arg) {
            if ($arg instanceof StringNode) {
                $processedArgs[] = $this->evaluateUrlString($arg);
            } else {
                $processedArgs[] = $this->evaluate($arg);
            }
        }

        return $processedArgs;
    }

    private function evaluateUrlString(StringNode|AstNode $arg): array
    {
        $originalContent = $arg->value;

        if (str_contains($originalContent, '#{$')) {
            $evaluated = $this->evaluate($arg);

            return [
                'value'  => $arg->quoted ? StringFormatter::unquoteString($evaluated) : $evaluated,
                'quoted' => $arg->quoted,
            ];
        }

        if ($arg->quoted) {
            return [
                'value'  => StringFormatter::unquoteString($originalContent),
                'quoted' => true,
            ];
        }

        return [
            'value'  => $originalContent,
            'quoted' => false,
        ];
    }

    private function containsDivisionOperation(mixed $arg): bool
    {
        if (! $arg instanceof AstNode) {
            return false;
        }

        if ($arg->type === NodeType::OPERATION && isset($arg->operator)) {
            return $arg->operator === '/';
        }

        return false;
    }

    private function evaluateArgumentsWithSlashSeparator(array $args): array
    {
        $processedArgs = [];

        foreach ($args as $arg) {
            if ($this->containsDivisionOperation($arg)) {
                $hueArg   = $this->evaluate($arg->left ?? $arg);
                $alphaArg = $this->evaluate($arg->right ?? null);

                $processedArgs[] = $hueArg;
                $processedArgs[] = $alphaArg;
            } else {
                $processedArgs[] = $this->evaluate($arg);
            }
        }

        return $processedArgs;
    }

    private function evaluateArgumentsPreservingQuotedStrings(array $args): array
    {
        $evaluated = [];

        foreach ($args as $key => $arg) {
            if ($arg instanceof StringNode && $arg->quoted) {
                $evaluated[$key] = StringFormatter::forceQuoteString(
                    $this->interpolationEvaluator->evaluate($arg->value, $this->evaluate(...))
                );

                continue;
            }

            $evaluated[$key] = $this->evaluate($arg);
        }

        return SpreadHelper::expand($evaluated, $this->evaluate(...));
    }

    private function negateNumber(SassNumber $number): float|array
    {
        $negated = ArithmeticCalculator::negate($number);

        return $negated->getUnit() === null ? $negated->getValue() : $negated->toArray();
    }

    private function formatValue(mixed $value): string
    {
        return $this->resultFormatter->format($value);
    }

    private function unwrapInterpolationResult(mixed $value): mixed
    {
        if (is_string($value) && StringFormatter::isQuoted($value)) {
            return StringFormatter::unquoteString($value);
        }

        return $value;
    }
}
