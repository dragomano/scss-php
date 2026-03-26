<?php

declare(strict_types=1);

use Bugo\SCSS\Runtime\CallableDefinition;
use Bugo\SCSS\Runtime\CallableDefinitionMap;
use Bugo\SCSS\Runtime\Scope;

describe('CallableDefinitionMap', function () {
    beforeEach(function () {
        $this->map = new CallableDefinitionMap();
        $this->def = new CallableDefinition([], [], new Scope(), 1);
    });

    it('returns null for unknown name', function () {
        expect($this->map->get('unknown'))->toBeNull();
    });

    it('has() returns false for unknown name', function () {
        expect($this->map->has('fn'))->toBeFalse();
    });

    it('set() stores and get() retrieves a definition', function () {
        $this->map->set('myfn', $this->def);

        expect($this->map->get('myfn'))->toBe($this->def);
    });

    it('has() returns true after set()', function () {
        $this->map->set('myfn', $this->def);

        expect($this->map->has('myfn'))->toBeTrue();
    });

    it('set() overwrites existing definition', function () {
        $other = new CallableDefinition([], [], new Scope(), 5);

        $this->map->set('myfn', $this->def);
        $this->map->set('myfn', $other);

        expect($this->map->get('myfn'))->toBe($other);
    });

    it('getIterator() yields all stored definitions', function () {
        $defA = new CallableDefinition([], [], new Scope(), 1);
        $defB = new CallableDefinition([], [], new Scope(), 2);

        $this->map->set('a', $defA);
        $this->map->set('b', $defB);

        $collected = [];
        foreach ($this->map as $name => $def) {
            $collected[$name] = $def;
        }

        expect($collected)->toBe(['a' => $defA, 'b' => $defB]);
    });

    it('is empty by default', function () {
        $collected = iterator_to_array($this->map);

        expect($collected)->toBe([]);
    });
});
