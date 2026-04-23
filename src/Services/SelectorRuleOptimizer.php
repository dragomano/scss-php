<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use function count;
use function ctype_alpha;
use function ctype_digit;
use function explode;
use function implode;
use function ltrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function substr_count;
use function trim;

final class SelectorRuleOptimizer
{
    public function optimizeRuleBlock(string $ruleBlock): string
    {
        if (substr_count($ruleBlock, ';') < 2 && ! str_contains($ruleBlock, "\n\n")) {
            return $ruleBlock;
        }

        $lines = explode("\n", $ruleBlock);

        if (count($lines) < 3) {
            return $ruleBlock;
        }

        if (! $this->hasPotentialDuplicateTopLevelProperty($lines)) {
            return $ruleBlock;
        }

        $declarationKeys               = [];
        $declarationProperties         = [];
        $lastDeclarationLineByKey      = [];
        $declarationKeyCounts          = [];
        $propertyHasVendorValue        = [];
        $declarationPropertyCounts     = [];
        $lastDeclarationLineByProperty = [];

        $depth = 0;

        foreach ($lines as $index => $line) {
            $trimmedLine = trim($line);

            if ($depth === 1) {
                $declarationKey = $this->extractDeclarationKey($trimmedLine);

                if ($declarationKey !== null) {
                    $property = $this->extractDeclarationProperty($trimmedLine);

                    $declarationKeys[$index]                   = $declarationKey;
                    $lastDeclarationLineByKey[$declarationKey] = $index;
                    $declarationKeyCounts[$declarationKey]     = ($declarationKeyCounts[$declarationKey] ?? 0) + 1;

                    if ($property !== null) {
                        $declarationProperties[$index]            = $property;
                        $lastDeclarationLineByProperty[$property] = $index;
                        $declarationPropertyCounts[$property]     = ($declarationPropertyCounts[$property] ?? 0) + 1;
                        $propertyHasVendorValue[$property]        = ($propertyHasVendorValue[$property] ?? false)
                            || $this->declarationHasVendorValue($trimmedLine);
                    }
                }
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        if ($declarationKeys === []) {
            return $ruleBlock;
        }

        $resultLines = [];

        $depth = 0;

        foreach ($lines as $index => $line) {
            $trimmedLine = trim($line);

            if (isset($declarationKeys[$index])) {
                $declarationKey = $declarationKeys[$index];

                if ($lastDeclarationLineByKey[$declarationKey] !== $index) {
                    $depth += substr_count($line, '{') - substr_count($line, '}');

                    continue;
                }

                $property = $declarationProperties[$index] ?? null;

                if (
                    $property !== null
                    && ! ($propertyHasVendorValue[$property] ?? false)
                    && $lastDeclarationLineByProperty[$property] !== $index
                ) {
                    $depth += substr_count($line, '{') - substr_count($line, '}');

                    continue;
                }
            }

            if ($depth >= 1 && $trimmedLine === '') {
                $depth += substr_count($line, '{') - substr_count($line, '}');

                continue;
            }

            $resultLines[] = $line;

            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return implode("\n", $resultLines);
    }

    public function optimizeAdjacentSiblingRuleBlocks(string $block): string
    {
        $lines = explode("\n", $block);
        $count = count($lines);

        if ($count < 5) {
            return $block;
        }

        $result = [];
        $index  = 0;

        while ($index < $count) {
            $line = $lines[$index];

            if (! $this->isSimpleSiblingRuleStart($lines, $index)) {
                $result[] = $line;
                $index++;

                continue;
            }

            $selector = trim($line);
            $body     = [];

            $index++;

            while ($index < $count && trim($lines[$index]) !== '}') {
                $body[] = $lines[$index];

                $index++;
            }

            if ($index >= $count) {
                $result[] = $line;

                foreach ($body as $bodyLine) {
                    $result[] = $bodyLine;
                }

                break;
            }

            while (true) {
                $nextIndex = $index + 1;

                while ($nextIndex < $count && trim($lines[$nextIndex]) === '') {
                    $nextIndex++;
                }

                if (! $this->isMatchingSimpleSiblingRuleStart($lines, $nextIndex, $selector)) {
                    break;
                }

                $index = $nextIndex + 1;

                while ($index < $count && trim($lines[$index]) !== '}') {
                    $body[] = $lines[$index];

                    $index++;
                }

                if ($index >= $count) {
                    break;
                }
            }

            $result[] = $line;
            foreach ($body as $bodyLine) {
                $result[] = $bodyLine;
            }

            if ($index >= $count) {
                break;
            }

            $result[] = $lines[$index];

            $index++;
        }

        return implode("\n", $result);
    }

    /**
     * @param array<int, string> $lines
     */
    private function hasPotentialDuplicateTopLevelProperty(array $lines): bool
    {
        $seenProperties = [];
        $depth          = 0;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if ($depth === 1) {
                if ($trimmedLine === '') {
                    return true;
                }

                $property = $this->extractDeclarationProperty($trimmedLine);

                if ($property !== null) {
                    if (isset($seenProperties[$property])) {
                        return true;
                    }

                    $seenProperties[$property] = true;
                }
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return false;
    }

    private function extractDeclarationKey(string $line): ?string
    {
        $property = $this->extractDeclarationProperty($line);

        if ($property === null) {
            return null;
        }

        $trimmed        = ltrim($line);
        $separatorIndex = (int) strpos($trimmed, ':');

        return $property . ':' . trim(substr($trimmed, $separatorIndex + 1, -1));
    }

    private function extractDeclarationProperty(string $line): ?string
    {
        if ($line === '' || ! str_ends_with($line, ';')) {
            return null;
        }

        $trimmed = ltrim($line);

        $length = strlen($trimmed);
        $index  = 0;
        $first  = $trimmed[$index];

        if (! (ctype_alpha($first) || $first === '-')) {
            return null;
        }

        $property = $first;

        $index++;

        while ($index < $length) {
            $char = $trimmed[$index];

            if (ctype_alpha($char) || ctype_digit($char) || $char === '-') {
                $property .= $char;

                $index++;

                continue;
            }

            break;
        }

        while ($index < $length && $trimmed[$index] === ' ') {
            $index++;
        }

        if ($index >= $length || $trimmed[$index] !== ':') {
            return null;
        }

        return strtolower($property);
    }

    private function declarationHasVendorValue(string $line): bool
    {
        $trimmedLine    = ltrim($line);
        $separatorIndex = (int) strpos($trimmedLine, ':');

        $trimmed = trim(substr($trimmedLine, $separatorIndex + 1, -1));

        foreach (['-webkit-', '-moz-', '-ms-', '-o-'] as $prefix) {
            if (str_starts_with(strtolower($trimmed), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $lines
     */
    private function isSimpleSiblingRuleStart(array $lines, int $index): bool
    {
        $line = trim($lines[$index] ?? '');

        if ($line === '' || str_starts_with($line, '@') || ! str_ends_with($line, '{')) {
            return false;
        }

        $nextLine = $lines[$index + 1] ?? null;

        if ($nextLine === null || trim($nextLine) === '' || trim($nextLine) === '}') {
            return false;
        }

        return ! str_ends_with(trim($nextLine), '{');
    }

    /**
     * @param array<int, string> $lines
     */
    private function isMatchingSimpleSiblingRuleStart(array $lines, int $index, string $selector): bool
    {
        if (! isset($lines[$index]) || trim($lines[$index]) !== $selector) {
            return false;
        }

        return $this->isSimpleSiblingRuleStart($lines, $index);
    }
}
