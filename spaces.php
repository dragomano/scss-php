<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Bugo\SCSS\Compiler;
use matthieumastadenis\couleur\ColorFactory;

$sassModulePath = __DIR__ . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'sass';

$spaces = [
    'rgb',
    'hsl',
    'hwb',
    'lab',
    'lch',
    'oklab',
    'oklch',
    'srgb',
    'srgb-linear',
    'display-p3',
    'display-p3-linear',
    'a98-rgb',
    'prophoto-rgb',
    'rec2020',
    'xyz',
    'xyz-d50',
    'xyz-d65',
];

$colors = [
    'hex'               => '#336699',
    'rgba'              => 'rgba(12, 34, 56, 0.4)',
    'hsl'               => 'hsl(210 60% 45%)',
    'hwb'               => 'hwb(210 15% 20%)',
    'lab'               => 'lab(60% 20 -30)',
    'lch'               => 'lch(60% 40 250deg)',
    'oklab'             => 'oklab(55% 0.1 -0.12)',
    'oklch'             => 'oklch(62% 0.18 250deg / 0.7)',
    'wide-gamut'        => 'color(display-p3 0.85 0.3 0.2)',
    'linear-wide-input' => 'color(rec2020 0.4 0.2 0.7)',
];

$couleurAliases = [
    'rgb'               => 'rgb',
    'hsl'               => 'hsl',
    'hwb'               => 'hwb',
    'lab'               => 'lab',
    'lch'               => 'lch',
    'oklab'             => 'oklab',
    'oklch'             => 'oklch',
    'srgb'              => 'srgb',
    'srgb-linear'       => 'srgb-linear',
    'display-p3'        => 'display-p3',
    'display-p3-linear' => 'p3-linear',
    'prophoto-rgb'      => 'prophoto-rgb',
    'xyz'               => 'xyz',
    'xyz-d50'           => 'xyz-d50',
    'xyz-d65'           => 'xyz-d65',
];

$summary = [
    'both'    => 0,
    'node'    => 0,
    'couleur' => 0,
    'no'      => 0,
    'n/a'     => 0,
];

$onlyMismatches = in_array('--only-mismatches', $argv ?? [], true);
$compiler = new Compiler();
$nodeSassResults = loadNodeSassResults($colors, $spaces, $sassModulePath);

echo 'Color conversion comparisons' . PHP_EOL;
echo 'Mode: ' . ($onlyMismatches ? 'only mismatches' : 'full table') . PHP_EOL . PHP_EOL;
echo 'Warning: Couleur currently has known HWB serialization and round-trip issues, so HWB mismatches may be misleading.' . PHP_EOL . PHP_EOL;

foreach ($colors as $label => $inputColor) {
    $rows = [];

    foreach ($spaces as $space) {
        $compilerValue = compilerConversion($compiler, $inputColor, $space);
        $nodeSassValue = $nodeSassResults[comparisonKey($label, $space)] ?? 'n/a';
        $couleurValue  = couleurConversion($inputColor, $space, $couleurAliases);
        $match         = determineMatch($compilerValue, $nodeSassValue, $couleurValue, $space, $couleurAliases);

        $summary[$match]++;

        if ($onlyMismatches && $match === 'both') {
            continue;
        }

        $rows[] = [
            'Space'        => $space,
            'Our compiler' => $compilerValue,
            'Node Sass'    => $nodeSassValue,
            'Couleur'      => $couleurValue,
            'Match'        => $match,
        ];
    }

    echo $label . ': ' . $inputColor . PHP_EOL;

    if ($rows === []) {
        echo '(no rows after filtering)' . PHP_EOL . PHP_EOL;

        continue;
    }

    echo renderTable($rows) . PHP_EOL . PHP_EOL;
}

echo 'Summary' . PHP_EOL;
echo 'Matched both: ' . $summary['both'] . PHP_EOL;
echo 'Matched Node Sass only: ' . $summary['node'] . PHP_EOL;
echo 'Matched Couleur only: ' . $summary['couleur'] . PHP_EOL;
echo 'Matched neither: ' . $summary['no'] . PHP_EOL;
echo 'Unavailable: ' . $summary['n/a'] . PHP_EOL;

function compilerConversion(Compiler $compiler, string $inputColor, string $space): string
{
    $scss = '@use "sass:color"; .x { value: color.to-space(' . $inputColor . ', ' . $space . '); }';

    try {
        return extractValueProperty($compiler->compileString($scss));
    } catch (Throwable $exception) {
        return 'error: ' . trim($exception->getMessage());
    }
}

function couleurConversion(string $inputColor, string $space, array $couleurAliases): string
{
    if (! array_key_exists($space, $couleurAliases)) {
        return 'n/a';
    }

    try {
        $color = ColorFactory::new($inputColor);

        if ($color === null) {
            return 'n/a';
        }

        $converted = $color->to($couleurAliases[$space]);

        return stringifyCouleurColor($converted, $space);
    } catch (Throwable) {
        return 'n/a';
    }
}

function determineMatch(string $compilerValue, string $nodeSassValue, string $couleurValue, string $space, array $couleurAliases): string
{
    $nodeMatch    = compareValues($compilerValue, $nodeSassValue, $space, $couleurAliases);
    $couleurMatch = compareValues($compilerValue, $couleurValue, $space, $couleurAliases);

    if ($nodeMatch === 'yes' && $couleurMatch === 'yes') {
        return 'both';
    }

    if ($nodeMatch === 'yes') {
        return 'node';
    }

    if ($couleurMatch === 'yes') {
        return 'couleur';
    }

    if ($nodeMatch === 'n/a' && $couleurMatch === 'n/a') {
        return 'n/a';
    }

    return 'no';
}

function compareValues(string $leftValue, string $rightValue, string $space, array $couleurAliases): string
{
    if ($leftValue === 'n/a' || $rightValue === 'n/a') {
        return 'n/a';
    }

    if (str_starts_with($leftValue, 'error:') || str_starts_with($rightValue, 'error:')) {
        return 'n/a';
    }

    $leftNormalized  = canonicalizeColor($leftValue, $space, $couleurAliases);
    $rightNormalized = canonicalizeColor($rightValue, $space, $couleurAliases);

    if ($leftNormalized === null || $rightNormalized === null) {
        return 'n/a';
    }

    return colorValuesMatch($leftNormalized, $rightNormalized, toleranceForSpace($space)) ? 'yes' : 'no';
}

function comparisonKey(string $label, string $space): string
{
    return $label . '|' . $space;
}

function loadNodeSassResults(array $colors, array $spaces, string $sassModulePath): array
{
    $tasks = [];

    foreach ($colors as $label => $inputColor) {
        foreach ($spaces as $space) {
            $tasks[] = [
                'key'  => comparisonKey($label, $space),
                'scss' => '@use "sass:color"; .x { value: color.to-space(' . $inputColor . ', ' . $space . '); }',
            ];
        }
    }

    try {
        $payload = (string) json_encode($tasks, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }

    $payloadFile = tempnam(sys_get_temp_dir(), 'color-comparisons-payload-');

    if ($payloadFile === false) {
        return [];
    }

    if (file_put_contents($payloadFile, $payload) === false) {
        @unlink($payloadFile);

        return [];
    }

    $script = implode("\n", [
        'const fs = require("fs");',
        'const sass = require(process.argv[3]);',
        'const payload = fs.readFileSync(process.argv[2], "utf8");',
        'const tasks = JSON.parse(payload);',
        'const results = {};',
        'for (const task of tasks) {',
        '  try {',
        '    results[task.key] = sass.compileString(task.scss).css;',
        '  } catch (error) {',
        '    const message = error && typeof error.message === "string" ? error.message.trim() : String(error).trim();',
        '    results[task.key] = "error: " + message;',
        '  }',
        '}',
        'process.stdout.write(JSON.stringify(results));',
    ]);
    $scriptFile = tempnam(sys_get_temp_dir(), 'color-comparisons-script-');

    if ($scriptFile === false) {
        @unlink($payloadFile);

        return [];
    }

    $scriptFileWithExtension = $scriptFile . '.cjs';

    if (! @rename($scriptFile, $scriptFileWithExtension)) {
        @unlink($scriptFile);
        @unlink($payloadFile);

        return [];
    }

    if (file_put_contents($scriptFileWithExtension, $script) === false) {
        @unlink($scriptFileWithExtension);
        @unlink($payloadFile);

        return [];
    }

    $command = 'node '
        . escapeshellarg($scriptFileWithExtension)
        . ' '
        . escapeshellarg($payloadFile)
        . ' '
        . escapeshellarg($sassModulePath);

    try {
        $output = shell_exec($command);
    } finally {
        @unlink($scriptFileWithExtension);
        @unlink($payloadFile);
    }

    if (! is_string($output) || trim($output) === '') {
        return [];
    }

    try {
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }

    if (! is_array($decoded)) {
        return [];
    }

    $results = [];

    foreach ($decoded as $key => $value) {
        if (! is_string($key) || ! is_string($value)) {
            continue;
        }

        $results[$key] = str_starts_with($value, 'error:') ? $value : extractValueProperty($value);
    }

    return $results;
}

/**
 * @return array{skeleton: string, numbers: list<float>}|null
 */
function canonicalizeColor(string $value, string $space, array $couleurAliases): ?array
{
    if (! array_key_exists($space, $couleurAliases)) {
        return null;
    }

    try {
        $color = ColorFactory::new(normalizeCouleurInput($value));

        if ($color === null) {
            return null;
        }

        $converted = $color->to($couleurAliases[$space]);

        return tokenizeComparableValue(normalizeValue(stringifyCouleurColor($converted, $space)));
    } catch (Throwable) {
        return null;
    }
}

function toleranceForSpace(string $space): float
{
    return match ($space) {
        'rgb',
        'srgb' => 0.6,
        'hsl',
        'hwb' => 0.15,
        'lab',
        'lch',
        'oklab',
        'oklch' => 0.02,
        default => 0.0005,
    };
}

/**
 * @param array{skeleton: string, numbers: list<float>} $left
 * @param array{skeleton: string, numbers: list<float>} $right
 */
function colorValuesMatch(array $left, array $right, float $tolerance): bool
{
    if ($left['skeleton'] !== $right['skeleton']) {
        return false;
    }

    if (count($left['numbers']) !== count($right['numbers'])) {
        return false;
    }

    foreach ($left['numbers'] as $index => $number) {
        if (abs($number - $right['numbers'][$index]) > $tolerance) {
            return false;
        }
    }

    return true;
}

/**
 * @return array{skeleton: string, numbers: list<float>}
 */
function tokenizeComparableValue(string $value): array
{
    $skeleton = '';
    $numbers  = [];
    $length   = strlen($value);
    $index    = 0;

    while ($index < $length) {
        $char = $value[$index];

        if (! isNumberStart($value, $index)) {
            $skeleton .= $char;
            $index++;

            continue;
        }

        $number = '';

        while ($index < $length && isNumberChar($value[$index], $number === '')) {
            $number .= $value[$index];
            $index++;
        }

        $numbers[] = (float) $number;
        $skeleton .= '#';
    }

    return [
        'skeleton' => $skeleton,
        'numbers'  => $numbers,
    ];
}

function isNumberStart(string $value, int $index): bool
{
    $char = $value[$index];

    if ($char >= '0' && $char <= '9') {
        return true;
    }

    if ($char === '.') {
        return isset($value[$index + 1]) && $value[$index + 1] >= '0' && $value[$index + 1] <= '9';
    }

    if ($char !== '+' && $char !== '-') {
        return false;
    }

    if ($index > 0) {
        $previous = $value[$index - 1];

        if (($previous >= '0' && $previous <= '9') || $previous === '.') {
            return false;
        }
    }

    return isset($value[$index + 1]) && (
        ($value[$index + 1] >= '0' && $value[$index + 1] <= '9')
        || $value[$index + 1] === '.'
    );
}

function isNumberChar(string $char, bool $isFirst): bool
{
    if ($char >= '0' && $char <= '9') {
        return true;
    }

    if ($char === '.') {
        return true;
    }

    if ($isFirst && ($char === '+' || $char === '-')) {
        return true;
    }

    return false;
}

function stringifyCouleurColor(object $color, string $space): string
{
    if (! method_exists($color, 'stringify')) {
        return 'n/a';
    }

    $value = match ($space) {
        'rgb',
        'srgb'  => $color->stringify(legacy: true, alpha: true, precision: 10),
        default => $color->stringify(alpha: true, precision: 10),
    };

    return trim((string) $value);
}

function normalizeValue(string $value): string
{
    $value  = strtolower(trim($value));
    $parts  = [];
    $chunk  = '';
    $length = strlen($value);

    for ($i = 0; $i < $length; $i++) {
        $char = substr($value, $i, 1);

        if ($char === ' ' || $char === "\n" || $char === "\r" || $char === "\t") {
            if ($chunk !== '') {
                $parts[] = $chunk;
                $chunk   = '';
            }

            continue;
        }

        $chunk .= $char;
    }

    if ($chunk !== '') {
        $parts[] = $chunk;
    }

    return implode('', $parts);
}

function extractValueProperty(string $css): string
{
    $markerStart = 'value:';
    $start = strpos($css, $markerStart);

    if ($start === false) {
        return trim($css);
    }

    $start += 6;
    $end = strpos($css, ';', $start);

    if ($end === false) {
        return trim(substr($css, $start));
    }

    return trim(substr($css, $start, $end - $start));
}

function normalizeCouleurInput(string $value): string
{
    $normalized = trim($value);

    if (str_starts_with($normalized, 'color(display-p3-linear ')) {
        return 'color(p3-linear ' . substr($normalized, 24);
    }

    return $normalized;
}

function renderTable(array $rows): string
{
    $headers = ['Space', 'Our compiler', 'Node Sass', 'Couleur', 'Match'];
    $widths  = array_fill(0, count($headers), 0);

    foreach ($headers as $index => $header) {
        $widths[$index] = max($widths[$index], strlen($header));
    }

    foreach ($rows as $row) {
        foreach ($headers as $index => $header) {
            $widths[$index] = max($widths[$index], strlen((string) $row[$header]));
        }
    }

    $lines   = [];
    $lines[] = tableRow($headers, $widths);
    $lines[] = tableSeparator($widths);

    foreach ($rows as $row) {
        $lines[] = tableRow([
            $row['Space'],
            $row['Our compiler'],
            $row['Node Sass'],
            $row['Couleur'],
            $row['Match'],
        ], $widths);
    }

    return implode(PHP_EOL, $lines);
}

function tableRow(array $values, array $widths): string
{
    $cells = [];

    foreach ($values as $index => $value) {
        $string  = is_string($value) ? $value : (string) $value;
        $cells[] = str_pad($string, $widths[$index]);
    }

    return '| ' . implode(' | ', $cells) . ' |';
}

function tableSeparator(array $widths): string
{
    $cells = [];

    foreach ($widths as $width) {
        $cells[] = str_repeat('-', $width);
    }

    return '|-' . implode('-|-', $cells) . '-|';
}
