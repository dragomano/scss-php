<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;

use function array_filter;
use function array_merge;
use function array_values;
use function str_ends_with;
use function str_starts_with;
use function trim;

final readonly class CallArgumentResolver
{
    public function __construct(
        private ParserInterface $parser,
        private CssArgumentEvaluator $cssArgument,
        private AstValueEvaluatorInterface $valueEvaluator,
    ) {}

    /**
     * @return array<int, AstNode>
     */
    public function parseContentCallArguments(string $prelude): array
    {
        $prelude = trim($prelude);

        if ($prelude === '' || ! str_starts_with($prelude, '(') || ! str_ends_with($prelude, ')')) {
            return [];
        }

        $ast = $this->parser->parse(".__content__ { __content_call__: __content__$prelude; }");

        $firstChild = $ast->children[0] ?? null;

        if (! $firstChild instanceof RuleNode) {
            return [];
        }

        $firstRuleChild = $firstChild->children[0] ?? null;

        if (
            $firstRuleChild instanceof DeclarationNode
            && $firstRuleChild->value instanceof FunctionNode
            && $firstRuleChild->value->name === '__content__'
        ) {
            return $firstRuleChild->value->arguments;
        }

        return [];
    }

    /**
     * @param mixed $value
     * @return array<int, AstNode>
     */
    public function extractAstNodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn(mixed $node): bool => $node instanceof AstNode,
        ));
    }

    /**
     * @param mixed $value
     * @return array<int, ArgumentNode>
     */
    public function extractArgumentNodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn(mixed $node): bool => $node instanceof ArgumentNode,
        ));
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array{0: array<int, AstNode>, 1: array<string, AstNode>}
     */
    public function resolveCallArguments(array $arguments, Environment $env): array
    {
        $positional = [];
        $spread     = [];
        $named      = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof SpreadArgumentNode) {
                $expanded = $this->cssArgument->expandSpreadValue($this->valueEvaluator->evaluate($argument->value, $env));

                foreach ($expanded as $spreadArgument) {
                    if ($spreadArgument instanceof NamedArgumentNode) {
                        $named[$spreadArgument->name] = $this->valueEvaluator->evaluate($spreadArgument->value, $env);

                        continue;
                    }

                    $spread[] = $this->valueEvaluator->evaluate($spreadArgument, $env);
                }

                continue;
            }

            if ($argument instanceof NamedArgumentNode) {
                $named[$argument->name] = $this->valueEvaluator->evaluate($argument->value, $env);

                continue;
            }

            $positional[] = $this->valueEvaluator->evaluate($argument, $env);
        }

        return [array_merge($positional, $spread), $named];
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function expandCallArguments(array $arguments, Environment $env): array
    {
        return $this->cssArgument->expandCallArguments($arguments, $env);
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, AstNode>
     */
    public function expandCssCallArguments(array $arguments, Environment $env): array
    {
        return $this->cssArgument->expandCssCallArguments($arguments, $env);
    }
}
