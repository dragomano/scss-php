<?php

declare(strict_types=1);

namespace DartSass\Evaluators;

use Closure;
use DartSass\Exceptions\CompilationException;
use DartSass\Parsers\Nodes\OperationNode;
use DartSass\Parsers\Nodes\StringNode;
use DartSass\Utils\ArithmeticCalculator;
use DartSass\Utils\LazyEvaluatable;
use DartSass\Utils\LazyValue;
use DartSass\Utils\ResultFormatterInterface;
use DartSass\Utils\StringFormatter;
use DartSass\Utils\ValueComparator;
use DartSass\Values\CalcValue;
use DartSass\Values\SassNumber;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function str_contains;

class OperationEvaluator implements EvaluatorInterface
{
    private const COMPARISON_OPERATORS = ['==', '!=', '<', '>', '<=', '>=', 'and', 'or'];

    private const MULTIPLICATION_DIVISION_OPERATORS = ['*', '/'];

    public function __construct(
        private readonly ResultFormatterInterface $resultFormatter,
        private readonly Closure $nodeEvaluator
    ) {}

    public function supports(mixed $expression): bool
    {
        return $expression instanceof OperationNode;
    }

    public function evaluate(mixed $expression): string|bool|SassNumber
    {
        if ($expression instanceof OperationNode) {
            $left     = $this->evaluateOperand($expression->left);
            $right    = $this->evaluateOperand($expression->right);
            $operator = $expression->operator;

            return $this->evaluateOperation($left, $operator, $right);
        }

        throw new CompilationException('Invalid arguments for OperationEvaluator::evaluate()');
    }

    public function evaluateOperation(mixed $left, string $operator, mixed $right): string|bool|SassNumber
    {
        $left  = $this->resolveValue($left);
        $right = $this->resolveValue($right);

        if (in_array($operator, self::COMPARISON_OPERATORS, true)) {
            return $this->evaluateComparison($left, $operator, $right);
        }

        if ($operator === '+') {
            return $this->handleAddition($left, $right);
        }

        if ($operator === '/' && is_string($left) && is_string($right)) {
            return StringFormatter::concatMultiple([$left, ' / ', $right]);
        }

        return $this->evaluateArithmetic($left, $operator, $right);
    }

    private function resolveValue(mixed $value): mixed
    {
        if ($value instanceof LazyValue) {
            return $value->getValue();
        }

        if ($value instanceof LazyEvaluatable) {
            return $value->evaluate();
        }

        return $value;
    }

    private function evaluateOperand(mixed $operand): mixed
    {
        $value = ($this->nodeEvaluator)($operand);

        if ($operand instanceof StringNode && $operand->quoted && is_string($value)) {
            return StringFormatter::forceQuoteString(StringFormatter::unquoteString($value));
        }

        return $value;
    }

    private function evaluateComparison(mixed $left, string $operator, mixed $right): bool
    {
        return ValueComparator::compare($operator, $left, $right);
    }

    private function handleAddition(mixed $left, mixed $right): string|SassNumber
    {
        if (is_string($left) && is_string($right)) {
            return $this->concatenateStrings($left, $right);
        }

        if ((is_string($left) && is_numeric($right)) || (is_numeric($left) && is_string($right))) {
            return StringFormatter::concat($left, $right);
        }

        if ($this->isStructuredValue($left) && is_string($right)) {
            return StringFormatter::concat($this->formatStructuredValue($left), $right);
        }

        if (is_string($left) && $this->isStructuredValue($right)) {
            return StringFormatter::concat($left, $this->formatStructuredValue($right));
        }

        $result = ArithmeticCalculator::calculate('+', $left, $right);
        if ($result !== null) {
            return $result;
        }

        return $this->evaluateArithmetic($left, '+', $right);
    }

    private function concatenateStrings(string $left, string $right): string|SassNumber
    {
        if (is_numeric($left) && is_numeric($right)) {
            return ArithmeticCalculator::add((float) $left, (float) $right);
        }

        return StringFormatter::concat($left, $right);
    }

    private function evaluateArithmetic(mixed $left, string $operator, mixed $right): string|SassNumber
    {
        $result = ArithmeticCalculator::calculate($operator, $left, $right);

        if ($result !== null) {
            return $result;
        }

        if (in_array($operator, self::MULTIPLICATION_DIVISION_OPERATORS, true)) {
            $this->throwIfUndefinedOperation($left, $operator, $right);
        }

        return (string) new CalcValue($left, $operator, $right);
    }

    private function throwIfUndefinedOperation(mixed $left, string $operator, mixed $right): void
    {
        $isLeftSimple  = is_string($left) && ! str_contains($left, '(');
        $isRightSimple = is_string($right) && ! str_contains($right, '(');

        if ($isLeftSimple || $isRightSimple) {
            $leftStr  = $this->formatValue($left);
            $rightStr = $this->formatValue($right);

            throw new CompilationException("Undefined operation \"$leftStr $operator $rightStr\".");
        }
    }

    private function formatStructuredValue(array $value): string
    {
        return StringFormatter::concat($value['value'], $value['unit'] ?? '');
    }

    private function formatValue(mixed $value): string
    {
        return $this->resultFormatter->format($value);
    }

    private function isStructuredValue(mixed $value): bool
    {
        return is_array($value) && isset($value['value']);
    }
}
