<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use function count;
use function ctype_alpha;
use function ctype_digit;
use function explode;
use function implode;
use function ltrim;
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
        $lines = explode("\n", $ruleBlock);

        if (count($lines) < 3) {
            return $ruleBlock;
        }

        $declarationKeys               = [];
        $declarationProperties         = [];
        $lastDeclarationLineByKey      = [];
        $declarationKeyCounts          = [];
        $propertyHasVendorValue        = [];
        $declarationPropertyCounts     = [];
        $lastDeclarationLineByProperty = [];

        $hasInnerBlankLines      = false;
        $hasDuplicateDeclaration = false;
        $hasDuplicateProperty    = false;

        $depth = 0;

        foreach ($lines as $index => $line) {
            $trimmedLine = trim($line);

            if ($depth === 1) {
                if ($trimmedLine === '') {
                    $hasInnerBlankLines = true;
                }

                $declarationKey = $this->extractDeclarationKey($trimmedLine);

                if ($declarationKey !== null) {
                    $property = $this->extractDeclarationProperty($trimmedLine);

                    $declarationKeys[$index]                   = $declarationKey;
                    $lastDeclarationLineByKey[$declarationKey] = $index;
                    $declarationKeyCounts[$declarationKey]     = ($declarationKeyCounts[$declarationKey] ?? 0) + 1;

                    if ($declarationKeyCounts[$declarationKey] > 1) {
                        $hasDuplicateDeclaration = true;
                    }

                    if ($property !== null) {
                        $declarationProperties[$index]            = $property;
                        $lastDeclarationLineByProperty[$property] = $index;
                        $declarationPropertyCounts[$property]     = ($declarationPropertyCounts[$property] ?? 0) + 1;
                        $propertyHasVendorValue[$property]        = ($propertyHasVendorValue[$property] ?? false)
                            || $this->declarationHasVendorValue($trimmedLine);

                        if ($declarationPropertyCounts[$property] > 1) {
                            $hasDuplicateProperty = true;
                        }
                    }
                }
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        if ($declarationKeys === []) {
            return $ruleBlock;
        }

        if (! $hasDuplicateDeclaration && ! $hasDuplicateProperty && ! $hasInnerBlankLines) {
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

            $result[] = $lines[$index];

            $index++;
        }

        return implode("\n", $result);
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
        $property = $this->extractDeclarationProperty($line);

        if ($property === null) {
            return false;
        }

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
