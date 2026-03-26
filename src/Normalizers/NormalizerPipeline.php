<?php

declare(strict_types=1);

namespace Bugo\SCSS\Normalizers;

use Bugo\SCSS\Syntax;

final class NormalizerPipeline
{
    private const NORMALIZERS = [
        NoOpNormalizer::class,
        SassNormalizer::class,
    ];

    /** @var array<int, SourceNormalizer> */
    private array $instances = [];

    public function process(string $source, Syntax $syntax): string
    {
        foreach ($this->get() as $normalizer) {
            if ($normalizer->supports($syntax)) {
                return $normalizer->normalize($source);
            }
        }

        return $source;
    }

    /**
     * @return array<int, SourceNormalizer>
     */
    private function get(): array
    {
        if ($this->instances === []) {
            foreach (self::NORMALIZERS as $normalizerClass) {
                $this->instances[] = new $normalizerClass();
            }
        }

        return $this->instances;
    }
}
