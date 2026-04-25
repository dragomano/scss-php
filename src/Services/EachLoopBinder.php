<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Values\ValueFactory;

use function count;

final readonly class EachLoopBinder implements EachLoopBinderInterface
{
    public function __construct(private ValueFactory $valueFactory) {}

    public function items(AstNode $iterableValue): array
    {
        if ($iterableValue instanceof ListNode || $iterableValue instanceof ArgumentListNode) {
            return $iterableValue->items;
        }

        if ($iterableValue instanceof MapNode) {
            $items = [];

            foreach ($iterableValue->pairs as $pair) {
                $items[] = new ListNode([$pair->key, $pair->value], 'space');
            }

            return $items;
        }

        return [$iterableValue];
    }

    public function assign(array $variables, AstNode $item, Environment $env): void
    {
        if (count($variables) === 1) {
            $env->getCurrentScope()->setVariable($variables[0], $item);

            return;
        }

        $values = [$item];

        if ($item instanceof ListNode || $item instanceof ArgumentListNode) {
            $values = $item->items;
        }

        foreach ($variables as $index => $name) {
            $env->getCurrentScope()->setVariable(
                $name,
                $values[$index] ?? $this->valueFactory->createNullNode(),
            );
        }
    }
}
