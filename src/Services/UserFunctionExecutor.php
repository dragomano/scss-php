<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Exceptions\FunctionReturnValueException;
use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
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

final readonly class UserFunctionExecutor
{
    public function __construct(
        private Condition $condition,
        private CallableParameterBinder $parameterBinder,
        private AstValueEvaluatorInterface $valueEvaluator,
        private VariableDeclarationApplierInterface $variableDeclarationApplier,
        private EachLoopBinderInterface $eachLoopBinder,
        private AstValueEvaluatorInterface $slashDivisionValueEvaluator,
        private DiagnosticDirectiveHandlerInterface $diagnosticHandler,
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
        Environment $env,
    ): AstNode {
        $env->enterScope($function->closureScope);

        $currentScope = $env->getCurrentScope();

        try {
            $this->parameterBinder->bind(
                $function->arguments,
                $positional,
                $named,
                $currentScope,
                function (string $argName, ?AstNode $defaultValue) use ($currentScope, $env, $name): void {
                    if ($defaultValue !== null) {
                        $currentScope->setVariableLocal($argName, $this->valueEvaluator->evaluate($defaultValue, $env));

                        return;
                    }

                    throw MissingFunctionArgumentsException::required($name, $argName);
                },
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
        Scope $scope,
    ): void {
        $this->parameterBinder->bind(
            $parameters,
            $resolvedPositional,
            $resolvedNamed,
            $scope,
            static function (string $name, ?AstNode $defaultValue) use ($scope): void {
                if ($defaultValue !== null) {
                    $scope->setVariableLocal($name, $defaultValue);
                }
            },
        );
    }

    /**
     * @param array<int, AstNode> $statements
     */
    private function runStatements(array $statements, Environment $env): ?AstNode
    {
        foreach ($statements as $statement) {
            if ($this->variableDeclarationApplier->apply($statement, $env)) {
                continue;
            }

            if ($statement instanceof EachNode) {
                $iterableValue = $this->valueEvaluator->evaluate($statement->list, $env);
                $items         = $this->eachLoopBinder->items($iterableValue);

                foreach ($items as $item) {
                    $this->eachLoopBinder->assign($statement->variables, $item, $env);

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
                return $this->slashDivisionValueEvaluator->evaluate($statement->value, $env);
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
                $this->diagnosticHandler->handle('debug', $statement->message, $env, $statement);

                continue;
            }

            if ($statement instanceof WarnNode) {
                $this->diagnosticHandler->handle('warn', $statement->message, $env, $statement);

                continue;
            }

            if ($statement instanceof ErrorNode) {
                $this->diagnosticHandler->handle('error', $statement->message, $env, $statement);
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

    private function loopBoundary(AstNode $node, Environment $env): int
    {
        $resolved = $this->valueEvaluator->evaluate($node, $env);

        if ($resolved instanceof NumberNode) {
            return (int) $resolved->value;
        }

        return 0;
    }
}
