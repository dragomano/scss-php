<?php

declare(strict_types=1);

namespace DartSass\Handlers\Builtins;

use DartSass\Handlers\SassModule;
use DartSass\Modules\StringModule;

use function in_array;
use function str_replace;

class StringModuleHandler extends BaseModuleHandler implements ConditionalPreservationInterface, QuotedStringArgumentsInterface
{
    protected const MODULE_FUNCTIONS = [
        'quote', 'index', 'insert',
        'length', 'slice', 'split',
        'to-upper-case', 'to-lower-case',
        'unique-id', 'unquote',
    ];

    protected const GLOBAL_FUNCTIONS = [
        'quote', 'str-index', 'str-insert',
        'str-length', 'str-slice', 'to-upper-case',
        'to-lower-case', 'unique-id', 'unquote',
    ];

    public function __construct(private readonly StringModule $stringModule) {}

    public function handle(string $functionName, array $args): mixed
    {
        $processedArgs = $this->normalizeArgs($args);

        $methodName = $this->kebabToCamel(str_replace('str-', '', $functionName));

        return $this->stringModule->$methodName($processedArgs);
    }

    public function getModuleNamespace(): SassModule
    {
        return SassModule::STRING;
    }

    public function shouldPreserveForConditions(string $functionName): bool
    {
        return in_array($functionName, ['index', 'str-index', 'unquote'], true);
    }

    public function shouldPreserveQuotedStringArguments(string $functionName): bool
    {
        return in_array($functionName, [
            'quote', 'str-index', 'str-insert',
            'str-length', 'str-slice', 'to-upper-case',
            'to-lower-case', 'unquote',
            'index', 'insert', 'length',
            'slice', 'split',
        ], true);
    }

    protected function normalizeArgs(array $args): array
    {
        return $args;
    }
}
