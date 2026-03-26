<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Contracts\Color\ColorSerializerInterface;
use Bugo\SCSS\Nodes\ColorNode;
use Tests\ReflectionAccessor;

describe('Compiler', function () {
    it('accepts custom color serializer through constructor', function () {
        $serializer = new class () implements ColorSerializerInterface {
            public function serialize(string $value, bool $outputHexColors): string
            {
                return 'custom:' . $value . ':' . ($outputHexColors ? 'hex' : 'raw');
            }
        };

        $compiler = new Compiler(colorSerializer: $serializer);
        $ctx      = (new ReflectionAccessor($compiler))->getProperty('ctx');

        expect($ctx->colorSerializer)->toBe($serializer)
            ->and($ctx->valueFactory->fromAst(new ColorNode('rgb(255, 0, 0)'))->toCss())
            ->toBe('custom:rgb(255, 0, 0):raw');
    });
});
