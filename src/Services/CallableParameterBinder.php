<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Scope;

use function array_filter;
use function array_slice;

use const ARRAY_FILTER_USE_KEY;

final readonly class CallableParameterBinder
{
    /**
     * @param array<int, ArgumentNode> $parameters
     * @param array<int, AstNode> $resolvedPositional
     * @param array<string, AstNode> $resolvedNamed
     * @param callable(string, AstNode|null): void $resolveDefault
     */
    public function bind(
        array $parameters,
        array $resolvedPositional,
        array $resolvedNamed,
        Scope $scope,
        callable $resolveDefault,
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
                            ARRAY_FILTER_USE_KEY,
                        ),
                    ),
                );

                continue;
            }

            $resolveDefault($parameterName, $parameter->defaultValue);
        }
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
}
