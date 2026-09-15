<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Loader;
use Tests\Support\ArrayLogger;

describe('Compiler extend module graph', function () {
    beforeEach(function () {
        $this->logger = new ArrayLogger();
    });

    it('applies extends from imported modules and resolves import branches across shared sub modules', function () {
        $tmpDir = sys_get_temp_dir() . '/scss-php-modgraph-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        file_put_contents($tmpDir . '/_shared.scss', <<<'SCSS'
        .sh {
          color: blue;
        }
        SCSS);

        file_put_contents($tmpDir . '/_sub.scss', <<<'SCSS'
        .sub {
          @extend .z;
        }

        .z {
          color: lime;
        }

        .sub2 {
          padding: 1px;
        }
        SCSS);

        file_put_contents($tmpDir . '/_c.scss', <<<'SCSS'
        @use '_shared';
        @import '_sub';
        .c1 {
          color: yellow;
        }
        SCSS);

        file_put_contents($tmpDir . '/_a.scss', <<<'SCSS'
        @use '_shared';
        @import '_c';
        SCSS);

        file_put_contents($tmpDir . '/_b2.scss', <<<'SCSS'
        @import '_c';
        SCSS);

        file_put_contents($tmpDir . '/_shared2.scss', <<<'SCSS'
        .sh2 {
          color: navy;
        }
        SCSS);

        file_put_contents($tmpDir . '/_b.scss', <<<'SCSS'
        @use '_shared2';
        @import '_d';
        SCSS);

        file_put_contents($tmpDir . '/_d.scss', <<<'SCSS'
        .d1 {
          margin: 0;
        }
        SCSS);

        file_put_contents($tmpDir . '/root.scss', <<<'SCSS'
        @use '_shared';
        @import '_a';
        @import '_b2';
        .target {
          color: red;
        }
        .every {
          @extend .target;
        }
        SCSS);

        $compiler = new Compiler(loader: new Loader([$tmpDir]), logger: $this->logger);

        $css = $compiler->compileString(@file_get_contents($tmpDir . '/root.scss'));

        expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
        .sh {
          color: blue;
        }

        .sh {
          color: blue;
        }

        .sh {
          color: blue;
        }

        .z, .sub {
          color: lime;
        }

        .sub2 {
          padding: 1px;
        }

        .c1 {
          color: yellow;
        }

        .sh {
          color: blue;
        }

        .z, .sub {
          color: lime;
        }

        .sub2 {
          padding: 1px;
        }

        .c1 {
          color: yellow;
        }

        .target, .every {
          color: red;
        }
        CSS);
    });

    it('stops importing dependency chains beyond the maximum depth', function () {
        $tmpDir = sys_get_temp_dir() . '/scss-php-modchain-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        $count = 24;

        for ($i = 1; $i < $count; $i++) {
            file_put_contents($tmpDir . "/_n$i.scss", "@import '_n" . ($i + 1) . "';\n.next-$i { padding: {$i}px; }");
        }

        file_put_contents($tmpDir . "/_n$count.scss", ".nth { margin: 0 }\n.tail { @extend .nth; }");
        file_put_contents($tmpDir . '/root.scss', ".base { color: red; }\n.every { @extend .base; }\n@import '_n1';");

        $compiler = new Compiler(loader: new Loader([$tmpDir]), logger: $this->logger);

        $css = $compiler->compileString(@file_get_contents($tmpDir . '/root.scss'));

        expect($css)->toContain('.every');
    });

    it('keeps extenders from import ancestors inside nested import branches', function () {
        $tmpDir = sys_get_temp_dir() . '/scss-php-modbranch-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        file_put_contents($tmpDir . '/_shared.scss', <<<'SCSS'
        .sh {
          color: blue;
        }
        SCSS);

        file_put_contents($tmpDir . '/_c.scss', <<<'SCSS'
        @use '_shared';
        .c1 {
          color: yellow;
        }
        SCSS);

        file_put_contents($tmpDir . '/_a.scss', <<<'SCSS'
        @use '_shared';
        @import '_c';
        .a1 {
          @extend .sh;
        }
        SCSS);

        file_put_contents($tmpDir . '/root.scss', <<<'SCSS'
        @use '_shared';
        @import '_a';
        .target {
          color: red;
        }
        SCSS);

        $compiler = new Compiler(loader: new Loader([$tmpDir]), logger: $this->logger);

        $css = $compiler->compileString(@file_get_contents($tmpDir . '/root.scss'));

        expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
        .sh, .a1 {
          color: blue;
        }

        .sh, .a1 {
          color: blue;
        }

        .sh, .a1 {
          color: blue;
        }

        .c1 {
          color: yellow;
        }

        .target {
          color: red;
        }
        CSS);
    });

    it('keeps extenders from import ancestors when load-css re-renders the imported subtree', function () {
        $tmpDir = sys_get_temp_dir() . '/scss-php-modbranchcss-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        file_put_contents($tmpDir . '/_shared.scss', <<<'SCSS'
        .sh {
          color: blue;
        }
        SCSS);

        file_put_contents($tmpDir . '/_c.scss', <<<'SCSS'
        @use '_shared';
        .c1 {
          color: yellow;
        }
        SCSS);

        file_put_contents($tmpDir . '/_a.scss', <<<'SCSS'
        @use '_shared';
        @import '_c';
        .a1 {
          @extend .sh;
        }
        SCSS);

        file_put_contents($tmpDir . '/root.scss', <<<'SCSS'
        @use 'sass:meta';
        @use '_shared';
        @import '_a';
        @include meta.load-css('_a');
        .target {
          color: red;
        }
        SCSS);

        $compiler = new Compiler(loader: new Loader([$tmpDir]), logger: $this->logger);

        $css = $compiler->compileString(@file_get_contents($tmpDir . '/root.scss'));

        expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
        .sh, .a1 {
          color: blue;
        }

        .sh, .a1 {
          color: blue;
        }

        .sh, .a1 {
          color: blue;
        }

        .c1 {
          color: yellow;
        }

        .sh, .a1 {
          color: blue;
        }

        .sh, .a1 {
          color: blue;
        }

        .c1 {
          color: yellow;
        }

        .target {
          color: red;
        }
        CSS);
    });

    it('fails compilation when module resolution raises an error inside the extend graph sweep', function () {
        $tmpDir = sys_get_temp_dir() . '/scss-php-modlint-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        file_put_contents($tmpDir . '/root.scss', ".base { color: red; }\n@import 'missing-module';");

        $compiler = new Compiler(loader: new Loader([$tmpDir]), logger: $this->logger);

        expect(fn() => $compiler->compileString(file_get_contents($tmpDir . '/root.scss')))
            ->toThrow(\Bugo\SCSS\Exceptions\ModuleResolutionException::class);
    });
});
