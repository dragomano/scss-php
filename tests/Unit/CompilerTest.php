<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Contracts\Color\ColorSerializerInterface;

describe('Compiler', function () {
    it('accepts custom color serializer through constructor', function () {
        $serializer = new class () implements ColorSerializerInterface {
            public function serialize(string $value, bool $outputHexColors): string
            {
                return 'custom:' . $value . ':' . ($outputHexColors ? 'hex' : 'raw');
            }
        };

        $compiler = new Compiler(colorSerializer: $serializer);
        $css      = $compiler->compileString('.test { color: rgb(255, 0, 0); }');

        expect($css)->toBe(".test {\n  color: custom:rgb(255, 0, 0):raw;\n}\n");
    });
});
