<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Utils\NameNormalizer;

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
        string $restSeparator = 'comma',
    ): void {
        $parameterNameSet = null;
        $normalizedNamed  = [];

        foreach ($resolvedNamed as $name => $value) {
            $normalizedNamed[NameNormalizer::normalize($name)] = $value;
        }

        foreach ($parameters as $index => $parameter) {
            $parameterName = NameNormalizer::normalize($parameter->name);

            if (! $parameter->rest && isset($normalizedNamed[$parameterName])) {
                $scope->setVariableLocal($parameterName, $normalizedNamed[$parameterName]);

                continue;
            }

            if (! $parameter->rest && isset($resolvedPositional[$index])) {
                $scope->setVariableLocal($parameterName, $resolvedPositional[$index]);

                continue;
            }

            if ($parameter->rest) {
                $parameterNameSet ??= $this->buildParameterNameSet($parameters);

                $scope->setVariableLocal(
                    $parameterName,
                    new ArgumentListNode(
                        array_slice($resolvedPositional, $index),
                        $restSeparator,
                        false,
                        array_filter(
                            $normalizedNamed,
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
            if (! $parameter->rest) {
                $names[NameNormalizer::normalize($parameter->name)] = true;
            }
        }

        return $names;
    }
}
