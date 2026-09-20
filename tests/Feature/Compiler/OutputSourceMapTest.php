<?php

declare(strict_types=1);

use axy\sourcemap\SourceMap as AxySourceMap;
use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Loader;
use Bugo\SCSS\Style;
use Tests\Support\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {
        it('handles comment types and interpolation in block comments', function () {
            $source = <<<'SCSS'
            $name: world;
            // hidden
            /* loud #{$name} */
            /*! keep #{$name} */
            .test { color: red; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            /* loud world */
            /*! keep world */
            .test {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected)
                ->and($css)->not->toContain('hidden');
        });

        it('compiles multiple blocks with empty lines', function () {
            $source = <<<'SCSS'
            .block1 { color: red; }
            .block2 { color: blue; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .block1 {
              color: red;
            }

            .block2 {
              color: blue;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('generates non-empty source map mappings', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $mapFile = $tmpDir . '/output.css.map';

            $options = new CompilerOptions(
                sourceMapFile: $mapFile,
            );

            $compiler = new Compiler($options);

            $source = <<<'SCSS'
            .block1 { color: red; }
            .block2 { color: blue; }
            SCSS;

            try {
                $css = $compiler->compileString($source);
                $map = json_decode((string) file_get_contents($mapFile), true);

                expect($css)->toContain('sourceMappingURL=') // map reference comment is added
                    ->and(file_exists($mapFile))->toBeTrue()
                    ->and($map)->toBeArray()
                    ->and($map['mappings'] ?? '')->not->toBe('');
            } finally {
                if (file_exists($mapFile)) {
                    unlink($mapFile);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('generates expected mappings for variable and separated blocks', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $mapFile = $tmpDir . '/output.css.map';

            $source = <<<'SCSS'
            $red: red;

            .block {
              color: $red;
            }

            body {
              background: black;
            }
            SCSS;

            $compiler = new Compiler(new CompilerOptions(sourceMapFile: $mapFile));

            try {
                $compiler->compileString($source);

                $map = json_decode((string) file_get_contents($mapFile), true);

                expect($map)->toBeArray()
                    ->and($map['mappings'] ?? null)->toBe('AAEA;EACE,OAHI;;;AAMN;EACE');
            } finally {
                if (file_exists($mapFile)) {
                    unlink($mapFile);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('generates mappings for @media block with no long semicolon runs', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $mapFile = $tmpDir . '/output.css.map';

            $source = <<<'SCSS'
            @media screen {
              .block { color: red; }
            }
            body { background: black; }
            SCSS;

            $compiler = new Compiler(new CompilerOptions(sourceMapFile: $mapFile));

            try {
                $compiler->compileString($source);

                $map = json_decode((string) file_get_contents($mapFile), true);

                $mappings = $map['mappings'] ?? '';

                preg_match_all('/;+/', $mappings, $m);
                $maxRun = $m[0] !== [] ? max(array_map('strlen', $m[0])) : 0;

                expect($map)->toBeArray()
                    ->and($mappings)->not->toBe('')
                    ->and($maxRun)->toBeLessThanOrEqual(4);
            } finally {
                if (file_exists($mapFile)) {
                    unlink($mapFile);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('generates source map for compressed style with optimized output', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $mapFile = $tmpDir . '/output.css.map';

            $options = new CompilerOptions(
                style: Style::COMPRESSED,
                sourceMapFile: $mapFile,
            );

            $compiler = new Compiler($options);

            $source = <<<'SCSS'
            .block { width: 0px; height: 20px; }
            SCSS;

            try {
                $css = $compiler->compileString($source);
                $map = json_decode((string) file_get_contents($mapFile), true);

                expect($css)->toContain('.block{width:0;height:20px}')
                    ->and($css)->toContain('sourceMappingURL=')
                    ->and(file_exists($mapFile))->toBeTrue()
                    ->and($map)->toBeArray()
                    ->and($map['mappings'] ?? '')->not->toBe('');
            } finally {
                if (file_exists($mapFile)) {
                    unlink($mapFile);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('keeps box shorthand values as written during compilation', function () {
            $source = <<<'SCSS'
            .box {
              margin: 10px 20px 10px 20px;
              padding: 8px 8px 8px 8px;
              margin-top-bottom: 1px 2px 1px;
            }
            .box-3 {
              margin: 3px 6px 3px;
              padding: 5px 5px 5px;
            }
            .box-2 {
              margin: 4px 4px;
              padding: 9px 11px;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .box {
              margin: 10px 20px 10px 20px;
              padding: 8px 8px 8px 8px;
              margin-top-bottom: 1px 2px 1px;
            }

            .box-3 {
              margin: 3px 6px 3px;
              padding: 5px 5px 5px;
            }

            .box-2 {
              margin: 4px 4px;
              padding: 9px 11px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('collapses box shorthand values in compressed style', function () {
            $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

            $source = <<<'SCSS'
            .box { margin: 10px 20px 10px 20px; padding: 8px 8px 8px 8px; margin-top-bottom: 1px 2px 1px; }
            .box-3 { margin: 3px 6px 3px; padding: 5px 5px 5px; }
            .box-2 { margin: 4px 4px; padding: 9px 11px; }
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('.box{margin:10px 20px;padding:8px;margin-top-bottom:1px 2px 1px}.box-3{margin:3px 6px;padding:5px}.box-2{margin:4px;padding:9px 11px}');
        });

        it('converts named colors to hex in compressed style for regular declarations', function () {
            $options  = new CompilerOptions(style: Style::COMPRESSED);
            $compiler = new Compiler($options);

            $source = <<<'SCSS'
            .test { color: red; background: transparent; border-color: blue !important; }
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('.test{color:#f00;background:rgba(0,0,0,0);border-color:#00f !important}');
        });

        it('does not convert named colors to hex inside custom properties in compressed style', function () {
            $options  = new CompilerOptions(style: Style::COMPRESSED);
            $compiler = new Compiler($options);

            $source = <<<'SCSS'
            .test { --token: red; color: red; }
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('.test{--token:red;color:#f00}');
        });

        it('converts legacy and wide-gamut color functions to hex in compressed style', function () {
            $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

            $source = <<<'SCSS'
            .test {
              a: rgb(102 51 153);
              b: hsl(270 50% 40%);
              c: hwb(270 20% 40%);
              d: color(srgb 0.4 0.2 0.6);
              e: color(srgb-linear 0.133 0.033 0.319);
              f: color(display-p3 0.1154 0.0363 0.2946);
              g: color(display-p3-linear 0.374 0.21 0.579);
              h: color(a98-rgb 0.358 0.212 0.584);
              i: color(prophoto-rgb 0.316 0.191 0.495);
              j: color(rec2020 0.305 0.168 0.531);
              k: color(xyz 0.124 0.075 0.309);
              l: color(xyz-d65 0.124 0.075 0.309);
              m: color(xyz-d50 0.116 0.073 0.233);
              n: lab(32.4% 38.4 -47.7);
              o: lch(32.4% 61.2 308.9deg);
              p: oklab(44% 0.088 -0.134);
              q: oklch(44% 0.16 303.4deg);
            }
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('.test{a:#639;b:#639;c:#639;d:#639;e:#639;f:#20084e;g:#ac7ccd;h:#639;i:#639;j:#4c1387;k:#639;l:#639;m:#653499;n:lab(32.4% 38.4 -47.7);o:lch(32.4% 61.2 308.9deg);p:oklab(44% .088 -.134);q:oklch(44% .16 303.4deg)}');
        });

        it('converts sass color function results to hex in compressed style for oklch methods', function () {
            $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

            $source = <<<'SCSS'
            @use 'sass:color';

            $venus: #998099;

            .test {
              a: color.scale($venus, $lightness: +15%, $space: oklch);
              b: color.mix($venus, midnightblue, $method: oklch);
            }
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('.test{a:#aa91aa;b:#604b85}');
        });

        it('converts exact rgb colors to hex in compressed style', function () {
            $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

            $css = $compiler->compileString('.a { color: rgb(255, 0, 0); }');

            expect($css)->toBe('.a{color:#f00}');
        });

        it('converts rgba values with inexact alpha to hex in compressed style', function () {
            $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

            $css = $compiler->compileString('.a { color: rgba(0, 0, 0, 0.3); }');

            expect($css)->toBe('.a{color:#0000004d}');
        });
    });

    it('source map contains non-empty sources field', function () {
        $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        $mapFile = $tmpDir . '/output.css.map';

        $options  = new CompilerOptions(sourceFile: 'input.scss', sourceMapFile: $mapFile);
        $compiler = new Compiler($options);

        try {
            $compiler->compileString('.a { color: red; }');
            $map = json_decode((string) file_get_contents($mapFile), true);

            expect($map)->toBeArray()
                ->and($map['sources'] ?? [])->not->toBeEmpty()
                ->and($map['sources'][0])->toBe('input.scss');
        } finally {
            if (file_exists($mapFile)) {
                unlink($mapFile);
            }

            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    });

    it('source map embeds sourcesContent when includeSources is enabled', function () {
        $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        $mapFile = $tmpDir . '/output.css.map';

        $source  = '.a { color: red; }';
        $options = new CompilerOptions(
            sourceMapFile: $mapFile,
            includeSources: true,
        );
        $compiler = new Compiler($options);

        try {
            $compiler->compileString($source);
            $map = json_decode((string) file_get_contents($mapFile), true);

            expect($map)->toBeArray()
                ->and($map['sourcesContent'] ?? null)->not->toBeNull()
                ->and($map['sourcesContent'])->toBeArray()
                ->and($map['sourcesContent'][0])->toBe($source);
        } finally {
            if (file_exists($mapFile)) {
                unlink($mapFile);
            }

            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    });

    it('produces decoded positions matching an expected map built with axy/sourcemap', function () {
        $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        $inputFile = $tmpDir . '/input.scss';
        $mapFile   = $tmpDir . '/output.css.map';

        $source = <<<'SCSS'
        .block {
          color: red;
          background: blue;
        }
        SCSS;

        file_put_contents($inputFile, $source);

        $compiler = new Compiler(
            options: new CompilerOptions(sourceMapFile: $mapFile),
            loader: new Loader([$tmpDir]),
        );

        try {
            $compiler->compileFile('input.scss');

            $ourMap = new AxySourceMap(json_decode((string) file_get_contents($mapFile), true));
            $axyMap = new AxySourceMap([
                'version'  => 3,
                'file'     => 'output.css',
                'sources'  => ['input.scss'],
                'mappings' => '',
            ]);

            foreach ([
                ['generated' => ['line' => 0, 'column' => 0], 'source' => ['file' => 'input.scss', 'line' => 0, 'column' => 0]],
                ['generated' => ['line' => 1, 'column' => 2], 'source' => ['file' => 'input.scss', 'line' => 1, 'column' => 2]],
                ['generated' => ['line' => 2, 'column' => 2], 'source' => ['file' => 'input.scss', 'line' => 2, 'column' => 2]],
            ] as $position) {
                $axyMap->addPosition($position);
            }

            $ourPositions = $ourMap->find();
            $axyPositions = $axyMap->find();

            expect(count($ourPositions))->toBe(count($axyPositions))->toBeGreaterThan(0);

            foreach ($ourPositions as $i => $ourPos) {
                expect($ourPos->generated->line)->toBe($axyPositions[$i]->generated->line)
                    ->and($ourPos->generated->column)->toBe($axyPositions[$i]->generated->column)
                    ->and($ourPos->source->line)->toBe($axyPositions[$i]->source->line)
                    ->and($ourPos->source->column)->toBe($axyPositions[$i]->source->column);
            }

            $firstPos = $ourMap->getPosition(0, 0);

            expect($firstPos)->not->toBeNull()
                ->and($firstPos->source->line)->toBe(0)
                ->and($firstPos->source->column)->toBe(0);
        } finally {
            foreach ([$inputFile, $mapFile] as $f) {
                if (file_exists($f)) {
                    unlink($f);
                }
            }

            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    });

    it('maps expanded top-level blocks to their real generated line', function () {
        $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        $mapFile = $tmpDir . '/output.css.map';

        // Two top-level blocks separated by a blank line: the second block must map to its actual
        // generated line, not one line below (no extra empty segment for the separator).
        $source = <<<'SCSS'
        .block {
          color: red;
        }

        body {
          background: black;
        }
        SCSS;

        $compiler = new Compiler(new CompilerOptions(sourceMapFile: $mapFile));

        try {
            $css  = $compiler->compileString($source);
            $body = substr($css, 0, (int) strpos($css, "\n/*# sourceMappingURL="));

            $lines    = explode("\n", $body);
            $bodyLine = array_search('body {', $lines, true);
            $bgLine   = array_search('  background: black;', $lines, true);

            $map = new AxySourceMap(json_decode((string) file_get_contents($mapFile), true));

            // Collect the generated line for each source line among the decoded mappings.
            $generatedBySource = [];
            foreach ($map->find() as $position) {
                $generatedBySource[$position->source->line] = $position->generated->line;
            }

            // "body {" is source line 4, "background" is source line 5 (0-based). Each must map to
            // the generated line where it actually sits, with no off-by-one from the separator.
            expect($generatedBySource[4] ?? null)->toBe($bodyLine)
                ->and($generatedBySource[5] ?? null)->toBe($bgLine);
        } finally {
            if (file_exists($mapFile)) {
                unlink($mapFile);
            }

            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    });

    it('remaps decoded positions to the compressed output after optimization', function () {
        $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        $mapFile = $tmpDir . '/output.css.map';

        $options = new CompilerOptions(
            style: Style::COMPRESSED,
            sourceMapFile: $mapFile,
        );

        $compiler = new Compiler($options);

        // Hex shortening, comment removal, whitespace compaction, zero-unit stripping and
        // box-shorthand collapsing all shift positions after the mappings were recorded.
        $source = <<<'SCSS'
        .alpha {
          color: #aabbcc;
          margin: 4px 4px 4px 4px;
        }
        /* comment */
        .beta {
          width: 0px;
        }
        SCSS;

        try {
            $css  = $compiler->compileString($source);
            $body = substr($css, 0, (int) strpos($css, "\n/*# sourceMappingURL="));
            $map  = new AxySourceMap(json_decode((string) file_get_contents($mapFile), true));

            // Every decoded generated position must fall inside the compressed body and point at
            // the same token the mapping links to in the source.
            $betaColumn = strpos($body, '.beta');

            expect($body)->toBe('.alpha{color:#abc;margin:4px}.beta{width:0}')
                ->and($betaColumn)->not->toBeFalse();

            foreach ($map->find() as $position) {
                expect($position->generated->line)->toBe(0)
                    ->and($position->generated->column)->toBeLessThanOrEqual(strlen($body));
            }

            $betaPosition = $map->getPosition(0, (int) $betaColumn);

            expect($betaPosition)->not->toBeNull()
                ->and($betaPosition->source->line)->toBe(5);
        } finally {
            if (file_exists($mapFile)) {
                unlink($mapFile);
            }

            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    });

    it('shifts compressed mappings by the hoisted @import prefix without changing the css', function () {
        $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        $mapFile = $tmpDir . '/output.css.map';

        $source = <<<'SCSS'
        @import "theme.css";
        .block {
          color: #aabbcc;
        }
        SCSS;

        try {
            $withMap = (new Compiler(new CompilerOptions(
                style: Style::COMPRESSED,
                sourceMapFile: $mapFile,
            )))->compileString($source);

            $withoutMap = (new Compiler(new CompilerOptions(
                style: Style::COMPRESSED,
            )))->compileString($source);

            $body = substr($withMap, 0, (int) strpos($withMap, "\n/*# sourceMappingURL="));

            // The css must stay byte-identical to compile without a source map.
            expect($body)->toBe($withoutMap)
                ->and($body)->toBe('@import "theme.css";.block{color:#abc}');

            $map = new AxySourceMap(json_decode((string) file_get_contents($mapFile), true));

            // The .block selector sits after the hoisted @import prefix; its generated column
            // must account for that prefix.
            $blockColumn = (int) strpos($body, '.block');

            $blockPosition = $map->getPosition(0, $blockColumn);

            expect($blockPosition)->not->toBeNull()
                ->and($blockPosition->source->line)->toBe(1);
        } finally {
            if (file_exists($mapFile)) {
                unlink($mapFile);
            }

            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    });

    it('shifts compressed mappings down by one line when a charset is prepended', function () {
        $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        $mapFile = $tmpDir . '/output.css.map';

        $source = <<<'SCSS'
        .icon {
          content: "→";
        }
        SCSS;

        try {
            $css = (new Compiler(new CompilerOptions(
                style: Style::COMPRESSED,
                sourceMapFile: $mapFile,
            )))->compileString($source);

            expect($css)->toStartWith('@charset "UTF-8";' . "\n");

            $map = new AxySourceMap(json_decode((string) file_get_contents($mapFile), true));

            // The charset line pushes every mapping to generated line 1 (0-based).
            foreach ($map->find() as $position) {
                expect($position->generated->line)->toBe(1);
            }
        } finally {
            if (file_exists($mapFile)) {
                unlink($mapFile);
            }

            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    });

    describe('compileFile()', function () {
        it('detects syntax from content for file without extension', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $filePath = $tmpDir . '/styles';

            file_put_contents($filePath, <<<'SASS'
            .container
              color: red
            SASS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $expected = /** @lang text */ <<<'CSS'
                .container {
                  color: red;
                }
                CSS;

                $css = $compiler->compileFile($filePath);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('uses custom sourceFile option instead of path when compiling file', function () {
            $tmpDir   = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);
            $filePath = $tmpDir . '/styles.scss';

            mkdir($tmpDir, 0777, true);
            file_put_contents($filePath, '.foo { color: red; }');

            try {
                $compiler = new Compiler(
                    options: new CompilerOptions(sourceFile: 'custom.scss'),
                    loader: new Loader([$tmpDir]),
                );

                $css = $compiler->compileFile($filePath);

                expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
                .foo {
                  color: red;
                }
                CSS);
            } finally {
                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('resolves compileFile module from current directory before loadPaths', function () {
            $workDir = sys_get_temp_dir() . '/dart-sass-compilefile-cwd-' . uniqid('', true);
            $loadDir = sys_get_temp_dir() . '/dart-sass-compilefile-loadpath-' . uniqid('', true);

            mkdir($workDir, 0777, true);
            mkdir($loadDir, 0777, true);

            $cwdPath  = $workDir . '/style.scss';
            $loadPath = $loadDir . '/style.scss';

            file_put_contents($cwdPath, <<<'SCSS'
            .from-cwd { color: red; }
            SCSS);
            file_put_contents($loadPath, <<<'SCSS'
            .from-loadpath { color: blue; }
            SCSS);

            $initialCwd = getcwd();

            try {
                chdir($workDir);

                $compiler = new Compiler(loader: new Loader([$loadDir]));

                $expected = /** @lang text */ <<<'CSS'
                .from-cwd {
                  color: red;
                }
                CSS;

                $css = $compiler->compileFile('style');

                expect($css)->toEqualCss($expected);
            } finally {
                chdir($initialCwd);

                if (file_exists($cwdPath)) {
                    unlink($cwdPath);
                }

                if (file_exists($loadPath)) {
                    unlink($loadPath);
                }

                if (is_dir($workDir)) {
                    rmdir($workDir);
                }

                if (is_dir($loadDir)) {
                    rmdir($loadDir);
                }
            }
        });
    });
});
