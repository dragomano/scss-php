<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Exceptions\FunctionReturnValueException;
use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\DebugNode;
use Bugo\SCSS\Nodes\EachNode;
use Bugo\SCSS\Nodes\ErrorNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\ReturnNode;
use Bugo\SCSS\Nodes\WarnNode;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\Runtime\CallableDefinition;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;
use Closure;

use function array_filter;
use function array_slice;

use const ARRAY_FILTER_USE_KEY;

final readonly class UserFunctionExecutor
{
    /**
     * @param Closure(AstNode, Environment): AstNode $evaluateValue
     * @param Closure(AstNode, Environment): bool $applyVariableDeclaration
     * @param Closure(AstNode): array<int, AstNode> $eachIterableItems
     * @param Closure(array<int, string>, AstNode, Environment): void $assignEachVariables
     * @param Closure(AstNode, Environment): AstNode $evaluateValueWithSlashDivision
     * @param Closure(string, AstNode, Environment, AstNode|null): void $handleDiagnosticDirective
     */
    public function __construct(
        private Condition $condition,
        private Closure $evaluateValue,
        private Closure $applyVariableDeclaration,
        private Closure $eachIterableItems,
        private Closure $assignEachVariables,
        private Closure $evaluateValueWithSlashDivision,
        private Closure $handleDiagnosticDirective
    ) {}

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function executeDefinition(
        string $name,
        CallableDefinition $function,
        array $positional,
        array $named,
        Environment $env
    ): AstNode {
        $env->enterScope($function->closureScope);

        $currentScope = $env->getCurrentScope();

        try {
            $this->bindArguments(
                $function->arguments,
                $positional,
                $named,
                $currentScope,
                function (string $argName, ?AstNode $defaultValue) use ($currentScope, $env, $name): void {
                    if ($defaultValue !== null) {
                        $currentScope->setVariableLocal($argName, ($this->evaluateValue)($defaultValue, $env));

                        return;
                    }

                    throw MissingFunctionArgumentsException::required($name, $argName);
                }
            );

            $result = $this->runStatements($function->body, $env);

            if ($result !== null) {
                return $result;
            }
        } finally {
            $env->exitScope();
        }

        throw new FunctionReturnValueException($name);
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
        $this->bindArguments(
            $parameters,
            $resolvedPositional,
            $resolvedNamed,
            $scope,
            static function (string $name, ?AstNode $defaultValue) use ($scope): void {
                if ($defaultValue !== null) {
                    $scope->setVariableLocal($name, $defaultValue);
                }
            }
        );
    }

    /**
     * @param array<int, ArgumentNode> $parameters
     * @param array<int, AstNode> $resolvedPositional
     * @param array<string, AstNode> $resolvedNamed
     */
    private function bindArguments(
        array $parameters,
        array $resolvedPositional,
        array $resolvedNamed,
        Scope $scope,
        callable $resolveDefault
    ): void {
        $parameterNameSet = null;

        foreach ($parameters as $index => $parameter) {
            $parameterName = $parameter->name;

            if (! $parameter->rest && isset($resolvedNamed[$parameterName])) {
                $scope->setVariableLocal($parameterName, $resolvedNamed[$parameterName]);

                continue;
            }

            if (! $parameter->rest && isset($resolvedPositional[$index])) {
                $scope->setVariableLocal($parameterName, $resolvedPositional[$index]);

                continue;
            }

            if ($parameter->rest) {
                if ($parameterNameSet === null) {
                    $parameterNameSet = $this->buildParameterNameSet($parameters);
                }

                $scope->setVariableLocal(
                    $parameterName,
                    new ArgumentListNode(
                        array_slice($resolvedPositional, $index),
                        'comma',
                        false,
                        array_filter(
                            $resolvedNamed,
                            fn(string $name): bool => ! isset($parameterNameSet[$name]),
                            ARRAY_FILTER_USE_KEY
                        )
                    )
                );

                continue;
            }

            $resolveDefault($parameterName, $parameter->defaultValue);
        }
    }

    /**
     * @param array<int, AstNode> $statements
     */
    private function runStatements(array $statements, Environment $env): ?AstNode
    {
        foreach ($statements as $statement) {
            if (($this->applyVariableDeclaration)($statement, $env)) {
                continue;
            }

            if ($statement instanceof EachNode) {
                $iterableValue = ($this->evaluateValue)($statement->list, $env);
                $items         = ($this->eachIterableItems)($iterableValue);

                foreach ($items as $item) {
                    ($this->assignEachVariables)($statement->variables, $item, $env);

                    $result = $this->runStatements($statement->body, $env);

                    if ($result !== null) {
                        return $result;
                    }
                }

                continue;
            }

            if ($statement instanceof ForNode) {
                $from = $this->loopBoundary($statement->from, $env);
                $to   = $this->loopBoundary($statement->to, $env);

                if (! $statement->inclusive) {
                    $to += $from <= $to ? -1 : 1;
                }

                $step          = $from <= $to ? 1 : -1;
                $iterations    = 0;
                $maxIterations = 10000;

                for ($i = $from; $step > 0 ? $i <= $to : $i >= $to; $i += $step) {
                    $iterations++;

                    if ($iterations > $maxIterations) {
                        throw new MaxIterationsExceededException('@for');
                    }

                    $env->getCurrentScope()->setVariable($statement->variable, new NumberNode($i));

                    $result = $this->runStatements($statement->body, $env);

                    if ($result !== null) {
                        return $result;
                    }
                }

                continue;
            }

            if ($statement instanceof ReturnNode) {
                return ($this->evaluateValueWithSlashDivision)($statement->value, $env);
            }

            if ($statement instanceof WhileNode) {
                $iterations    = 0;
                $maxIterations = 10000;

                while ($this->condition->evaluate($statement->condition, $env)) {
                    $iterations++;

                    if ($iterations > $maxIterations) {
                        throw new MaxIterationsExceededException('@while');
                    }

                    $result = $this->runStatements($statement->body, $env);

                    if ($result !== null) {
                        return $result;
                    }
                }

                continue;
            }

            if ($statement instanceof DebugNode) {
                ($this->handleDiagnosticDirective)('debug', $statement->message, $env, $statement);

                continue;
            }

            if ($statement instanceof WarnNode) {
                ($this->handleDiagnosticDirective)('warn', $statement->message, $env, $statement);

                continue;
            }

            if ($statement instanceof ErrorNode) {
                ($this->handleDiagnosticDirective)('error', $statement->message, $env, $statement);
            }

            if ($statement instanceof IfNode) {
                if ($this->condition->evaluate($statement->condition, $env)) {
                    $result = $this->runStatements($statement->body, $env);

                    if ($result !== null) {
                        return $result;
                    }

                    continue;
                }

                $executedElseIf = false;

                foreach ($statement->elseIfBranches as $branch) {
                    $condition = $branch->condition;
                    $body      = $branch->body;

                    if ($this->condition->evaluate($condition, $env)) {
                        $executedElseIf = true;

                        $result = $this->runStatements($body, $env);

                        if ($result !== null) {
                            return $result;
                        }

                        break;
                    }
                }

                if ($executedElseIf) {
                    continue;
                }

                $result = $this->runStatements($statement->elseBody, $env);

                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, ArgumentNode> $parameters
     * @return array<string, true>
     */
    private function buildParameterNameSet(array $parameters): array
    {
        $names = [];

        foreach ($parameters as $parameter) {
            $names[$parameter->name] = true;
        }

        return $names;
    }

    private function loopBoundary(AstNode $node, Environment $env): int
    {
        $resolved = ($this->evaluateValue)($node, $env);

        if ($resolved instanceof NumberNode) {
            return (int) $resolved->value;
        }

        return 0;
    }
}
