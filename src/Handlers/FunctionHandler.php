<?php

declare(strict_types=1);

namespace DartSass\Handlers;

use Closure;
use DartSass\Compilers\Environment;
use DartSass\Evaluators\UserFunctionEvaluator;
use DartSass\Handlers\Builtins\CustomFunctionHandler;

use function array_key_exists;
use function count;
use function explode;
use function is_array;
use function str_contains;

readonly class FunctionHandler
{
    public function __construct(
        private Environment           $environment,
        private ModuleHandler         $moduleHandler,
        private FunctionRouter        $router,
        private CustomFunctionHandler $customFunctionHandler,
        private UserFunctionEvaluator $userFunctionEvaluator,
        private Closure               $expression,
    ) {}

    public function addCustom(string $name, callable $callback): void
    {
        $this->customFunctionHandler->addCustomFunction($name, $callback);
    }

    public function exists(string $name): bool
    {
        return $this->environment->getCurrentScope()->hasFunction($name);
    }

    public function defineUserFunction(
        string $name,
        array $args,
        array $body,
        bool $global = false
    ): void {
        $this->environment->getCurrentScope()->setFunction($name, $args, $body, $global);
    }

    public function getUserFunctions(): array
    {
        return [
            'customFunctions' => $this->customFunctionHandler->getSupportedFunctions(),
        ];
    }

    public function setUserFunctions(array $state): void
    {
        if (isset($state['customFunctions'])) {
            $this->customFunctionHandler->setCustomFunctions($state['customFunctions']);
        }
    }

    public function call(string $name, array $args)
    {
        $namespace = str_contains($name, '.') ? explode('.', $name, 2)[0] : '';

        if (
            count($args) === 1
            && array_key_exists(0, $args)
            && is_array($args[0])
            && ! isset($args[0]['value'])
        ) {
            $args = $args[0];
        }

        $originalName = $name;
        $modulePath   = SassModule::getPath($namespace);

        if ($namespace && ! $this->moduleHandler->isModuleLoaded($modulePath)) {
            $this->moduleHandler->loadModule($modulePath, $namespace);
        }

        if ($this->environment->getCurrentScope()->hasFunction($originalName)) {
            $func = $this->environment->getCurrentScope()->getFunction($originalName);

            return $this->userFunctionEvaluator->evaluate($func, $args, $this->expression);
        }

        return $this->router->route($name, $args);
    }

    public function shouldPreserveQuotedStringArguments(string $name): bool
    {
        return $this->router->shouldPreserveQuotedStringArguments($name);
    }
}
