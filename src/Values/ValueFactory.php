<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\SCSS\Builtins\Color\ColorSerializerAdapter;
use Bugo\SCSS\Contracts\Color\ColorSerializerInterface;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

use function strrpos;
use function substr;

final readonly class ValueFactory
{
    public function __construct(
        private bool $outputHexColors = false,
        private ColorSerializerInterface $colorSerializer = new ColorSerializerAdapter()
    ) {}

    /**
     * @param null|callable(AstNode): string $formatter
     */
    public function fromAst(AstNode $node, ?callable $formatter = null, bool $preserveZeroUnits = false): SassValue
    {
        if ($node instanceof BooleanNode) {
            return SassBoolean::fromBool($node->value);
        }

        if ($node instanceof NullNode) {
            return SassNull::instance();
        }

        if ($node instanceof NumberNode) {
            return new SassNumber($node->value, $node->unit, $preserveZeroUnits);
        }

        if ($node instanceof ColorNode) {
            return new SassColor($node->value, $this->outputHexColors, $this->colorSerializer);
        }

        if ($node instanceof StringNode) {
            if ($node->quoted) {
                return new SassString($node->value, true);
            }

            return new SassString($node->value, false);
        }

        if ($node instanceof ListNode || $node instanceof ArgumentListNode) {
            $items = [];

            foreach ($node->items as $item) {
                $items[] = $this->fromAst($item, $formatter, $preserveZeroUnits)->toCss();
            }

            return new SassList($items, $node->separator, $node->bracketed, false);
        }

        if ($node instanceof MapNode) {
            $pairs = [];

            foreach ($node->pairs as $pair) {
                $pairs[] = [
                    'key'   => $this->fromAst($pair['key'], $formatter),
                    'value' => $this->fromAst($pair['value'], $formatter, $preserveZeroUnits),
                ];
            }

            return new SassMap($pairs);
        }

        if ($node instanceof FunctionNode) {
            if ($node->capturedScope !== null && $node->arguments === []) {
                return new SassFunctionRef($this->callableDisplayName($node->name));
            }

            $preserveNestedZeroUnits = $preserveZeroUnits || SassCalculation::isCalculationFunctionName($node->name);

            $arguments = [];
            foreach ($node->arguments as $argument) {
                $arguments[] = $this->fromAst($argument, $formatter, $preserveNestedZeroUnits);
            }

            return new SassCalculation($node->name, $arguments);
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

    private function callableDisplayName(string $name): string
    {
        $offset = strrpos($name, '.');

        if ($offset === false) {
            return $name;
        }

        return substr($name, $offset + 1);
    }

    public function createBooleanNode(bool $value): BooleanNode
    {
        return new BooleanNode($value);
    }

    public function createNullNode(): NullNode
    {
        return new NullNode();
    }
}
