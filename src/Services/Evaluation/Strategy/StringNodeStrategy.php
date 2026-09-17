<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyInterface;
use Bugo\SCSS\Utils\SelectorHelper;
use Bugo\SCSS\Utils\StringEscapeDecoder;
use Closure;

use function str_contains;
use function str_starts_with;
use function strlen;

final readonly class StringNodeStrategy implements EvaluationStrategyInterface
{
    /**
     * @param Closure(Environment): ?StringNode $getCurrentParentSelector
     * @param Closure(): AstNode $createNullNode
     * @param Closure(string, Environment, bool): string $replaceInterpolations
     */
    public function __construct(
        private Closure $getCurrentParentSelector,
        private Closure $createNullNode,
        private Closure $replaceInterpolations,
    ) {}

    public function supports(AstNode $node): bool
    {
        return $node instanceof StringNode;
    }

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        /** @var StringNode $node */
        if (! $node->quoted && $node->value === '&') {
            $selectorValue = ($this->getCurrentParentSelector)($env);

            if ($selectorValue !== null) {
                $items = [];

                foreach (SelectorHelper::splitList($selectorValue->value) as $selector) {
                    $components = [];

                    foreach (SelectorHelper::splitComponents($selector) as $component) {
                        $components[] = new StringNode($component, isSelectorValue: true);
                    }

                    $complex = new ListNode($components, 'space', isComputed: true);

                    $items[] = $complex;
                }

                return new ListNode($items, 'comma', isComputed: true);
            }

            return ($this->createNullNode)();
        }

        if (! str_contains($node->value, '#{')) {
            return $node;
        }

        if (! $node->quoted && $this->isPureInterpolation($node->value)) {
            return new StringNode(
                ($this->replaceInterpolations)($node->value, $env, false),
                false,
                isSpecialString: true,
            );
        }

        return new StringNode(
            ($this->replaceInterpolations)($node->value, $env, $node->quoted),
            $node->quoted,
        );
    }

    private function isPureInterpolation(string $value): bool
    {
        if (! str_starts_with($value, '#{')) {
            return false;
        }

        return StringEscapeDecoder::skipInterpolation($value, 1) === strlen($value);
    }
}
