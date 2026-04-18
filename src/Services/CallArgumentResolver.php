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
use Closure;

use function array_filter;
use function array_values;
use function str_ends_with;
use function str_starts_with;
use function trim;

final readonly class CallArgumentResolver
{
    /**
     * @param Closure(AstNode, Environment): AstNode $evaluateValue
     */
    public function __construct(
        private ParserInterface $parser,
        private CssArgumentEvaluator $cssArgument,
        private Closure $evaluateValue,
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
        $named      = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof SpreadArgumentNode) {
                $spread = ($this->evaluateValue)($argument->value, $env);

                foreach ($this->cssArgument->expandSpreadValue($spread) as $spreadArgument) {
                    if ($spreadArgument instanceof NamedArgumentNode) {
                        $named[$spreadArgument->name] = $spreadArgument->value;

                        continue;
                    }

                    $positional[] = $spreadArgument;
                }

                continue;
            }

            if ($argument instanceof NamedArgumentNode) {
                $named[$argument->name] = ($this->evaluateValue)($argument->value, $env);

                continue;
            }

            $positional[] = ($this->evaluateValue)($argument, $env);
        }

        return [$positional, $named];
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
