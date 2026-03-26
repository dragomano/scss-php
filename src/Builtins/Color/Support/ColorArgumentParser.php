<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

use Bugo\SCSS\Contracts\Color\ColorConverterInterface;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\NonFiniteNumberException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Closure;

use function array_merge;
use function array_slice;
use function count;
use function in_array;
use function is_finite;
use function str_contains;
use function strtolower;

use const M_PI;

final readonly class ColorArgumentParser
{
    /**
     * @param Closure(string): string $errorCtx
     */
    public function __construct(
        private ColorConverterInterface $colorSpaceConverter,
        private Closure $errorCtx
    ) {}

    /**
     * @param array<int, AstNode> $positional
     */
    public function requireColor(array $positional, int $index, string $context): AstNode
    {
        if (! isset($positional[$index])) {
            throw MissingFunctionArgumentsException::required(($this->errorCtx)($context), 'color');
        }

        $value = $positional[$index];

        if (! ($value instanceof ColorNode || $value instanceof StringNode || $value instanceof FunctionNode)) {
            throw new MissingFunctionArgumentsException(($this->errorCtx)($context), 'color arguments');
        }

        return $value;
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function requireColorOrDefer(array $positional, string $context): AstNode
    {
        try {
            return $this->requireColor($positional, 0, $context);
        } catch (MissingFunctionArgumentsException $missingFunctionArgumentsException) {
            if ($this->shouldDeferToCss($context, $missingFunctionArgumentsException)) {
                throw new DeferToCssFunctionException(
                    $missingFunctionArgumentsException->getMessage(),
                    0,
                    $missingFunctionArgumentsException
                );
            }

            throw $missingFunctionArgumentsException;
        }
    }

    public function shouldDeferToCss(string $context, MissingFunctionArgumentsException $exception): bool
    {
        if (! str_contains($exception->getMessage(), 'expects color arguments')) {
            return false;
        }

        return in_array($context, [
            'saturate',
            'desaturate',
            'grayscale',
            'invert',
            'opacity',
        ], true);
    }

    /**
     * @param array<int, AstNode> $positional
     * @return array<int, AstNode>
     */
    public function parseFunctionalColorArguments(
        array $positional,
        string $context,
        int $minArguments
    ): array {
        $arguments = $this->expandSingleSpaceListArgument($positional);

        if ($this->isRelativeColorSyntax($arguments)) {
            throw new DeferToCssFunctionException(
                $this->callRef($context) . ' should be emitted as a CSS function.'
            );
        }

        if ($this->hasUnresolvableArguments($arguments)) {
            throw new DeferToCssFunctionException(
                $this->callRef($context) . ' should be emitted as a CSS function.'
            );
        }

        $arguments = $this->extractSlashAlpha($arguments);

        if (count($arguments) < $minArguments) {
            throw MissingFunctionArgumentsException::count(($this->errorCtx)($context), $minArguments);
        }

        return $arguments;
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function extractSlashAlpha(array $arguments): array
    {
        foreach ($arguments as $i => $argument) {
            if ($argument instanceof StringNode && $argument->value === '/') {
                return array_merge(
                    array_slice($arguments, 0, $i),
                    array_slice($arguments, $i + 1)
                );
            }
        }

        return $arguments;
    }

    /**
     * @param array<int, AstNode> $arguments
     */
    public function parseAlphaOrDefault(array $arguments, int $index, string $context): float
    {
        if (! isset($arguments[$index])) {
            return 1.0;
        }

        $arg = $arguments[$index];

        if ($arg instanceof NumberNode && $arg->unit === '%') {
            return $this->clamp((float) $arg->value / 100.0, 1.0);
        }

        return $this->clamp($this->asNumber($arg, $context), 1.0);
    }

    /**
     * @param array<int, AstNode> $positional
     * @return array<int, AstNode>
     */
    public function expandSingleSpaceListArgument(array $positional): array
    {
        if (
            count($positional) === 1
            && $positional[0] instanceof ListNode
            && $positional[0]->separator === 'space'
        ) {
            return $positional[0]->items;
        }

        return $positional;
    }

    public function asNumber(?AstNode $value, string $context): float
    {
        if (! ($value instanceof NumberNode)) {
            throw new MissingFunctionArgumentsException(($this->errorCtx)($context), 'number arguments');
        }

        if (! is_finite((float) $value->value)) {
            throw new NonFiniteNumberException(($this->errorCtx)($context));
        }

        return (float) $value->value;
    }

    public function asHueAngle(?AstNode $value, string $context): float
    {
        if (! ($value instanceof NumberNode)) {
            throw new MissingFunctionArgumentsException(($this->errorCtx)($context), 'number arguments');
        }

        if (! is_finite((float) $value->value)) {
            throw new NonFiniteNumberException(($this->errorCtx)($context));
        }

        $v = (float) $value->value;

        return match ($value->unit) {
            'turn'  => $v * 360.0,
            'rad'   => $v * (180.0 / M_PI),
            'grad'  => $v * 0.9,
            default => $v,
        };
    }

    public function asAbsoluteChannel(?AstNode $value, string $context, float $range): float
    {
        if (! ($value instanceof NumberNode)) {
            throw new MissingFunctionArgumentsException(($this->errorCtx)($context), 'number arguments');
        }

        if (! is_finite((float) $value->value)) {
            throw new NonFiniteNumberException(($this->errorCtx)($context));
        }

        $v = (float) $value->value;

        return $value->unit === '%' ? ($v / 100.0) * $range : $v;
    }

    public function asColorChannel(?AstNode $value): float
    {
        if (! ($value instanceof NumberNode)) {
            throw new MissingFunctionArgumentsException(($this->errorCtx)('color'), 'number arguments');
        }

        if (! is_finite((float) $value->value)) {
            throw new NonFiniteNumberException(($this->errorCtx)('color'));
        }

        if ($value->unit === '%') {
            return (float) $value->value / 100.0;
        }

        return (float) $value->value;
    }

    public function asString(?AstNode $value, string $context): string
    {
        if (! ($value instanceof StringNode)) {
            throw new MissingFunctionArgumentsException(($this->errorCtx)($context), 'string arguments');
        }

        return strtolower($value->value);
    }

    public function asPercentage(?AstNode $value, string $context): float
    {
        $value = $this->unwrapCalcNumber($value) ?? $value;

        if (! ($value instanceof NumberNode)) {
            throw new MissingFunctionArgumentsException(($this->errorCtx)($context), 'a percentage number');
        }

        if ($value->unit !== '%' && $value->unit !== null) {
            throw new MissingFunctionArgumentsException(($this->errorCtx)($context), 'percentage values');
        }

        return (float) $value->value;
    }

    public function unwrapCalcNumber(?AstNode $value): ?NumberNode
    {
        if (! ($value instanceof FunctionNode) || strtolower($value->name) !== 'calc') {
            return null;
        }

        if (count($value->arguments) !== 1) {
            return null;
        }

        $argument = $value->arguments[0];

        if ($argument instanceof NumberNode) {
            return $argument;
        }

        if (
            $argument instanceof ListNode
            && count($argument->items) === 1
            && $argument->items[0] instanceof NumberNode
        ) {
            return $argument->items[0];
        }

        return null;
    }

    public function asByte(AstNode $value, string $context): float
    {
        if ($value instanceof NumberNode && $value->unit === '%') {
            return $this->clamp((float) $value->value * 2.55, 255.0);
        }

        return $this->clamp($this->asNumber($value, $context), 255.0);
    }

    public function clamp(float $value, float $maxValue): float
    {
        return $this->colorSpaceConverter->clamp($value, $maxValue);
    }

    public function normalizeHue(float $hue): float
    {
        return $this->colorSpaceConverter->normalizeHue($hue);
    }

    public function isMissingChannelNode(AstNode $node): bool
    {
        return $node instanceof StringNode && strtolower($node->value) === 'none';
    }

    /**
     * @param array<int, AstNode> $arguments
     */
    public function isRelativeColorSyntax(array $arguments): bool
    {
        return isset($arguments[0])
            && $arguments[0] instanceof StringNode
            && strtolower($arguments[0]->value) === 'from';
    }

    /**
     * @param array<int, AstNode> $arguments
     */
    public function hasUnresolvableArguments(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof FunctionNode) {
                return true;
            }

            if ($argument instanceof StringNode && strtolower($argument->value) === 'none') {
                return true;
            }
        }

        return false;
    }

    public function callRef(string $context): string
    {
        $ctx = ($this->errorCtx)($context);

        return str_contains($ctx, '()') ? $ctx : $ctx . '()';
    }
}
