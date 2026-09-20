<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Syntax;
use Tests\Support\MemoryLoader;

$specDir = getenv('SASS_SPEC_DIR') ?: dirname(__DIR__, 3) . '/spec';

/**
 * Cases whose expected `output.css` contradicts the reference Dart Sass compiler.
 *
 * Values are `<relative hrx path>`. Only add an entry
 * after verifying with `vendor/bugo/sass-embedded-php/bin/sass.bat` that our
 * output matches the reference compiler and the `.hrx` expectation is the odd one out.
 *
 * Note that differences in blank lines are not a reason to add an entry: `normalizeCss()`
 * already ignores them, matching the official sass-spec runner.
 *
 * @var array<string, string> $outdatedSpecCases
 */
$outdatedSpecCases = [
    'libsass-todo-issues/issue_221262.hrx',
    'libsass-todo-issues/issue_221292.hrx',
];

if (getenv('RUN_SASS_SPEC') && is_dir($specDir)) {
    $grouped = collectSpecFilesGrouped($specDir);

    foreach ($grouped as $groupLabel => $groupFiles) {
        describe($groupLabel, function () use ($groupFiles, $outdatedSpecCases) {
            $compiler = new Compiler();

            foreach ($groupFiles as $hxrPath) {
                $relative  = $hxrPath['relative'];
                $testCases = parseSpecFile($hxrPath['absolute'], $relative);

                if ($testCases === []) {
                    continue;
                }

                it('checks ' . $relative, function () use ($compiler, $testCases, $relative, $outdatedSpecCases) {
                    $failures = [];

                    foreach ($testCases as [$testName, $inputName, $input, $expectedCss, $files]) {
                        if (in_array($relative, $outdatedSpecCases, true)) {
                            continue;
                        }

                        $inputBase = basename($inputName);

                        $hasExtraFiles = array_diff(
                            array_map('basename', array_keys($files)),
                            [$inputBase, 'output.css'],
                        ) !== [];

                        if (! $hasExtraFiles && hasExternalDependency($input)) {
                            continue;
                        }

                        $testCompiler = $hasExtraFiles
                            ? new Compiler(loader: new MemoryLoader($files, dirname($inputName)))
                            : $compiler;

                        try {
                            $actualCss = $testCompiler->compileString(
                                $input,
                                Syntax::fromPath($inputName),
                                $inputName,
                            );
                        } catch (Throwable $e) {
                            $failures[] = "$testName — Exception: " . $e->getMessage();

                            continue;
                        }

                        $actualNorm   = normalizeCss($actualCss);
                        $expectedNorm = normalizeCss($expectedCss);

                        if ($actualNorm !== $expectedNorm) {
                            $failures[] = "$testName\nExpected:\n$expectedNorm\nActual:\n$actualNorm";
                        }
                    }

                    expect($failures)->toBeEmpty(
                        count($failures) . ' failed:'
                        . "\n\n" . implode("\n\n---\n\n", $failures),
                    );
                });
            }
        });
    }
}

/**
 * Collects spec files grouped into batches of ~100 for manageable describe blocks.
 */
function collectSpecFilesGrouped(string $specDir): array
{
    $specDir  = rtrim($specDir, '/\\') . '/';
    $allFiles = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($specDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'hrx') {
            continue;
        }

        $realPath = $file->getRealPath() ?: $file->getPathname();
        $relative = str_replace('\\', '/', substr($realPath, strlen($specDir)));

        $allFiles[] = ['absolute' => $realPath, 'relative' => $relative];
    }

    usort($allFiles, static fn(array $a, array $b): int => strcmp($a['relative'], $b['relative']));

    $batches   = [];
    $batchSize = 100;

    foreach ($allFiles as $index => $file) {
        $batchIndex = intdiv($index, $batchSize);

        $batches[$batchIndex][] = $file;
    }

    $grouped = [];

    foreach ($batches as $batchIndex => $batchFiles) {
        $first = $batchFiles[0]['relative'];
        $last  = end($batchFiles)['relative'];

        $firstDir = explode('/', $first)[0];
        $lastDir  = explode('/', $last)[0];

        $rangeStart = $batchIndex * $batchSize + 1;
        $rangeEnd   = $rangeStart + count($batchFiles) - 1;

        if ($firstDir === $lastDir) {
            $label = "$firstDir [$rangeStart-$rangeEnd]";
        } else {
            $label = "{$firstDir}–$lastDir [$rangeStart-$rangeEnd]";
        }

        $grouped[$label] = $batchFiles;
    }

    return $grouped;
}

/**
 * Parses an HRX file and returns compilable Sass test cases.
 *
 * @return list<array{0: string, 1: string, 2: string, 3: string, 4: array<string, string>}>
 *         [testName, inputName, input, expectedCss, virtualFiles]
 */
function parseSpecFile(string $hxrPath, string $relativePath): array
{
    $content = file_get_contents($hxrPath);

    if ($content === false) {
        return [];
    }

    $entries     = parseHrxEntries($content);
    $archiveBase = substr($relativePath, 0, -4);
    $groups      = [];

    foreach ($entries as $path => $entryContent) {
        $lastSlash = strrpos($path, '/');

        if ($lastSlash !== false) {
            $base = substr($path, 0, $lastSlash);
            $name = substr($path, $lastSlash + 1);
        } else {
            $base = '';
            $name = $path;
        }

        $groups[$base][$name] = $entryContent;
    }

    $tests = [];

    foreach ($groups as $base => $groupFiles) {
        $base      = (string) $base;
        $inputBase = isset($groupFiles['input.scss']) ? 'input.scss' : 'input.sass';

        if (! isset($groupFiles[$inputBase]) || ! isset($groupFiles['output.css'])) {
            continue;
        }

        $input = $groupFiles[$inputBase];

        if (isProblematicInput($input)) {
            continue;
        }

        $virtualFiles = [];

        foreach ($entries as $entryPath => $entryContent) {
            $lastSlash = strrpos($entryPath, '/');
            $entryBase = $lastSlash !== false ? substr($entryPath, 0, $lastSlash) : '';

            if (isVirtualFileVisibleToTest($entryBase, $base)) {
                $virtualFiles[$archiveBase . '/' . $entryPath] = $entryContent;
            }
        }

        $inputName = $archiveBase . '/' . ($base === '' ? '' : $base . '/') . $inputBase;
        $tests[]   = [$base, $inputName, $input, $groupFiles['output.css'], $virtualFiles];
    }

    return $tests;
}

function isVirtualFileVisibleToTest(string $entryBase, string $testBase): bool
{
    if ($entryBase === '' || $testBase === '' || $entryBase === $testBase) {
        return true;
    }

    return str_starts_with($entryBase, $testBase . '/')
        || str_starts_with($testBase, $entryBase . '/');
}

/**
 * Parses HRX content into a map of virtual path => file content.
 *
 * @return array<string, string>
 */
function parseHrxEntries(string $content): array
{
    $lines        = explode("\n", $content);
    $files        = [];
    $currentPath  = null;
    $currentLines = [];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '<===>')) {
            if ($currentPath !== null && $currentPath !== '') {
                $files[$currentPath] = implode("\n", $currentLines);
            }

            $path = trim(substr($line, 5));

            if ($path === '' || $path === 'README.md') {
                $currentPath  = null;
            } else {
                $currentPath  = $path;
            }

            $currentLines = [];
        } elseif ($currentPath !== null) {
            $currentLines[] = $line;
        }
    }

    if ($currentPath !== null && $currentPath !== '') {
        $files[$currentPath] = implode("\n", $currentLines);
    }

    return $files;
}

/**
 * Detects inputs likely to cause segfaults, OOM, or infinite loops.
 */
function isProblematicInput(string $input): bool
{
    if (hasExtendDirective($input)) {
        return true;
    }

    if (hasRecursiveSelfReference($input)) {
        return true;
    }

    if (strlen($input) > 10000) {
        return true;
    }

    return false;
}

/**
 * Detects mixin/function parameters with `$name: $name` pattern
 * which causes infinite recursion in the compiler.
 */
function hasRecursiveSelfReference(string $input): bool
{
    $lines = explode("\n", $input);

    foreach ($lines as $line) {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        if (! str_starts_with($trimmed, '@mixin ') && ! str_starts_with($trimmed, '@function ')) {
            continue;
        }

        $openPos  = strpos($trimmed, '(');
        $closePos = strrpos($trimmed, ')');

        if ($openPos === false || $closePos === false || $closePos <= $openPos) {
            continue;
        }

        $paramStr = substr($trimmed, $openPos + 1, $closePos - $openPos - 1);
        $params   = explode(',', $paramStr);

        foreach ($params as $param) {
            $param    = trim($param);
            $colonPos = strpos($param, ':');

            if ($colonPos === false) {
                continue;
            }

            $paramName  = trim(substr($param, 0, $colonPos));
            $defaultVal = trim(substr($param, $colonPos + 1));

            if ($paramName !== '' && $paramName === $defaultVal) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Checks whether the SCSS input references external files via @use, @import or @forward.
 * Built-in sass:* modules are allowed.
 */
function hasExternalDependency(string $input): bool
{
    $lines      = explode("\n", $input);
    $directives = ['@use', '@import', '@forward'];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        foreach ($directives as $directive) {
            $directiveLen = strlen($directive);

            if (! str_starts_with($trimmed, $directive . ' ') && ! str_starts_with($trimmed, $directive . "\t")) {
                continue;
            }

            $rest = ltrim(substr($trimmed, $directiveLen));

            if (str_starts_with($rest, '"sass:') || str_starts_with($rest, "'sass:")) {
                continue 2;
            }

            if (str_starts_with($rest, '"') || str_starts_with($rest, "'")) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Checks whether the SCSS input contains @extend directives
 * which can cause catastrophic backtracking / OOM in the compiler.
 */
function hasExtendDirective(string $input): bool
{
    $lines = explode("\n", $input);

    foreach ($lines as $line) {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        if (str_starts_with($trimmed, '@extend ')) {
            return true;
        }
    }

    return false;
}

/**
 * Normalizes CSS for comparison
 */
function normalizeCss(string $css): string
{
    return collapseBlankLines($css);
}

/**
 * Collapses runs of newlines into one, mirroring `normalizeOutput()` from the
 * official sass-spec runner (`lib/test-case/compare.ts`). Blank lines between
 * top-level groups are not part of the spec contract; they are covered by
 * dedicated formatting tests instead.
 */
function collapseBlankLines(string $css): string
{
    $result = '';

    foreach (explode("\n", $css) as $line) {
        $line = rtrim($line, "\r");

        if ($line === '') {
            continue;
        }

        $result .= ($result === '' ? '' : "\n") . $line;
    }

    return $result;
}
