<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\SourceMapGenerator;
use Bugo\SCSS\Utils\SourceMapMapping;
use Bugo\SCSS\Utils\SourceMapOptions;
use Bugo\SCSS\Utils\SourceMapPosition;
use Tests\ReflectionAccessor;

beforeEach(function () {
    $this->generator = new SourceMapGenerator();
    $this->accessor  = new ReflectionAccessor($this->generator);
});

function mapping(
    int $generatedLine,
    int $generatedColumn,
    int $originalLine,
    int $originalColumn,
    int $sourceIndex = 0
): SourceMapMapping {
    return new SourceMapMapping(
        new SourceMapPosition($generatedLine, $generatedColumn),
        new SourceMapPosition($originalLine, $originalColumn),
        $sourceIndex,
    );
}

function sourceMapOptions(
    int $outputLines = 0,
    bool $includeSources = false,
    string $sourceContent = '',
    string $sourceMapRoot = '',
    array $sources = []
): SourceMapOptions {
    return new SourceMapOptions(
        outputLines: $outputLines,
        sourceMapRoot: $sourceMapRoot,
        sourceContent: $sourceContent,
        includeSources: $includeSources,
        sources: $sources,
    );
}

describe('source map generation', function () {
    it('provides backward compatibility without options', function () {
        $mappings = [
            mapping(1, 0, 1, 0),
        ];

        $result = $this->generator->generate(
            $mappings,
            'input.scss',
            'output.css',
            sourceMapOptions()
        );

        $sourceMap = json_decode($result, true);

        expect($sourceMap)->toHaveKey('version')
            ->and($sourceMap)->toHaveKey('sources')
            ->and($sourceMap)->toHaveKey('mappings')
            ->and($sourceMap['mappings'])->toBe(';AACA')
            ->and($sourceMap)->not->toHaveKey('sourcesContent');
    });

    it('includes sourcesContent when includeSources is true', function () {
        $mappings = [
            mapping(1, 0, 1, 0),
        ];
        $result = $this->generator->generate(
            $mappings,
            'input.scss',
            'output.css',
            sourceMapOptions(includeSources: true)
        );

        $sourceMap = json_decode($result, true);

        expect($sourceMap)->toHaveKey('sourcesContent')
            ->and($sourceMap['sourcesContent'])->toBe(['']);
    });
})->covers(SourceMapGenerator::class);

describe('mappings generation', function () {
    it('adds comma between multiple mappings on the same line', function () {
        $mappings = [
            mapping(1, 0, 1, 0),
            mapping(1, 10, 1, 10),
        ];

        $result = $this->generator->generate(
            $mappings,
            'source.scss',
            'output.css',
            sourceMapOptions()
        );
        $sourceMap = json_decode($result, true);

        expect($sourceMap)->toBeArray()
            ->and($sourceMap['mappings'])->toContain(',');
    });

    it('handles multiple mappings on same line after empty lines', function () {
        $mappings = [
            mapping(1, 0, 1, 0),
            mapping(5, 0, 2, 0),
            mapping(5, 10, 2, 10),
        ];

        $result = $this->generator->generate(
            $mappings,
            'source.scss',
            'output.css',
            sourceMapOptions()
        );
        $sourceMap = json_decode($result, true);

        expect($sourceMap)->toBeArray()
            ->and($sourceMap['mappings'])->toContain(',')
            ->and($sourceMap['mappings'])->toMatch('/;;;/');
    });
})->covers(SourceMapGenerator::class);

describe('source map options', function () {
    it('checks that SourceMapGenerator with includeSources false does not include sourcesContent', function () {
        $mappings = [
            mapping(1, 0, 1, 0),
        ];

        $result = $this->generator->generate(
            $mappings,
            'source.scss',
            'output.css',
            sourceMapOptions()
        );
        $sourceMap = json_decode($result, true);

        expect($sourceMap)->toBeArray()
            ->and($sourceMap)->not->toHaveKey('sourcesContent');
    });
})->covers(SourceMapGenerator::class);

describe('VLQ encoding', function () {
    it('uses VLQ continuation bit for large values', function () {
        $encoded = $this->accessor->callMethod('encodeVLQ', [100]);

        expect(strlen($encoded))->toBeGreaterThan(1);
    });

    it('does not use VLQ continuation bit for small values', function () {
        $encoded = $this->accessor->callMethod('encodeVLQ', [0]);

        expect(strlen($encoded))->toBe(1);
    });

    it('does not use VLQ continuation bit for other small values', function () {
        $encoded1  = $this->accessor->callMethod('encodeVLQ', [1]);
        $encoded15 = $this->accessor->callMethod('encodeVLQ', [15]);

        expect(strlen($encoded1))->toBe(1)
            ->and(strlen($encoded15))->toBe(1);
    });
})->covers(SourceMapGenerator::class);
