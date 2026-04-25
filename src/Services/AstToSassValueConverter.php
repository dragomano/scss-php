<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Values\SassValue;
use Bugo\SCSS\Values\ValueFactory;

final readonly class AstToSassValueConverter implements AstToSassValueConverterInterface
{
    public function __construct(
        private ValueFactory $valueFactory,
        private AstValueFormatterInterface $valueFormatter,
    ) {}

    public function convert(AstNode $node, Environment $env): SassValue
    {
        return $this->valueFactory->fromAst(
            $node,
            fn(AstNode $inner): string => $this->valueFormatter->format($inner, $env),
        );
    }
}
