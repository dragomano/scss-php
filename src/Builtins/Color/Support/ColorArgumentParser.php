<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

use Bugo\Iris\Converters\SpaceConverter;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
use Bugo\SCSS\Exceptions\InvalidColorChannelsException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Values\AstValueInspector;

use function array_merge;
use function array_slice;
use function array_values;
use function count;
use function ctype_digit;
use function implode;
use function in_array;
use function is_finite;
use function is_nan;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function substr_count;
use function trim;

use const M_PI;

final readonly class ColorArgumentParser
{
    private const SPECIAL_NUMBER_FUNCTIONS = ['var', 'env', 'attr', 'calc', 'clamp', 'min', 'max'];

    public function __construct(
        private SpaceConverter $colorSpaceConverter,
        private ColorModuleContext $context,
    ) {}

    /**
     * @param array<int, AstNode> $positional
     */
    public function requireColor(array $positional, int $index, string $context): AstNode
    {
        if (! isset($positional[$index])) {
            throw MissingFunctionArgumentsException::required($this->context->errorCtx($context), 'color');
        }

        $value = $this->unwrapOutOfGamutColorMix($positional[$index]);

        if ($value instanceof FunctionNode && ! $this->isColorFunction($value->name)) {
            throw new MissingFunctionArgumentsException($this->context->errorCtx($context), 'color arguments');
        }

        if (! ($value instanceof ColorNode || $value instanceof StringNode || $value instanceof FunctionNode)) {
            throw new MissingFunctionArgumentsException($this->context->errorCtx($context), 'color arguments');
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
                    $missingFunctionArgumentsException,
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

        return true;
    }

    /**
     * @param array<int, AstNode> $positional
     * @return array<int, AstNode>
     */
    public function parseFunctionalColorArguments(
        array $positional,
        string $context,
        int $minArguments,
        bool $allowMissingChannels = false,
    ): array {
        $arguments = $this->expandSingleSpaceListArgument(
            $this->expandSingleSlashListArgument($positional),
        );

        if (
            $this->isRelativeColorSyntax($arguments)
            || $this->hasUnresolvableArguments($arguments, $allowMissingChannels)
        ) {
            throw new DeferToCssFunctionException(
                $this->callRef($context) . ' should be emitted as a CSS function.',
            );
        }

        $arguments = $this->extractSlashAlpha($arguments);

        if (count($arguments) < $minArguments) {
            throw MissingFunctionArgumentsException::count($this->context->errorCtx($context), $minArguments);
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
                    array_slice($arguments, $i + 1),
                );
            }

            if ($argument instanceof ListNode && ! $argument->bracketed && count($argument->items) === 3) {
                $triple = $argument->items;

                if ($triple[1] instanceof StringNode && $triple[1]->value === '/') {
                    return array_merge(
                        array_slice($arguments, 0, $i),
                        [$triple[0], $triple[2]],
                        array_slice($arguments, $i + 1),
                    );
                }
            }
        }

        return $arguments;
    }

    /**
     * @param list<string> $channelNames
     * @throws DeferToCssFunctionException when the input must be emitted verbatim
     * @throws InvalidColorChannelsException when the input is structurally invalid
     */
    public function parseChannels(
        string $function,
        string $argument,
        string $space,
        array $channelNames,
        AstNode $input,
    ): ParsedColorChannels {
        if ($this->isVarNode($input) || $this->isModernCssIfFunction($input)) {
            throw new DeferToCssFunctionException(
                $this->callRef($function) . ' should be emitted as a CSS function.',
            );
        }

        $slashSplit = $this->parseChannelList($input);

        if ($slashSplit === null) {
            throw new DeferToCssFunctionException(
                $this->callRef($function) . ' should be emitted as a CSS function.',
            );
        }

        $components = $slashSplit['components'];
        $alphaNode  = $slashSplit['alpha'];

        if ($components instanceof ListNode) {
            if ($components->items === []) {
                throw InvalidColorChannelsException::emptyList($argument);
            }

            if ($components->bracketed) {
                throw InvalidColorChannelsException::bracketed($argument, $this->renderForError($components));
            }

            if ($components->separator === 'comma') {
                throw InvalidColorChannelsException::wrongSeparator($argument, $this->renderForError($components));
            }

            $items = array_values($components->items);
        } else {
            $items = [$components];
        }

        $first = $items[0];

        if ($first instanceof StringNode && ! $first->quoted && strtolower(trim($first->value)) === 'from') {
            throw new DeferToCssFunctionException(
                $this->callRef($function) . ' should be emitted as a CSS function.',
            );
        }

        if (count($items) === 1 && $this->isVarNode($first)) {
            $channels = [$first];
        } else {
            $channels = $items;
        }

        foreach ($channels as $index => $channel) {
            if (
                ! $channel instanceof NumberNode
                && ! AstValueInspector::isNoneKeyword($channel)
                && ! $this->isSpecialNumberNode($channel)
            ) {
                throw InvalidColorChannelsException::channelType(
                    $argument,
                    $channelNames[$index] ?? 'channel ' . ($index + 1),
                    $this->renderForError($channel),
                );
            }
        }

        if ($alphaNode !== null && $this->isSpecialNumberNode($alphaNode)) {
            if (count($channels) !== 3) {
                throw new DeferToCssFunctionException(
                    $this->callRef($function) . ' should be emitted as a CSS function.',
                );
            }

            return new ParsedColorChannels(commaArguments: [...$channels, $alphaNode]);
        }

        $alphaValue = $this->resolveChannelAlpha($argument, $alphaNode);

        foreach ($channels as $channel) {
            if ($this->isSpecialNumberNode($channel)) {
                if (count($channels) !== 3) {
                    throw new DeferToCssFunctionException(
                        $this->callRef($function) . ' should be emitted as a CSS function.',
                    );
                }

                $arguments = $channels;

                if ($alphaNode !== null) {
                    $arguments[] = $alphaNode;
                }

                return new ParsedColorChannels(commaArguments: $arguments);
            }
        }

        if (count($channels) !== 3) {
            throw InvalidColorChannelsException::channelCount(
                $space,
                $argument,
                $this->renderForError($input),
                count($channels),
            );
        }

        return new ParsedColorChannels(channels: $channels, alphaNode: $alphaNode, alphaValue: $alphaValue);
    }

    /**
     * @return array{components: AstNode, alpha: AstNode|null}|null null when ambiguous
     */
    public function parseChannelList(AstNode $input): ?array
    {
        if ($input instanceof ListNode && $input->separator === 'slash') {
            if (count($input->items) !== 2) {
                throw InvalidColorChannelsException::slashElementCount('channels', count($input->items));
            }

            return ['components' => $input->items[0], 'alpha' => $input->items[1]];
        }

        if ($input instanceof ListNode && $input->separator === 'space') {
            foreach ($input->items as $index => $item) {
                if ($item instanceof StringNode && ! $item->quoted && trim($item->value) === '/') {
                    $before = array_slice($input->items, 0, $index);
                    $after  = array_slice($input->items, $index + 1);
                    $alpha  = $after[0] ?? null;

                    return [
                        'components' => new ListNode([...$before, ...array_slice($after, 1)], 'space'),
                        'alpha'      => $alpha,
                    ];
                }
            }

            $last = $input->items === [] ? null : $input->items[count($input->items) - 1];

            if ($last instanceof ListNode && count($last->items) === 3 && ! $last->bracketed) {
                $triple = $last->items;

                if (
                    $triple[1] instanceof StringNode
                    && ! $triple[1]->quoted
                    && trim($triple[1]->value) === '/'
                ) {
                    $items   = array_slice($input->items, 0, -1);
                    $items[] = $triple[0];

                    return [
                        'components' => new ListNode($items, 'space'),
                        'alpha'      => $triple[2],
                    ];
                }
            }

            if ($last instanceof StringNode && ! $last->quoted) {
                $slashPosition = strpos($last->value, '/');

                if ($slashPosition !== false) {
                    if (substr_count($last->value, '/') > 1) {
                        return null;
                    }

                    $items   = array_slice($input->items, 0, -1);
                    $items[] = $this->parseNumberOrString(substr($last->value, 0, $slashPosition));

                    return [
                        'components' => new ListNode($items, 'space'),
                        'alpha'      => $this->parseNumberOrString(substr($last->value, $slashPosition + 1)),
                    ];
                }
            }
        }

        return ['components' => $input, 'alpha' => null];
    }

    public function isSpecialNumberNode(AstNode $node): bool
    {
        if ($node instanceof FunctionNode) {
            return in_array(strtolower($node->name), self::SPECIAL_NUMBER_FUNCTIONS, true);
        }

        if ($node instanceof StringNode && ! $node->quoted) {
            $value = strtolower(trim($node->value));

            foreach (self::SPECIAL_NUMBER_FUNCTIONS as $function) {
                if (str_starts_with($value, $function . '(')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isVarNode(AstNode $node): bool
    {
        if ($node instanceof FunctionNode) {
            return strtolower($node->name) === 'var';
        }

        return $node instanceof StringNode
            && ! $node->quoted
            && str_starts_with(strtolower(trim($node->value)), 'var(--');
    }

    public function asLenientPercentage(?AstNode $value, string $context): float
    {
        $value = $this->unwrapCalcNumber($value) ?? $value;

        if (! ($value instanceof NumberNode)) {
            throw new MissingFunctionArgumentsException(
                $this->context->errorCtx($context),
                'number arguments',
            );
        }

        return (float) $value->value;
    }

    public function renderForError(AstNode $node): string
    {
        if ($node instanceof ListNode) {
            $joiner = match ($node->separator) {
                'comma' => ', ',
                'slash' => ' / ',
                default => ' ',
            };

            $rendered = implode($joiner, array_map($this->renderForError(...), $node->items));

            if ($node->bracketed) {
                return "[$rendered]";
            }

            return "($rendered)";
        }

        if ($node instanceof NumberNode) {
            return (string) $node;
        }

        if ($node instanceof StringNode) {
            return $node->quoted ? '"' . $node->value . '"' : $node->value;
        }

        if ($node instanceof FunctionNode) {
            $arguments = implode(', ', array_map($this->renderForError(...), $node->arguments));

            return $node->name . '(' . $arguments . ')';
        }

        if ($node instanceof ColorNode) {
            return $node->value;
        }

        return '';
    }

    /**
     * @param array<int, AstNode> $arguments
     */
    public function parseAlphaOrDefault(array $arguments, int $index, string $context): float
    {
        if (! isset($arguments[$index])) {
            return 1.0;
        }

        return $this->parseAlphaNode($arguments[$index], $context);
    }

    public function parseAlphaNode(AstNode $arg, string $context): float
    {
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
            throw new MissingFunctionArgumentsException(
                $this->context->errorCtx($context),
                'number arguments',
            );
        }

        return (float) $value->value;
    }

    public function asHueAngle(?AstNode $value, string $context): float
    {
        if (! ($value instanceof NumberNode)) {
            throw new MissingFunctionArgumentsException(
                $this->context->errorCtx($context),
                'number arguments',
            );
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
            throw new MissingFunctionArgumentsException(
                $this->context->errorCtx($context),
                'number arguments',
            );
        }

        $v = (float) $value->value;

        return $value->unit === '%' ? ($v / 100.0) * $range : $v;
    }

    public function asColorChannel(?AstNode $value): float
    {
        if (! ($value instanceof NumberNode)) {
            throw new MissingFunctionArgumentsException(
                $this->context->errorCtx('color'),
                'number arguments',
            );
        }

        if (! is_finite((float) $value->value)) {
            throw new DeferToCssFunctionException($this->context->errorCtx('color'));
        }

        if ($value->unit === '%') {
            return (float) $value->value / 100.0;
        }

        return (float) $value->value;
    }

    public function asString(?AstNode $value, string $context): string
    {
        if (! ($value instanceof StringNode)) {
            throw new MissingFunctionArgumentsException(
                $this->context->errorCtx($context),
                'string arguments',
            );
        }

        return strtolower($value->value);
    }

    public function asPercentage(?AstNode $value, string $context): float
    {
        $value = $this->unwrapCalcNumber($value) ?? $value;

        if (! ($value instanceof NumberNode)) {
            throw new MissingFunctionArgumentsException(
                $this->context->errorCtx($context),
                'a percentage number',
            );
        }

        if ($value->unit !== '%' && $value->unit !== null) {
            throw new MissingFunctionArgumentsException(
                $this->context->errorCtx($context),
                'percentage values',
            );
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
        if (is_nan($value)) {
            return 0.0;
        }

        return $this->colorSpaceConverter->clamp($value, $maxValue);
    }

    public function normalizeHue(float $hue): float
    {
        return $this->colorSpaceConverter->normalizeHue($hue);
    }

    public function isMissingChannelNode(AstNode $node): bool
    {
        return AstValueInspector::isNoneKeyword($node);
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
    public function hasUnresolvableArguments(array $arguments, bool $allowMissingChannels = false): bool
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof FunctionNode) {
                return true;
            }

            if ($argument instanceof StringNode && ! $argument->quoted && str_contains($argument->value, '(')) {
                return true;
            }

            if (! $allowMissingChannels && AstValueInspector::isNoneKeyword($argument)) {
                return true;
            }
        }

        return false;
    }

    public function callRef(string $context): string
    {
        $ctx = $this->context->errorCtx($context);

        return str_contains($ctx, '()') ? $ctx : $ctx . '()';
    }

    /**
     * @param array<int, AstNode> $positional
     * @return array<int, AstNode>
     */
    private function expandSingleSlashListArgument(array $positional): array
    {
        if (count($positional) !== 1) {
            return $positional;
        }

        $list = $positional[0];

        if (
            ! ($list instanceof ListNode)
            || $list->separator !== 'slash'
            || $list->bracketed
            || count($list->items) !== 2
        ) {
            return $positional;
        }

        $channels = $list->items[0];

        if (! ($channels instanceof ListNode) || $channels->separator !== 'space' || $channels->bracketed) {
            return $positional;
        }

        return [...$channels->items, new StringNode('/'), $list->items[1]];
    }

    private function isColorFunction(string $name): bool
    {
        return in_array(strtolower($name), [
            'rgb', 'rgba', 'hsl', 'hsla', 'hwb',
            'color', 'lab', 'lch', 'oklab', 'oklch',
        ], true);
    }

    private function isModernCssIfFunction(AstNode $node): bool
    {
        if ($node instanceof FunctionNode) {
            return strtolower($node->name) === 'if' && $node->modernSyntax;
        }

        if (! ($node instanceof StringNode) || $node->quoted) {
            return false;
        }

        $value = strtolower($node->value);

        return str_starts_with($value, 'if(')
            && str_ends_with($value, ')')
            && (str_contains($value, ':') || str_contains($value, ';'));
    }

    private function resolveChannelAlpha(string $argument, ?AstNode $alphaNode): ?float
    {
        if ($alphaNode === null) {
            return 1.0;
        }

        if (AstValueInspector::isNoneKeyword($alphaNode)) {
            return null;
        }

        if (! ($alphaNode instanceof NumberNode)) {
            throw InvalidColorChannelsException::alphaType($argument, $this->renderForError($alphaNode));
        }

        $value = (float) $alphaNode->value;

        if ($alphaNode->unit === '%') {
            $value /= 100.0;
        }

        return $this->clamp($value, 1.0);
    }

    private function parseNumberOrString(string $text): AstNode
    {
        $number = $this->parseNumericPrefix($text);

        if ($number !== null) {
            return $number;
        }

        return new StringNode($text);
    }

    private function parseNumericPrefix(string $text): ?NumberNode
    {
        $length = strlen($text);
        $index  = 0;

        if ($index < $length && ($text[$index] === '+' || $text[$index] === '-')) {
            $index++;
        }

        $hasDigits = false;

        while ($index < $length && ctype_digit($text[$index])) {
            $index++;
            $hasDigits = true;
        }

        if ($index < $length && $text[$index] === '.') {
            $index++;

            while ($index < $length && ctype_digit($text[$index])) {
                $index++;
                $hasDigits = true;
            }
        }

        if (! $hasDigits) {
            return null;
        }

        $numberPart = substr($text, 0, $index);
        $unitPart   = substr($text, $index);

        $value = str_contains($numberPart, '.') ? (float) $numberPart : (int) $numberPart;

        return new NumberNode($value, $unitPart === '' ? null : $unitPart);
    }

    private function unwrapOutOfGamutColorMix(AstNode $node): AstNode
    {
        if (! ($node instanceof FunctionNode) || strtolower($node->name) !== 'color-mix') {
            return $node;
        }

        $arguments = $node->arguments;

        if (
            count($arguments) !== 3
            || ! ($arguments[0] instanceof ListNode)
            || ! ($arguments[1] instanceof ListNode)
            || count($arguments[0]->items) !== 2
            || count($arguments[1]->items) !== 2
            || ! ($arguments[0]->items[0] instanceof StringNode)
            || strtolower($arguments[0]->items[0]->value) !== 'in'
            || ! ($arguments[1]->items[0] instanceof FunctionNode)
            || ! ($arguments[1]->items[1] instanceof NumberNode)
            || (float) $arguments[1]->items[1]->value !== 100.0
            || ! ($arguments[2] instanceof StringNode)
        ) {
            return $node;
        }

        return $arguments[1]->items[0];
    }
}
