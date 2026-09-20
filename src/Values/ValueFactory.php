<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\Iris\Serializers\Serializer;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\FunctionRefNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Utils\SlashOperatorCompactor;

use function array_values;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

final readonly class ValueFactory
{
    public function __construct(
        private Serializer $colorSerializer = new Serializer(),
        private bool $compressed = false,
    ) {}

    /**
     * @param null|callable(AstNode): string $formatter
     */
    public function fromAst(
        AstNode $node,
        ?callable $formatter = null,
        bool $compactSlash = true,
    ): SassValue {
        if ($node instanceof BooleanNode) {
            return SassBoolean::fromBool($node->value);
        }

        if ($node instanceof NullNode) {
            return SassNull::instance();
        }

        if ($node instanceof NumberNode) {
            return new SassNumber($node->value, $node->unit, $this->compressed);
        }

        if ($node instanceof ColorNode) {
            return new SassColor($node->value, $this->colorSerializer, $this->compressed);
        }

        if ($node instanceof StringNode) {
            return new SassString($node->value, $node->quoted);
        }

        if ($node instanceof FunctionRefNode) {
            return new SassFunctionRef($this->callableDisplayName($node->name));
        }

        if ($node instanceof ListNode || $node instanceof ArgumentListNode) {
            $items = [];

            foreach ($node->items as $item) {
                $items[] = $this->fromAst($item, $formatter, $compactSlash)->toCss();
            }

            $separator = $node->separator;

            if ($separator === 'space' && $compactSlash) {
                $items = SlashOperatorCompactor::compact($node->items, $items);
            }

            return new SassList(array_values($items), $separator, $node->bracketed);
        }

        if ($node instanceof MapNode) {
            $pairs = [];

            foreach ($node->pairs as $pair) {
                $pairs[] = [
                    'key'   => $this->fromAst($pair->key, $formatter),
                    'value' => $this->fromAst($pair->value, $formatter),
                ];
            }

            return new SassMap($pairs);
        }

        if ($node instanceof FunctionNode) {
            if ($node->capturedScope !== null && $node->arguments === []) {
                return new SassFunctionRef($this->callableDisplayName($node->name));
            }

            $isCalculation = SassCalculation::isCalculationFunctionName($node->name);
            $arguments     = [];

            $compactSlashArguments = $compactSlash && ! $isCalculation;

            if (
                $compactSlashArguments
                && strtolower($node->name) === 'color'
                && ! $this->isColorFromSyntax($node)
            ) {
                $compactSlashArguments = false;
            }

            foreach ($node->arguments as $argument) {
                $arguments[] = $this->fromAst($argument, $formatter, $compactSlashArguments);
            }

            $name = $isCalculation ? strtolower($node->name) : $node->name;

            return new SassCalculation($name, $arguments);
        }

        if ($node instanceof MixinRefNode) {
            return new SassMixin($this->callableDisplayName($node->name));
        }

        if ($formatter !== null) {
            /** @var string $formatted */
            $formatted = $formatter($node);

            return new SassString($formatted);
        }

        return SassBoolean::fromBool(true);
    }

    public function createBooleanNode(bool $value): BooleanNode
    {
        return new BooleanNode($value);
    }

    public function createNullNode(): NullNode
    {
        return new NullNode();
    }

    private function callableDisplayName(string $name): string
    {
        $offset = strrpos($name, '.');

        if ($offset === false) {
            return $name;
        }

        return substr($name, $offset + 1);
    }

    private function isColorFromSyntax(FunctionNode $node): bool
    {
        $first = $node->arguments[0] ?? null;

        if ($first instanceof ListNode) {
            $first = $first->items[0] ?? null;
        }

        return $first instanceof StringNode
            && ! $first->quoted
            && strtolower(trim($first->value)) === 'from';
    }
}
