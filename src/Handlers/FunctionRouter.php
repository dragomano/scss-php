<?php

declare(strict_types=1);

namespace DartSass\Handlers;

use DartSass\Exceptions\CompilationException;
use DartSass\Handlers\Builtins\ConditionalPreservationInterface;
use DartSass\Handlers\Builtins\LazyEvaluationInterface;
use DartSass\Handlers\Builtins\ModuleHandlerInterface;
use DartSass\Handlers\Builtins\QuotedStringArgumentsInterface;
use DartSass\Utils\ResultFormatterInterface;
use Exception;

use function array_map;
use function explode;
use function implode;
use function is_bool;
use function str_contains;
use function strrchr;
use function substr;

readonly class FunctionRouter
{
    public function __construct(
        private ModuleRegistry $registry,
        private ResultFormatterInterface $resultFormatter
    ) {}

    public function route(string $functionName, array $args): mixed
    {
        $shortName = $this->getShortName($functionName);

        $handler = $this->getHandler($shortName, $functionName);

        if ($handler === null) {
            return $this->handleUnknownFunction($functionName, $args);
        }

        try {
            $result = $handler->handle($shortName, $args);

            $requiresRawResult = $handler instanceof LazyEvaluationInterface
                && $handler->requiresRawResult($shortName);

            if ($requiresRawResult) {
                return $result;
            }

            $shouldPreserve = $handler instanceof ConditionalPreservationInterface
                && $handler->shouldPreserveForConditions($shortName);

            if ($shouldPreserve && ($result === null || is_bool($result))) {
                return $result;
            }

            return $this->resultFormatter->format($result);
        } catch (CompilationException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new CompilationException(
                "Error processing function $functionName: " . $e->getMessage()
            );
        }
    }

    public function shouldPreserveQuotedStringArguments(string $functionName): bool
    {
        $shortName = $this->getShortName($functionName);
        $handler   = $this->getHandler($shortName, $functionName);

        return $handler instanceof QuotedStringArgumentsInterface
            && $handler->shouldPreserveQuotedStringArguments($shortName);
    }

    private function getShortName(string $functionName): string
    {
        return str_contains($functionName, '.')
            ? substr(strrchr($functionName, '.'), 1)
            : $functionName;
    }

    private function getHandler(string $shortName, string $functionName): ?ModuleHandlerInterface
    {
        $handler = $this->registry->getHandler($functionName);

        if ($handler !== null) {
            return $handler;
        }

        if (str_contains($functionName, '.')) {
            $namespace = explode('.', $functionName, 2)[0];

            if (SassModule::isValid($namespace)) {
                throw new CompilationException(
                    "Function $shortName is not available in namespace $namespace"
                );
            }
        }

        return $this->registry->getHandler($shortName);
    }

    private function handleUnknownFunction(string $functionName, array $args): string
    {
        $argsList = implode(', ', array_map(
            $this->resultFormatter->format(...),
            $args
        ));

        return "$functionName($argsList)";
    }
}
