<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\MediaQuery;

describe('parseList()', function () {
    it('returns null for a blank prelude', function () {
        expect(MediaQuery::parseList(''))->toBeNull();
    });

    it('returns null for a prelude with an empty part', function () {
        expect(MediaQuery::parseList('screen, '))->toBeNull();
    });

    it('returns null when a condition segment is empty', function () {
        expect(MediaQuery::parseList('(a) and and (b)'))->toBeNull();
    });

    it('returns null when the query does not start with an identifier', function () {
        expect(MediaQuery::parseList('9px'))->toBeNull();
    });

    it('returns null when not is followed by a non-identifier token', function () {
        expect(MediaQuery::parseList('not 5'))->toBeNull();
    });

    it('returns null when not precedes malformed conditions', function () {
        expect(MediaQuery::parseList('not (a) and and (b)'))->toBeNull();
    });

    it('returns null when the modifier type is followed by a foreign keyword', function () {
        expect(MediaQuery::parseList('only screen foo'))->toBeNull();
    });

    it('returns null when and is not followed by conditions', function () {
        expect(MediaQuery::parseList('screen and'))->toBeNull();
    });

    it('returns null when and is followed by malformed conditions', function () {
        expect(MediaQuery::parseList('screen and (a) and and (b)'))->toBeNull();
    });

    it('keeps commas, and keywords and escaped quotes inside strings intact', function () {
        $queries = MediaQuery::parseList('(content: "a and b, c\"")');

        expect($queries)->toBeArray()->toHaveCount(1)
            ->and($queries[0]->conditions)->toBe(['(content: "a and b, c\"")']);
    });

    it('does not treat a leading a that is not and as a boundary', function () {
        $queries = MediaQuery::parseList('screen and apple (b)');

        expect($queries)->toBeArray()->toHaveCount(1)
            ->and($queries[0]->toString())->toBe('screen and apple (b)');
    });

    it('does not treat and without preceding whitespace as a boundary', function () {
        $queries = MediaQuery::parseList('screen and (a)and (b)');

        expect($queries[0]->toString())->toBe('screen and (a)and (b)');
    });

    it('does not treat and at the condition start as a boundary', function () {
        $queries = MediaQuery::parseList('screen and and(b)');

        expect($queries[0]->toString())->toBe('screen and and(b)');
    });

    it('does not treat and without trailing whitespace as a boundary', function () {
        $queries = MediaQuery::parseList('screen and (a) andx (b)');

        expect($queries[0]->toString())->toBe('screen and (a) andx (b)');
    });

    it('does not treat and at the very end as a boundary', function () {
        $queries = MediaQuery::parseList('screen and (a) and');

        expect($queries[0]->toString())->toBe('screen and (a) and');
    });

    it('keeps a non-hex escape inside an identifier', function () {
        $queries = MediaQuery::parseList('sc\reen');

        expect($queries[0]->type)->toBe('sc\reen');
    });
})->covers(MediaQuery::class);

describe('toString()', function () {
    it('serializes modifier, type and conditions', function () {
        expect((new MediaQuery('only', 'screen', ['(color)', '(min-width: 10px)']))->toString())
            ->toBe('only screen and (color) and (min-width: 10px)');
    });

    it('serializes a query without conditions', function () {
        expect((new MediaQuery(null, 'print', []))->toString())->toBe('print');
    });
})->covers(MediaQuery::class);

describe('matchesAllTypes()', function () {
    it('reports whether the query matches all media types', function () {
        expect((new MediaQuery(null, null, ['(color)']))->matchesAllTypes())->toBeTrue()
            ->and((new MediaQuery(null, 'ALL', []))->matchesAllTypes())->toBeTrue()
            ->and((new MediaQuery(null, 'screen', []))->matchesAllTypes())->toBeFalse();
    });
})->covers(MediaQuery::class);

describe('serializeList()', function () {
    it('joins serialized queries with a comma', function () {
        expect(MediaQuery::serializeList([
            new MediaQuery(null, 'screen', []),
            new MediaQuery(null, null, ['(color)']),
        ]))->toBe('screen, (color)');
    });
})->covers(MediaQuery::class);

describe('mergeLists()', function () {
    it('merges compatible queries', function () {
        $outer = MediaQuery::parseList('screen and (color)');
        $inner = MediaQuery::parseList('(min-width: 10px)');

        expect(MediaQuery::mergeLists($outer, $inner)[0]->toString())
            ->toBe('screen and (color) and (min-width: 10px)');
    });

    it('returns an empty list for contradictory queries', function () {
        $outer = MediaQuery::parseList('not screen');
        $inner = MediaQuery::parseList('screen');

        expect(MediaQuery::mergeLists($outer, $inner))->toBe([]);
    });

    it('returns null for incompatible queries', function () {
        $outer = MediaQuery::parseList('not screen and (color)');
        $inner = MediaQuery::parseList('screen and (width)');

        expect(MediaQuery::mergeLists($outer, $inner))->toBeNull();
    });
})->covers(MediaQuery::class);
