<?php declare(strict_types=1);

use Rector\CodeQuality\Rector\BooleanOr\RepeatedOrEqualToInArrayRector;
use Rector\CodingStyle\Rector\If_\NullableCompareToNullRector;
use Rector\Config\RectorConfig;
use Rector\Exception\Configuration\InvalidConfigurationException;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;

try {
    return RectorConfig::configure()
        ->withPaths([
            __DIR__ . '/src',
        ])
        ->withSkip([
            NullableCompareToNullRector::class,
            NullToStrictStringFuncCallArgRector::class,
            RepeatedOrEqualToInArrayRector::class,
        ])
        ->withPhpSets()
        ->withTypeCoverageLevel(10)
        ->withDeadCodeLevel(10)
        ->withCodeQualityLevel(10)
        ->withCodingStyleLevel(10);
} catch (InvalidConfigurationException $e) {
    echo $e->getMessage();
}
