<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Builtins\Color\Conversion\HexColorConverter;
use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Runtime\CallableDefinition;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Style;
use Bugo\SCSS\Utils\NameHelper;

use function count;
use function implode;
use function in_array;
use function strtolower;

final readonly class FunctionCallEvaluator
{
    public function __construct(
        private CompilerContext $ctx,
        private CompilerOptions $options,
        private UserFunctionExecutor $userFunction,
        private CallArgumentResolver $callArguments,
        private CalculationEvaluator $calculation,
        private ConditionalEvaluator $conditional,
        private HexColorConverter $hexColorConverter,
        private DiagnosticDirectiveHandlerInterface $diagnosticHandler,
        private AstValueEvaluatorInterface $valueEvaluator,
        private AstValueFormatterInterface $valueFormatter,
    ) {}

    public function evaluate(FunctionNode $node, Environment $env): AstNode
    {
        if ($node->capturedScope !== null && $node->arguments === []) {
            return $node;
        }

        $resolvedUserFunction = $this->resolveUserFunction($node, $env);

        if ($resolvedUserFunction !== null) {
            return $this->executeUserFunction($node, $resolvedUserFunction, $env);
        }

        return $this->evaluateBuiltinOrCssFunction($node, $env);
    }

    /**
     * @return array{name: string, definition: CallableDefinition}|null
     */
    private function resolveUserFunction(FunctionNode $node, Environment $env): ?array
    {
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
                $userFunctionName = $functionName;
            }
        } elseif ($node->capturedScope !== null) {
            $userFunction = $node->capturedScope->findFunction($node->name)?->definition;
        }

        if ($userFunction === null) {
            $userFunction = $currentScope->findFunction($node->name)?->definition;
        }

        if ($userFunction === null) {
            return null;
        }

        return [
            'name'       => $userFunctionName,
            'definition' => $userFunction,
        ];
    }

    /**
     * @param array{name: string, definition: CallableDefinition} $resolvedUserFunction
     */
    private function executeUserFunction(FunctionNode $node, array $resolvedUserFunction, Environment $env): AstNode
    {
        [$positionalArguments, $namedArguments] = $this->callArguments->resolveCallArguments($node->arguments, $env);

        if (++$this->ctx->moduleState->callDepth > 100) {
            $this->ctx->moduleState->callDepth--;

            throw new MaxIterationsExceededException('@function');
        }

        try {
            return $this->userFunction->executeDefinition(
                $resolvedUserFunction['name'],
                $resolvedUserFunction['definition'],
                $positionalArguments,
                $namedArguments,
                $env,
            );
        } finally {
            $this->ctx->moduleState->callDepth--;
        }
    }

    private function evaluateBuiltinOrCssFunction(FunctionNode $node, Environment $env): AstNode
    {
        $arguments = $this->callArguments->expandCallArguments($node->arguments, $env);
        $arguments = $this->calculation->normalizeArguments($node->name, $arguments);

        if (strtolower($node->name) === 'if' && count($arguments) >= 2 && ! $node->modernSyntax) {
            $rawCond = $node->arguments[0] ?? $arguments[0];
            $condStr = $rawCond instanceof VariableReferenceNode
                ? '$' . $rawCond->name
                : $this->valueFormatter->format($arguments[0], $env);

            $suggestion = 'if(sass(' . $condStr . '): ' . $this->valueFormatter->format($arguments[1], $env);

            if (isset($arguments[2])) {
                $suggestion .= '; else: ' . $this->valueFormatter->format($arguments[2], $env);
            }

            $suggestion .= ')';

            $this->diagnosticHandler->handle(
                'warn',
                new StringNode(implode(' ', [
                    'The Sass if() syntax is deprecated in favor of the modern CSS syntax.',
                    'Use `' . $suggestion . '` instead.',
                ])),
                $env,
                $node,
            );
        }

        $inlineIf = $this->conditional->evaluateInlineIfFunction($node->name, $arguments, $env);

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
            fn(string $msg) => $this->diagnosticHandler->handle('warn', new StringNode($msg), $env, $node),
            null,
            $node->arguments,
            $node->line,
        );

        $preferBuiltin = in_array(strtolower($node->name), ['max', 'min', 'clamp'], true);

        if (! $preferBuiltin && $simplifiedFunction !== null) {
            return $simplifiedFunction;
        }

        $resolved = $this->ctx->functionRegistry->tryCall($node->name, $arguments, $context);

        if ($resolved !== null) {
            if ($resolved instanceof FunctionNode && $resolved->name !== $node->name) {
                return $this->valueEvaluator->evaluate($resolved, $env);
            }

            return $resolved;
        }

        if ($preferBuiltin && $simplifiedFunction !== null) {
            return $simplifiedFunction;
        }

        $fallbackArguments = $this->callArguments->expandCssCallArguments($node->arguments, $env);

        $fallback = new FunctionNode(
            $node->name,
            $this->calculation->normalizeArguments($node->name, $fallbackArguments),
        );

        if ($this->options->style === Style::COMPRESSED) {
            $compressedColor = $this->hexColorConverter->tryConvert($fallback);

            if ($compressedColor !== null) {
                return $compressedColor;
            }
        }

        return $fallback;
    }
}
