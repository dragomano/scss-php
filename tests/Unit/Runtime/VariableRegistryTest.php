<?php

declare(strict_types=1);

use Bugo\SCSS\Runtime\VariableRegistry;

describe('VariableRegistry', function () {
    beforeEach(function () {
        $this->registry = new VariableRegistry();
    });

    it('stores and retrieves values', function () {
        $this->registry->set('color', 'red', 3);

        expect($this->registry->has('color'))->toBeTrue()
            ->and($this->registry->get('color'))->toBe('red')
            ->and($this->registry->getLine('color'))->toBe(3);
    });

    it('preserves null values as defined entries', function () {
        $this->registry->set('empty', null, 5);

        expect($this->registry->has('empty'))->toBeTrue()
            ->and($this->registry->get('empty'))->toBeNull()
            ->and($this->registry->getLine('empty'))->toBe(5);
    });

    it('throws when getting an unknown value', function () {
        expect(fn() => $this->registry->get('missing'))
            ->toThrow(LogicException::class);
    });

    it('returns all stored values', function () {
        $this->registry->set('first', 'a', 1);
        $this->registry->set('second', 'b', 2);

        expect($this->registry->all())->toBe([
            'first'  => 'a',
            'second' => 'b',
        ]);
    });
});
