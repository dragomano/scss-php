<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\ColorAstParser;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\StringNode;

describe('ColorAstParser', function () {
    beforeEach(function () {
        $this->parser = new ColorAstParser();
    });

    it('parses functional color strings into function nodes', function () {
        $parsed = $this->parser->parse('rgb(255, 0, 0)');

        expect($parsed)->toBeInstanceOf(FunctionNode::class)
            ->and($parsed?->name)->toBe('rgb');
    });

    it('parses hex color strings into color nodes', function () {
        $parsed = $this->parser->parse('#abc');

        expect($parsed)->toBeInstanceOf(ColorNode::class)
            ->and($parsed?->value)->toBe('#abc');
    });

    it('returns null for plain non-color strings', function () {
        expect($this->parser->parse('plain-text'))->toBeNull();
    });

    it('reparses invalid unquoted url arguments as inline string values', function () {
        $parsed = $this->parser->parse('url(foo+bar)');

        expect($parsed)->toBeInstanceOf(FunctionNode::class)
            ->and($parsed?->name)->toBe('url')
            ->and($parsed?->arguments)->toHaveCount(1)
            ->and($parsed?->arguments[0])->toBeInstanceOf(StringNode::class)
            ->and($parsed?->arguments[0]->value)->toBe('foo+bar');
    });
});
