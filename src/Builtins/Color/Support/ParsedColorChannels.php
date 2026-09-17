<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

use Bugo\SCSS\Nodes\AstNode;

final readonly class ParsedColorChannels
{
    /**
     * @param list<AstNode> $channels
     * @param list<AstNode>|null $commaArguments
     */
    public function __construct(
        public array $channels = [],
        public ?AstNode $alphaNode = null,
        public ?float $alphaValue = null,
        public ?array $commaArguments = null,
    ) {}

    /**
     * @psalm-assert-if-true list<AstNode> $this->commaArguments
     */
    public function isCommaForm(): bool
    {
        return $this->commaArguments !== null;
    }
}
