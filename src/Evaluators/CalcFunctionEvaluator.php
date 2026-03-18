<?php

declare(strict_types=1);

namespace DartSass\Evaluators;

use Closure;
use DartSass\Exceptions\CompilationException;
use DartSass\Parsers\Nodes\AstNode;
use DartSass\Parsers\Nodes\OperationNode;
use DartSass\Utils\ArithmeticCalculator;
use DartSass\Utils\ResultFormatterInterface;
use DartSass\Values\SassNumber;

use function array_map;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function str_ends_with;
use function str_starts_with;
use function substr;

readonly class CalcFunctionEvaluator
{
    public function __construct(private ResultFormatterInterface $resultFormatter) {}

    public function evaluate(array $args, Closure $expression): mixed
    {
        $resolvedArgs = [];

        foreach ($args as $arg) {
            $resolvedArgs[] = $this->resolveNode($arg, $expression);
        }

        if (count($resolvedArgs) === 1) {
            $result = $resolvedArgs[0];

            if ($result instanceof SassNumber) {
                if ($result->getUnit() === null) {
                    return $result->getValue();
                }

                return $result;
            }

            if (is_array($result) && isset($result['value'], $result['unit'])) {
                if ($result['unit'] === '') {
                    return $result['value'];
                }

                return $result;
            }

            if (is_numeric($result)) {
                return $result;
            }

            if (is_string($result) && str_starts_with($result, 'calc(')) {
                return $result;
            }
        }

        $argString = implode(', ', array_map(
            $this->resultFormatter->format(...),
            $resolvedArgs
        ));

        return 'calc(' . $argString . ')';
    }

    private function resolveNode(mixed $node, Closure $expression): mixed
    {
        if ($node instanceof OperationNode) {
            $node  = $this->ensurePrecedence($node);
            $left  = $this->resolveNode($node->left, $expression);
            $right = $this->resolveNode($node->right, $expression);

            $operator = $node->operator;

            return $this->computeOperation($left, $operator, $right);
        }

        if ($node instanceof AstNode) {
            return $expression($node);
        }

        return $node;
    }

    private function ensurePrecedence(OperationNode $node): OperationNode
    {
        $left     = $node->left;
        $right    = $node->right;
        $operator = $node->operator;

        if (($operator === '*' || $operator === '/') && $left instanceof OperationNode) {
            $subOp = $left->operator;

            if ($subOp === '+' || $subOp === '-') {
                $newLeft = $left->left;
                $mid     = $left->right;

                $newRight = new OperationNode($mid, $operator, $right, $node->line);
                $node     = new OperationNode($newLeft, $subOp, $newRight, $node->line);
            }
        }

        return $node;
    }

    private function computeOperation(mixed $left, string $operator, mixed $right): string|array
    {
        $leftNumber  = SassNumber::tryFrom($left);
        $rightNumber = SassNumber::tryFrom($right);

        if ($leftNumber !== null && $rightNumber !== null) {
            if (! in_array($operator, ['+', '-', '*', '/'], true)) {
                throw new CompilationException("Unknown operator: $operator");
            }

            try {
                $result = match ($operator) {
                    '+' => $leftNumber->add($rightNumber),
                    '-' => $leftNumber->subtract($rightNumber),
                    '*' => $leftNumber->multiply($rightNumber),
                    '/' => ArithmeticCalculator::divide($leftNumber, $rightNumber),
                };

                return $result->toArray();
            } catch (CompilationException) {
            }
        }

        $lStr = $this->formatResult($left);
        $rStr = $this->formatResult($right);
        $lStr = $this->unwrapCalc($lStr);
        $rStr = $this->unwrapCalc($rStr);

        return "$lStr $operator $rStr";
    }

    private function formatResult(mixed $val): string
    {
        $number = SassNumber::tryFrom($val);

        if ($number !== null) {
            return (string) $number;
        }

        return (string) $val;
    }

    private function unwrapCalc(string $val): string
    {
        if (str_starts_with($val, 'calc(') && str_ends_with($val, ')')) {
            return '(' . substr($val, 5, -1) . ')';
        }

        return $val;
    }
}
