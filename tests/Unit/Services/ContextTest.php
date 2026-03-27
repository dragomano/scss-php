<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Services\Context;
use Tests\ArrayLogger;

describe('Context', function () {
    beforeEach(function () {
        $this->ctx    = new CompilerContext(currentSourceFile: 'theme.scss');
        $this->logger = new ArrayLogger();
    });

    describe('getters', function () {
        it('exposes options', function () {
            $options = new CompilerOptions(sourceFile: 'source.scss');
            $service = new Context($this->ctx, $options, $this->logger);

            expect($service->options())->toBe($options);
        });

        it('exposes logger', function () {
            $options = new CompilerOptions();
            $service = new Context($this->ctx, $options, $this->logger);

            expect($service->logger())->toBe($this->logger);
        });

        it('returns currentSourceFile from context', function () {
            $options = new CompilerOptions();
            $service = new Context($this->ctx, $options, $this->logger);

            expect($service->currentSourceFile())->toBe('theme.scss');
        });
    });

    describe('logWarning()', function () {
        it('does not log when verboseLogging is false and warning is issued', function () {
            $options = new CompilerOptions(verboseLogging: false);
            $service = new Context($this->ctx, $options, $this->logger);
            $service->logWarning('something went wrong');

            expect($this->logger->records)->toHaveCount(1)
                ->and($this->logger->records[0]['level'])->toBe('warning');
        });

        it('logs compact message with file prefix when verboseLogging is false', function () {
            $options = new CompilerOptions(verboseLogging: false);
            $service = new Context($this->ctx, $options, $this->logger);
            $service->logWarning('deprecated mixin');

            $message = $this->logger->records[0]['message'];
            expect($message)->toContain('theme.scss')
                ->and($message)->toContain('deprecated mixin');
        });

        it('includes line number in compact message when line is provided', function () {
            $options = new CompilerOptions(verboseLogging: false);
            $service = new Context($this->ctx, $options, $this->logger);
            $service->logWarning('deprecated mixin', 42);

            $message = $this->logger->records[0]['message'];
            expect($message)->toContain(':42');
        });

        it('omits line number in compact message when line is null', function () {
            $options = new CompilerOptions(verboseLogging: false);
            $service = new Context($this->ctx, $options, $this->logger);
            $service->logWarning('deprecated mixin');

            $message = $this->logger->records[0]['message'];
            expect($message)->not->toContain(':');
        });

        it('logs with context array when verboseLogging is true', function () {
            $options = new CompilerOptions(verboseLogging: true);
            $service = new Context($this->ctx, $options, $this->logger);
            $service->logWarning('some warning', 10);

            $record = $this->logger->records[0];
            expect($record['message'])->toBe('some warning')
                ->and($record['context']['file'])->toBe('theme.scss')
                ->and($record['context']['line'])->toBe(10);
        });

        it('passes null line in context when verboseLogging is true and no line given', function () {
            $options = new CompilerOptions(verboseLogging: true);
            $service = new Context($this->ctx, $options, $this->logger);
            $service->logWarning('some warning');

            $record = $this->logger->records[0];
            expect($record['context']['line'])->toBeNull();
        });
    });
});
