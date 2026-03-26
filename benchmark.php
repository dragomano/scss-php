<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Bugo\BenchmarkUtils\BenchmarkRunner;
use Bugo\BenchmarkUtils\ScssGenerator;
use Bugo\Sass\Compiler as EmbeddedCompiler;
use Bugo\Sass\Options;
use Bugo\SCSS\Cache\CachingCompiler;
use Bugo\SCSS\Cache\TrackingLoader;
use Bugo\SCSS\Compiler as SassCompiler;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Loader;
use Bugo\SCSS\Style;
use ScssPhp\ScssPhp\Compiler as ScssCompiler;
use ScssPhp\ScssPhp\OutputStyle;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class CachedBenchmarkCompiler
{
    public function __construct(
        private readonly CachingCompiler $compiler,
        private readonly string $entryPath
    ) {}

    public function compileString(string $scss): string
    {
        if (! file_exists($this->entryPath) || file_get_contents($this->entryPath) !== $scss) {
            file_put_contents($this->entryPath, $scss, LOCK_EX);
        }

        return $this->compiler->compileFile($this->entryPath);
    }
}

$args = $_SERVER['argv'] ?? [];

$benchmarkRuns = 5;

if (isset($args[1]) && ctype_digit((string) $args[1])) {
    $benchmarkRuns = max(1, (int) $args[1]);
}

$forceRegenerate = in_array('--regenerate', $args, true);
$scssFile = __DIR__ . DIRECTORY_SEPARATOR . 'generated.scss';

if (! $forceRegenerate && file_exists($scssFile)) {
    $scss = (string) file_get_contents($scssFile);
    echo "Using existing generated.scss\n";
} else {
    $scss = ScssGenerator::generate(200, 4);
    file_put_contents($scssFile, $scss, LOCK_EX);
    echo "Generated SCSS saved to generated.scss\n";
}

echo 'SCSS size: ' . strlen($scss) . " bytes\n";

$minimize     = false;
$sourceMap    = true;
$singleRuns   = 10;
$warmupRuns   = 2;
$outputDir    = __DIR__;
$allResults   = [];
$aggregate    = [];
$compilerList = [
    'bugo/scss-php',
    'bugo/scss-php+cache',
    'bugo/sass-embedded-php',
    'scssphp/scssphp',
];

for ($i = 0; $i < $benchmarkRuns; $i++) {
    $results = (new BenchmarkRunner())
        ->setScssCode($scss)
        ->setRuns($singleRuns)
        ->setWarmupRuns($warmupRuns)
        ->setOutputDir($outputDir)
        ->addCompiler('bugo/scss-php', function () use ($sourceMap, $minimize) {
            $options = new CompilerOptions(
                style: $minimize ? Style::COMPRESSED : Style::EXPANDED,
                sourceFile: 'generated.scss',
                outputFile: 'result-bugo-scss-php.css',
                sourceMapFile: $sourceMap ? 'result-bugo-scss-php.css.map' : null,
                includeSources: true,
                outputHexColors: true,
            );

            return new SassCompiler($options);
        })
        ->addCompiler('bugo/scss-php+cache', function () use ($scss, $scssFile, $sourceMap, $minimize) {
            $options = new CompilerOptions(
                style: $minimize ? Style::COMPRESSED : Style::EXPANDED,
                outputFile: 'result-bugo-scss-php-cache.css',
                sourceMapFile: $sourceMap ? 'result-bugo-scss-php-cache.css.map' : null,
                includeSources: true,
                outputHexColors: true,
            );

            file_put_contents($scssFile, $scss, LOCK_EX);

            $trackingLoader = new TrackingLoader(new Loader([__DIR__]));
            $compiler       = new SassCompiler($options, $trackingLoader);
            $cache          = new Psr16Cache(new ArrayAdapter());

            return new CachedBenchmarkCompiler(
                new CachingCompiler($compiler, $cache, $trackingLoader, $options),
                $scssFile,
            );
        })
        ->addCompiler('bugo/sass-embedded-php', function () use ($sourceMap, $minimize) {
            $compiler = new EmbeddedCompiler();
            $compiler->setOptions(new Options(
                style: $minimize ? 'compressed' : 'expanded',
                includeSources: true,
                removeEmptyLines: true,
                sourceMapPath: $sourceMap ? 'result-sass-embedded-php.css.map' : null,
                sourceFile: 'generated.scss',
                streamResult: true,
            ));

            return $compiler;
        })
        ->addCompiler('scssphp/scssphp', function () use ($sourceMap, $minimize) {
            $compiler = new ScssCompiler();
            $compiler->setOutputStyle($minimize ? OutputStyle::COMPRESSED : OutputStyle::EXPANDED);
            $compiler->setSourceMap($sourceMap ? ScssCompiler::SOURCE_MAP_FILE : ScssCompiler::SOURCE_MAP_NONE);
            $compiler->setSourceMapOptions($sourceMap ? [
                'sourceMapFilename' => 'generated.scss',
                'sourceMapURL'      => 'result-scssphp-scssphp.css.map',
                'outputSourceFiles' => true,
            ] : []);

            return $compiler;
        })
        ->run();

    $allResults[] = $results;

    foreach ($compilerList as $compilerName) {
        if (! isset($results[$compilerName])) {
            continue;
        }

        $time = $results[$compilerName]['time'];

        if (! is_numeric($time)) {
            continue;
        }

        $aggregate[$compilerName]['time'][]   = (float) $time;
        $aggregate[$compilerName]['size'][]   = (float) $results[$compilerName]['size'];
        $aggregate[$compilerName]['memory'][] = (float) $results[$compilerName]['memory'];
    }

    echo PHP_EOL . '## Run ' . ($i + 1) . '/' . $benchmarkRuns . PHP_EOL;
    echo BenchmarkRunner::formatTable($results);
}

$median = static function (array $values): float {
    sort($values);
    $count = count($values);
    $mid   = intdiv($count, 2);

    if ($count % 2 === 0) {
        return ($values[$mid - 1] + $values[$mid]) / 2;
    }

    return $values[$mid];
};

$results = [];

foreach ($aggregate as $compilerName => $stats) {
    $times  = $stats['time'];
    $sizes  = $stats['size'];
    $memory = $stats['memory'];

    $results[$compilerName] = [
        'time'   => $median($times),
        'size'   => $median($sizes),
        'memory' => $median($memory),
    ];
}

echo PHP_EOL . '## Aggregated (median)' . PHP_EOL;
echo BenchmarkRunner::formatTable($results);

echo PHP_EOL . '## Time Stats (sec)' . PHP_EOL;
echo '| Compiler | Min | Median | Max | Avg |' . PHP_EOL;
echo '|------------|-------------|-------------|-------------|-------------|' . PHP_EOL;

foreach ($aggregate as $compilerName => $stats) {
    $times = $stats['time'];
    sort($times);

    $min = $times[0];
    $max = $times[count($times) - 1];
    $med = $median($times);
    $avg = array_sum($times) / count($times);

    echo '| ' . $compilerName
        . ' | ' . number_format($min, 4)
        . ' | ' . number_format($med, 4)
        . ' | ' . number_format($max, 4)
        . ' | ' . number_format($avg, 4)
        . " |\n";
}

echo PHP_EOL . 'Iterations: ' . $benchmarkRuns . ', runs per iteration: ' . $singleRuns . ', warmup: ' . $warmupRuns . PHP_EOL;

BenchmarkRunner::updateMarkdownFile('benchmark.md', $results);
