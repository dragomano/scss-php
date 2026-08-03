<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Conversion;

use Bugo\Iris\Exceptions\UnsupportedColorSpace;
use Bugo\Iris\Operations\GamutMapper;
use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\Iris\Spaces\XyzColor;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use LogicException;

use function abs;
use function count;
use function in_array;
use function strtolower;

final readonly class ColorSpaceConverter
{
    private const LIGHTNESS_BOUNDARY_EPSILON = 1e-10;

    private const RGB_FAMILY_SPACES = ['srgb', 'srgb-linear', 'display-p3', 'display-p3-linear', 'a98-rgb', 'rec2020'];

    /**
     * Direct linear-light RGB conversion matrices (Dart Sass, lib/src/value/color/conversions.dart)
     *
     * @var array<string, array{float, float, float, float, float, float, float, float, float}>
     */
    private const RGB_FAMILY_LINEAR_MATRICES = [
        'srgb|srgb'             => [1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0],
        'display-p3|display-p3' => [1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0],
        'a98-rgb|a98-rgb'       => [1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0],
        'rec2020|rec2020'       => [1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0],
        'srgb|display-p3'       => [0.82246196871436230, 0.17753803128563775, 0.0, 0.03319419885096161, 0.96680580114903840, 0.0, 0.01708263072112003, 0.07239744066396346, 0.91051992861491650],
        'display-p3|srgb'       => [1.22494017628055980, -0.22494017628055996, 0.0, -0.04205695470968816, 1.04205695470968800, 0.0, -0.01963755459033443, -0.07863604555063188, 1.09827360014096630],
        'srgb|a98-rgb'          => [0.71512560685562470, 0.28487439314437535, 0.0, 0.0, 1.0, 0.0, 0.0, 0.04116194845011846, 0.95883805154988160],
        'a98-rgb|srgb'          => [1.39835574396077830, -0.39835574396077830, 0.0, 0.0, 1.0, 0.0, 0.0, -0.04292898929447326, 1.04292898929447330],
        'srgb|rec2020'          => [0.62740389593469900, 0.32928303837788370, 0.04331306568741722, 0.06909728935823208, 0.91954039507545870, 0.01136231556630917, 0.01639143887515027, 0.08801330787722575, 0.89559525324762400],
        'rec2020|srgb'          => [1.66049100210843450, -0.58764113878854950, -0.07284986331988487, -0.12455047452159074, 1.13289989712596030, -0.00834942260436947, -0.01815076335490530, -0.10057889800800737, 1.11872966136291270],
        'display-p3|a98-rgb'    => [0.86400513747404840, 0.13599486252595164, 0.0, -0.04205695470968816, 1.04205695470968800, 0.0, -0.02056038078232985, -0.03250613804550798, 1.05306651882783790],
        'a98-rgb|display-p3'    => [1.15009441814101840, -0.15009441814101834, 0.0, 0.04641729862941844, 0.95358270137058150, 0.0, 0.02388759479083904, 0.02650477632633013, 0.94960762888283080],
        'display-p3|rec2020'    => [0.75383303436172180, 0.19859736905261630, 0.04756959658566187, 0.04574384896535833, 0.94177721981169350, 0.01247893122294812, -0.00121034035451832, 0.01760171730108989, 0.98360862305342840],
        'rec2020|display-p3'    => [1.34357825258433200, -0.28217967052613570, -0.06139858205819628, -0.06529745278911953, 1.07578791584857460, -0.01049046305945495, 0.00282178726170095, -0.01959849452449406, 1.01677670726279310],
        'rec2020|a98-rgb'       => [1.15197839471591630, -0.09750305530240860, -0.05447533941350766, -0.12455047452159074, 1.13289989712596030, -0.00834942260436947, -0.02253038278105590, -0.04980650742838876, 1.07233689020944460],
        'a98-rgb|rec2020'       => [0.87733384166365680, 0.07749370651571998, 0.04517245182062317, 0.09662259146620378, 0.89152732024418050, 0.01185008828961569, 0.02292106270284839, 0.04303668501067932, 0.93404225228647230],
    ];

    /**
     * ProPhoto RGB (D50) linear → XYZ D50 matrix (Dart Sass, lib/src/value/color/conversions.dart)
     *
     * @var array{float, float, float, float, float, float, float, float, float}
     */
    private const PROPHOTO_TO_XYZ_D50_MATRIX = [
        0.79776664490064230, 0.13518129740053308, 0.03134773412839220,
        0.28807482881940130, 0.71183523424187300, 0.00008993693872564,
        0.0,                 0.0,                 0.82510460251046020,
    ];

    public function __construct(
        private ColorRuntime $runtime,
        private ColorNodeConverter $converter,
        private GamutMapper $gamutMapper = new GamutMapper(),
    ) {}

    /**
     * @param array<int, AstNode> $positional
     */
    public function toSpace(array $positional): AstNode
    {
        $color       = $this->runtime->argumentParser->requireColor($positional, 0, 'to-space');
        $space       = strtolower($this->runtime->argumentParser->asString($positional[1] ?? null, 'to-space'));
        $nativeSpace = $this->converter->detectNativeColorSpace($color);

        if (
            $color instanceof FunctionNode
            && ($nativeSpace === $space || ($nativeSpace === 'xyz' && $space === 'xyz-d65'))
            && ! in_array($space, ['rgb', 'hsl', 'hwb'], true)
        ) {
            if (in_array($space, ['lch', 'lab', 'oklch', 'oklab'], true) && $this->isLightnessOutOfRange($color, $nativeSpace)) {
                $xyz   = $this->extractXyzD65ForColorMix($color);
                $alpha = $this->converter->toAlpha($color);

                return $this->buildColorMixNode($space, $xyz, $alpha);
            }

            return $color;
        }

        if (
            ($color instanceof FunctionNode || $color instanceof ColorNode)
            && $nativeSpace === $space
            && in_array($space, ['rgb', 'hsl'], true)
        ) {
            [$r, $g, $b] = $this->extractUnclampedSrgbChannels($color);

            $inGamut = $r >= 0.0 && $r <= 255.0
                && $g >= 0.0 && $g <= 255.0
                && $b >= 0.0 && $b <= 255.0;

            if ($inGamut) {
                return $color;
            }
        }

        if ($space === 'lch') {
            $xyz50 = $this->converter->toXyzD50($color);
            $lch   = $this->runtime->spaceConverter->xyzD50ToLch($xyz50);
            $alpha = $this->converter->toAlpha($color);

            $hslWithMissing = $this->toHslWithMissingChannels($color);
            $lightnessNode  = new NumberNode($lch->lValue(), '%');
            $hueNode        = new NumberNode($lch->hValue(), 'deg');

            if ($color instanceof FunctionNode && strtolower($color->name) === 'oklch') {
                $oklch = $this->extractOklchMixData($color);
                $lch   = $this->runtime->spaceConverter->oklchToLch(new OklchColor(
                    l: $oklch['l'],
                    c: $oklch['c'],
                    h: $oklch['h'],
                    a: $oklch['a'],
                ));

                if (
                    ! $oklch['l_missing'] && ($lch->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                    || $lch->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
                ) {
                    return $this->buildColorMixNode('lch', $this->extractXyzD65ForColorMix($color), $oklch['a']);
                }

                $lightnessNode = $oklch['l_missing']
                    ? new StringNode('none')
                    : new NumberNode($lch->lValue(), '%');

                $chromaNode = $oklch['c_missing']
                    ? new StringNode('none')
                    : new NumberNode($lch->cValue());

                $hueNode = $oklch['h_missing'] || $oklch['c_missing'] || abs($lch->cValue()) < 0.0000001
                    ? new StringNode('none')
                    : new NumberNode($lch->hValue(), 'deg');

                return $this->converter->buildFunctionalColorNode('lch', [
                    $lightnessNode,
                    $chromaNode,
                    $hueNode,
                ], $oklch['a']);
            }

            if (($hslWithMissing !== null && $hslWithMissing->h === null) || abs($lch->cValue()) < 0.0000001) {
                $hueNode = new StringNode('none');
            }

            if (
                ! $this->isSemanticChannelMissing($color) && ($lch->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                || $lch->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
            ) {
                return $this->buildColorMixNode('lch', $this->extractXyzD65ForColorMix($color), $alpha);
            }

            if (
                $color instanceof FunctionNode
                && ! $this->isSemanticChannelMissing($color)
                && $this->extractLegacySrgbChannelsUnclamped($color) !== null
            ) {
                $unclampedXyz = $this->computeUnclampedXyzD65($color);

                if ($unclampedXyz !== null) {
                    [$dx, $dy, $dz] = $this->runtime->spaceConverter->d65ToD50((float) $unclampedXyz->x, (float) $unclampedXyz->y, (float) $unclampedXyz->z);

                    $unclampedLch = $this->runtime->spaceConverter->xyzD50ToLch(new XyzColor(x: $dx, y: $dy, z: $dz));

                    if ($unclampedLch->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON || $unclampedLch->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON) {
                        return $this->buildColorMixNode('lch', $unclampedXyz, $alpha);
                    }
                }
            }

            if ($this->isSemanticChannelMissing($color)) {
                $lightnessNode = $this->missingStringNode();
            }

            return $this->converter->buildFunctionalColorNode('lch', [
                $lightnessNode,
                new NumberNode($lch->cValue()),
                $hueNode,
            ], $alpha);
        }

        if ($space === 'oklch') {
            if ($color instanceof FunctionNode && strtolower($color->name) === 'lch') {
                $lchData = $this->extractLchMissingData($color);

                $lchColor = $this->runtime->spaceConverter->xyzD65ToOklch(
                    $this->runtime->spaceConverter->lchToXyzD65($lchData['l'], $lchData['c'], $lchData['h']),
                );

                if (
                    ! $lchData['l_missing'] && ($lchColor->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                    || $lchColor->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
                ) {
                    return $this->buildColorMixNode('oklch', $this->extractXyzD65ForColorMix($color), $lchData['a']);
                }

                $lightnessNode = $lchData['l_missing']
                    ? new StringNode('none')
                    : new NumberNode($lchColor->lValue(), '%');

                $chromaNode = $lchData['c_missing']
                    ? new StringNode('none')
                    : new NumberNode($lchColor->cValue());

                $hueNode = $lchData['h_missing'] || $lchData['c_missing'] || abs($lchColor->cValue()) < 0.0000001
                    ? new StringNode('none')
                    : new NumberNode($lchColor->hValue(), 'deg');

                return $this->converter->buildFunctionalColorNode('oklch', [
                    $lightnessNode,
                    $chromaNode,
                    $hueNode,
                ], $lchData['a']);
            }

            $xyzD65 = $color instanceof FunctionNode ? $this->converter->toXyzD65WithAlpha($color) : null;
            $oklch  = $xyzD65 === null
                ? $this->toOklchPreservingMissingChannels($color)
                : $this->runtime->spaceConverter->xyzD65ToOklch($xyzD65[0], $xyzD65[1]);

            if (
                ! $this->isSemanticChannelMissing($color) && ($oklch->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                || $oklch->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
            ) {
                return $this->buildColorMixNode('oklch', $this->extractXyzD65ForColorMix($color), $oklch->a);
            }

            if (
                $color instanceof FunctionNode
                && ! $this->isSemanticChannelMissing($color)
                && $this->extractLegacySrgbChannelsUnclamped($color) !== null
            ) {
                $unclampedXyz   = $this->computeUnclampedXyzD65($color);

                if ($unclampedXyz !== null) {
                    $unclampedOklch = $this->runtime->spaceConverter->xyzD65ToOklch($unclampedXyz);

                    if (
                        $unclampedOklch->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                        || $unclampedOklch->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON
                    ) {
                        return $this->buildColorMixNode('oklch', $unclampedXyz, $unclampedOklch->a);
                    }
                }
            }

            $lightnessNode = new NumberNode($oklch->lValue(), '%');
            $hueNode       = new NumberNode($oklch->hValue(), 'deg');

            if ($this->isSemanticChannelMissing($color)) {
                $lightnessNode = $this->missingStringNode();
            }

            if (abs($oklch->cValue()) < 0.0000001) {
                $hueNode = new StringNode('none');
            }

            return $this->converter->buildFunctionalColorNode('oklch', [
                $lightnessNode,
                new NumberNode($oklch->cValue()),
                $hueNode,
            ], $oklch->a);
        }

        if ($space === 'lab') {
            if ($color instanceof FunctionNode && strtolower($color->name) === 'lch') {
                $lchData = $this->extractLchMissingData($color);

                if ($lchData['l_missing']) {
                    return $this->converter->buildFunctionalColorNode('lab', [
                        $this->missingStringNode(),
                        $this->missingStringNode(),
                        $this->missingStringNode(),
                    ], $lchData['a']);
                }

                $labColor = $this->runtime->spaceConverter->xyzD50ToLab(
                    $this->converter->toXyzD50($color),
                    $this->converter->toAlpha($color),
                );

                if ($labColor->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON || $labColor->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON) {
                    return $this->buildColorMixNode('lab', $this->extractXyzD65ForColorMix($color), $lchData['a']);
                }

                $chromaZero = abs($lchData['c']) < 0.0000001;

                if ($chromaZero && ($lchData['h_missing'] || abs($lchData['l']) < 0.0000001)) {
                    return $this->converter->buildFunctionalColorNode('lab', [
                        new NumberNode($labColor->lValue(), '%'),
                        $this->missingStringNode(),
                        $this->missingStringNode(),
                    ], $lchData['a']);
                }

                return $this->converter->buildFunctionalColorNode('lab', [
                    new NumberNode($labColor->lValue(), '%'),
                    new NumberNode($labColor->aValue()),
                    new NumberNode($labColor->bValue()),
                ], $lchData['a']);
            }

            if ($color instanceof FunctionNode && strtolower($color->name) === 'oklab') {
                $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);

                [$channels, $alpha] = $this->runtime->arguments->splitChannelsAndAlpha($expandedArgs);

                $lightnessMissing = isset($channels[0]) && $this->runtime->argumentParser->isMissingChannelNode($channels[0]);
                $aMissing         = isset($channels[1]) && $this->runtime->argumentParser->isMissingChannelNode($channels[1]);
                $bMissing         = isset($channels[2]) && $this->runtime->argumentParser->isMissingChannelNode($channels[2]);

                $labColor = $this->runtime->spaceConverter->xyzD50ToLab(
                    $this->converter->toXyzD50($color),
                    $this->converter->toAlpha($color),
                );

                if (
                    ! $lightnessMissing && ($labColor->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                    || $labColor->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
                ) {
                    return $this->buildColorMixNode('lab', $this->extractXyzD65ForColorMix($color), $labColor->alpha);
                }

                $lightnessNode = $lightnessMissing
                    ? $this->missingStringNode()
                    : new NumberNode($labColor->lValue(), '%');

                $aNode = $aMissing
                    ? $this->missingStringNode()
                    : new NumberNode($labColor->aValue());

                $bNode = $bMissing
                    ? $this->missingStringNode()
                    : new NumberNode($labColor->bValue());

                return $this->converter->buildFunctionalColorNode('lab', [
                    $lightnessNode,
                    $aNode,
                    $bNode,
                ], $labColor->alpha);
            }

            $labColor = $this->runtime->spaceConverter->xyzD50ToLab(
                $this->converter->toXyzD50($color),
                $this->converter->toAlpha($color),
            );

            if (
                ! $this->isSemanticChannelMissing($color) && ($labColor->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                || $labColor->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
            ) {
                return $this->buildColorMixNode('lab', $this->extractXyzD65ForColorMix($color), $labColor->alpha);
            }

            if (
                $color instanceof FunctionNode
                && ! $this->isSemanticChannelMissing($color)
                && $this->extractLegacySrgbChannelsUnclamped($color) !== null
            ) {
                $unclampedXyz = $this->computeUnclampedXyzD65($color);

                if ($unclampedXyz !== null) {
                    [$dx, $dy, $dz] = $this->runtime->spaceConverter->d65ToD50((float) $unclampedXyz->x, (float) $unclampedXyz->y, (float) $unclampedXyz->z);

                    $xyzD50       = new XyzColor(x: $dx, y: $dy, z: $dz);
                    $unclampedLab = $this->runtime->spaceConverter->xyzD50ToLab($xyzD50, $this->converter->toAlpha($color));

                    if ($unclampedLab->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON || $unclampedLab->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON) {
                        return $this->buildColorMixNode('lab', $unclampedXyz, $unclampedLab->alpha);
                    }
                }
            }

            if ($this->isSemanticChannelMissing($color)) {
                return $this->converter->buildFunctionalColorNode('lab', [
                    $this->missingStringNode(),
                    new NumberNode($labColor->aValue()),
                    new NumberNode($labColor->bValue()),
                ], $labColor->alpha);
            }

            return $this->converter->buildLabColorNode($labColor);
        }

        if ($space === 'oklab') {
            if ($color instanceof FunctionNode && strtolower($color->name) === 'lab') {
                $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);

                [$channels, $alpha] = $this->runtime->arguments->splitChannelsAndAlpha($expandedArgs);

                $lightnessMissing = isset($channels[0]) && $this->runtime->argumentParser->isMissingChannelNode($channels[0]);
                $aMissing         = isset($channels[1]) && $this->runtime->argumentParser->isMissingChannelNode($channels[1]);
                $bMissing         = isset($channels[2]) && $this->runtime->argumentParser->isMissingChannelNode($channels[2]);

                $oklab = $this->runtime->spaceConverter->xyzD65ToOklab(
                    $this->converter->toXyzD65($color),
                    $this->converter->toAlpha($color),
                );

                if (
                    ! $lightnessMissing && ($oklab->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                    || $oklab->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
                ) {
                    return $this->buildColorMixNode('oklab', $this->extractXyzD65ForColorMix($color), $oklab->alpha);
                }

                $lightnessNode = $lightnessMissing
                    ? $this->missingStringNode()
                    : new NumberNode($oklab->lValue(), '%');

                $aNode = $aMissing
                    ? $this->missingStringNode()
                    : new NumberNode($oklab->aValue());

                $bNode = $bMissing
                    ? $this->missingStringNode()
                    : new NumberNode($oklab->bValue());

                return $this->converter->buildFunctionalColorNode('oklab', [
                    $lightnessNode,
                    $aNode,
                    $bNode,
                ], $oklab->alpha);
            }

            $xyzD65 = $this->converter->toXyzD65($color);
            $oklab  = $this->runtime->spaceConverter->xyzD65ToOklab(
                $xyzD65,
                $this->converter->toAlpha($color),
            );

            if (
                ! $this->isSemanticChannelMissing($color) && ($oklab->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                || $oklab->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
            ) {
                return $this->buildColorMixNode('oklab', $this->extractXyzD65ForColorMix($color), $oklab->alpha);
            }

            if (
                $color instanceof FunctionNode
                && ! $this->isSemanticChannelMissing($color)
                && $this->extractLegacySrgbChannelsUnclamped($color) !== null
            ) {
                $unclampedXyz  = $this->computeUnclampedXyzD65($color);

                if ($unclampedXyz !== null) {
                    $unclampedOklab = $this->runtime->spaceConverter->xyzD65ToOklab($unclampedXyz, $this->converter->toAlpha($color));

                    if (
                        $unclampedOklab->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                        || $unclampedOklab->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON
                    ) {
                        return $this->buildColorMixNode('oklab', $unclampedXyz, $unclampedOklab->alpha);
                    }
                }
            }

            if ($this->isSemanticChannelMissing($color)) {
                return $this->converter->buildFunctionalColorNode('oklab', [
                    $this->missingStringNode(),
                    new NumberNode($oklab->aValue()),
                    new NumberNode($oklab->bValue()),
                ], $oklab->alpha);
            }

            return $this->converter->buildOklabColorNode($oklab);
        }

        if ($space === 'xyz-d50') {
            if ($color instanceof FunctionNode && strtolower($color->name) === 'color') {
                $inputSpace = $this->converter->detectNativeColorSpace($color);

                if ($inputSpace !== 'xyz-d50') {
                    return $this->convertGenericToXyzD50($color);
                }
            }

            $xyz = $this->converter->toXyzD50($color);

            return $this->converter->buildGenericColorFunctionNode(
                'xyz-d50',
                [(float) $xyz->x, (float) $xyz->y, (float) $xyz->z],
                $this->converter->toAlpha($color),
            );
        }

        if ($space === 'xyz' || $space === 'xyz-d65') {
            if ($color instanceof FunctionNode && strtolower($color->name) === 'color') {
                $inputSpace = $this->converter->detectNativeColorSpace($color);

                if (! in_array($inputSpace, [$space, 'xyz'], true)) {
                    return $this->convertGenericToXyzD65($color, $space);
                }
            }

            $xyz = $this->converter->toXyzD65($color);

            return $this->converter->buildGenericColorFunctionNode(
                $space,
                [(float) $xyz->x, (float) $xyz->y, (float) $xyz->z],
                $this->converter->toAlpha($color),
            );
        }

        if (in_array($space, ['display-p3-linear', 'display-p3', 'srgb-linear', 'srgb', 'a98-rgb', 'rec2020'], true)) {
            return $this->toXyzD65GenericSpace($color, $space);
        }

        if ($space === 'prophoto-rgb') {
            return $this->toXyzD50GenericSpace($color, $space);
        }

        if (! in_array($space, ['rgb', 'hsl', 'hwb'], true)) {
            throw new UnsupportedColorSpaceException($space, $this->runtime->context->errorCtx('to-space'));
        }

        if ($space === 'hsl' || $space === 'hwb') {
            $alpha = $this->converter->toAlpha($color);

            [$r, $g, $b] = $this->extractUnclampedSrgbChannels($color, false);

            if ($space === 'hwb') {
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);

                if ($max < 1e-10) {
                    return new ColorNode('black');
                }

                if (abs($max - 255.0) < 1e-10 && abs($min - 255.0) < 1e-10) {
                    return new ColorNode('white');
                }
            }

            return $this->converter->serializeAsUnclampedHsl($r, $g, $b, $alpha, true);
        }

        $rgb = $this->converter->toRgb($color);

        [$r, $g, $b] = $this->extractUnclampedSrgbChannels($color);

        $alpha = $this->converter->toAlpha($color);

        $inGamut = $r >= 0.0 && $r <= 255.0
            && $g >= 0.0 && $g <= 255.0
            && $b >= 0.0 && $b <= 255.0;

        if (! $inGamut && ! $this->isSemanticChannelMissing($color)) {
            return $this->converter->serializeAsUnclampedHsl($r, $g, $b, $alpha, true);
        }

        return $this->converter->serializeRgbFromAstSource($color, $rgb);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function extractUnclampedSrgbChannels(AstNode $color, bool $missingAsZero = true): array
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'color') {
            $inputSpace = $this->converter->detectNativeColorSpace($color);

            if ($inputSpace === 'srgb') {
                $channels = $this->converter->extractChannelNodes($color);
                $none     = new StringNode('none');

                $r = $channels[1] ?? $none;
                $g = $channels[2] ?? $none;
                $b = $channels[3] ?? $none;

                return [
                    ($this->runtime->argumentParser->isMissingChannelNode($r) ? 0.0 : $this->runtime->argumentParser->asNumber($r, 'to-space')) * 255.0,
                    ($this->runtime->argumentParser->isMissingChannelNode($g) ? 0.0 : $this->runtime->argumentParser->asNumber($g, 'to-space')) * 255.0,
                    ($this->runtime->argumentParser->isMissingChannelNode($b) ? 0.0 : $this->runtime->argumentParser->asNumber($b, 'to-space')) * 255.0,
                ];
            }

            if ($inputSpace === 'srgb-linear') {
                $channels = $this->converter->extractChannelNodes($color);
                $none     = new StringNode('none');

                $r = $channels[1] ?? $none;
                $g = $channels[2] ?? $none;
                $b = $channels[3] ?? $none;

                return [
                    $this->runtime->spaceConverter->gamSrgb(
                        $this->runtime->argumentParser->isMissingChannelNode($r) ? 0.0 : $this->runtime->argumentParser->asNumber($r, 'to-space'),
                    ) * 255.0,
                    $this->runtime->spaceConverter->gamSrgb(
                        $this->runtime->argumentParser->isMissingChannelNode($g) ? 0.0 : $this->runtime->argumentParser->asNumber($g, 'to-space'),
                    ) * 255.0,
                    $this->runtime->spaceConverter->gamSrgb(
                        $this->runtime->argumentParser->isMissingChannelNode($b) ? 0.0 : $this->runtime->argumentParser->asNumber($b, 'to-space'),
                    ) * 255.0,
                ];
            }

            $channels = $this->converter->extractChannelNodes($color);

            $numericValues = [];
            $missing       = [];

            for ($i = 0; $i < 3; $i++) {
                $node = $channels[$i + 1] ?? new StringNode('none');
                if ($this->runtime->argumentParser->isMissingChannelNode($node)) {
                    $missing[$i]       = true;
                    $numericValues[$i] = 0.0;
                } else {
                    $missing[$i]       = false;
                    $numericValues[$i] = $this->runtime->argumentParser->asNumber($node, 'to-space');
                }
            }

            if (in_array($inputSpace, ['display-p3', 'display-p3-linear', 'a98-rgb'], true)) {
                $srgb = $this->convertRgbFamilyChannels(
                    $inputSpace,
                    [$numericValues[0], $numericValues[1], $numericValues[2]],
                    'srgb',
                );

                $srgb = [$srgb[0] * 255.0, $srgb[1] * 255.0, $srgb[2] * 255.0];

                if ($missingAsZero) {
                    $srgb[0] = $missing[0] ? 0.0 : $srgb[0];
                    $srgb[1] = $missing[1] ? 0.0 : $srgb[1];
                    $srgb[2] = $missing[2] ? 0.0 : $srgb[2];
                }

                return $srgb;
            }

            $xyzD65 = $this->forwardToXyzD65Unclamped($inputSpace, $numericValues);
            $lin    = $this->runtime->spaceConverter->xyzD65ToSrgbChannels($xyzD65);

            if (! $missingAsZero) {
                return [
                    $lin[0] * 255.0,
                    $lin[1] * 255.0,
                    $lin[2] * 255.0,
                ];
            }

            return [
                $missing[0] ? 0.0 : $lin[0] * 255.0,
                $missing[1] ? 0.0 : $lin[1] * 255.0,
                $missing[2] ? 0.0 : $lin[2] * 255.0,
            ];
        }

        if ($color instanceof FunctionNode) {
            $nativeChannels = $this->extractNativeLabLchOklabOklchUnclamped($color);

            if ($nativeChannels !== null) {
                return $nativeChannels;
            }
        }

        if ($color instanceof FunctionNode) {
            $legacyChannels = $this->extractLegacySrgbChannelsUnclamped($color);

            if ($legacyChannels !== null) {
                return [$legacyChannels[0], $legacyChannels[1], $legacyChannels[2]];
            }
        }

        // Fallback for ColorNode/StringNode
        $rgb = $this->converter->toRgb($color);

        return [$rgb->rValue(), $rgb->gValue(), $rgb->bValue()];
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function toGamut(array $positional, array $named): AstNode
    {
        $color = $this->runtime->argumentParser->requireColor($positional, 0, 'to-gamut');

        $space = strtolower($this->runtime->argumentParser->asString(
            $named['space'] ?? ($positional[1] ?? new StringNode('rgb')),
            'to-gamut',
        ));

        $method = strtolower($this->runtime->argumentParser->asString(
            $named['method'] ?? ($positional[2] ?? new StringNode('local-minde')),
            'to-gamut',
        ));

        $nativeSpace = $this->converter->detectNativeColorSpace($color);

        if (! in_array($method, ['local-minde', 'clip'], true)) {
            throw new UnsupportedColorValueException("Unknown gamut mapping method: $method");
        }

        if ($space === 'rgb' || $space === 'srgb') {
            $rgb = $this->converter->toUnclampedRgb($color);

            if ($nativeSpace === 'oklch') {
                return $this->toGamutFromOklch($color, $method);
            }

            $isInGamut = $rgb->r >= 0.0 && $rgb->r <= 255.0
                && $rgb->g >= 0.0 && $rgb->g <= 255.0
                && $rgb->b >= 0.0 && $rgb->b <= 255.0;

            if ($isInGamut) {
                return $color;
            }

            if ($method === 'clip') {
                return $this->serializeRgbForOriginalSpace($nativeSpace, new RgbColor(
                    r: $this->runtime->argumentParser->clamp($rgb->rValue(), 255.0),
                    g: $this->runtime->argumentParser->clamp($rgb->gValue(), 255.0),
                    b: $this->runtime->argumentParser->clamp($rgb->bValue(), 255.0),
                    a: $rgb->a,
                ));
            }

            $oklch    = $this->runtime->spaceConverter->rgbToOklch($rgb);
            $mapped   = $this->gamutMapper->localMinde($oklch);
            $finalRgb = $this->runtime->spaceConverter->oklchToRgb($mapped);

            return $this->serializeRgbForOriginalSpace($nativeSpace, new RgbColor(
                r: $this->runtime->argumentParser->clamp($finalRgb->rValue() * 255.0, 255.0),
                g: $this->runtime->argumentParser->clamp($finalRgb->gValue() * 255.0, 255.0),
                b: $this->runtime->argumentParser->clamp($finalRgb->bValue() * 255.0, 255.0),
                a: $finalRgb->a,
            ));
        }

        throw new UnsupportedColorSpaceException($space, $this->runtime->context->errorCtx('to-gamut'));
    }

    public function toGamutFromOklch(AstNode $color, string $method): AstNode
    {
        $oklch  = $this->extractOklchColor($color);
        $mapped = $method === 'clip'
            ? $this->gamutMapper->clip($oklch)
            : $this->gamutMapper->localMinde($oklch);

        return $this->converter->serializeAsOklchString($mapped);
    }

    public function serializeRgbForOriginalSpace(string $space, RgbColor $rgb): AstNode
    {
        if ($space === 'rgb' || $space === 'srgb') {
            return $this->converter->fromRgb($rgb);
        }

        if (in_array($space, ['oklch', 'oklab', 'lch', 'lab'], true)) {
            return $this->toSpace([
                $this->converter->fromRgb($rgb),
                new StringNode($space),
            ]);
        }

        return $this->converter->fromRgb($rgb);
    }

    public function toOklchPreservingMissingChannels(AstNode $color): OklchColor
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'lch') {
            $channels  = $this->converter->extractChannelNodes($color);
            $lightness = $this->runtime->argumentParser->isMissingChannelNode($channels[0] ?? new StringNode('none'))
                ? 0.0
                : $this->runtime->argumentParser->clamp(
                    $this->runtime->argumentParser->asPercentage($channels[0] ?? null, 'to-space'),
                    100.0,
                );

            $chroma = $this->runtime->argumentParser->asAbsoluteChannel(
                $channels[1] ?? null,
                'to-space',
                150.0,
            );

            $hue = $this->runtime->argumentParser->normalizeHue(
                $this->runtime->argumentParser->asHueAngle($channels[2] ?? null, 'to-space'),
            );

            return $this->runtime->spaceConverter->xyzD65ToOklch(
                $this->runtime->spaceConverter->lchToXyzD65($lightness, $chroma, $hue),
            );
        }

        return $this->runtime->spaceConverter->rgbToOklch($this->converter->toRgb($color));
    }

    public function extractOklchColor(AstNode $color): OklchColor
    {
        return $this->converter->extractOklch($color, 'to-gamut');
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function rgbToWorkingSpaceChannels(RgbColor $rgb, string $space): array
    {
        /** @var array{0: float, 1: float, 2: float} $channels */
        $channels = match ($space) {
            'display-p3'   => $this->runtime->spaceConverter->rgbToP3Channels($rgb),
            'a98-rgb'      => $this->runtime->spaceConverter->rgbToA98Channels($rgb),
            'prophoto-rgb' => $this->runtime->spaceConverter->rgbToProphotoChannels($rgb),
            'rec2020'      => $this->runtime->spaceConverter->rgbToRec2020Channels($rgb),
            default        => throw new UnsupportedColorSpaceException($space, $this->runtime->context->errorCtx('invert')),
        };

        return $channels;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $channels
     */
    public function workingSpaceChannelsToRgb(string $space, array $channels, float $alpha): RgbColor
    {
        try {
            $rgba = $this->runtime->spaceRouter->convertToRgba(
                $space,
                $channels[0],
                $channels[1],
                $channels[2],
                1.0,
            );
        } catch (UnsupportedColorSpace) {
            throw new UnsupportedColorSpaceException($space, $this->runtime->context->errorCtx('invert'));
        }

        return new RgbColor(
            r: $rgba->rValue() * 255.0,
            g: $rgba->gValue() * 255.0,
            b: $rgba->bValue() * 255.0,
            a: $alpha,
        );
    }

    /**
     * @return array{l: float, c: float, h: float, a: float, l_missing: bool, c_missing: bool, h_missing: bool}
     */
    public function extractOklchMixData(AstNode $color): array
    {
        return $this->converter->extractOklchMixData($color, 'mix');
    }

    /**
     * @return array{l: float, c: float, h: float, a: float, l_missing: bool, c_missing: bool, h_missing: bool}
     */
    public function extractLchMissingData(AstNode $color): array
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'lch') {
            $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);

            [$channels, $alpha] = $this->runtime->arguments->splitChannelsAndAlpha($expandedArgs);

            $lightnessMissing = $this->runtime->argumentParser->isMissingChannelNode($channels[0] ?? new StringNode('none'));
            $chromaMissing    = $this->runtime->argumentParser->isMissingChannelNode($channels[1] ?? new StringNode('none'));
            $hueMissing       = $this->runtime->argumentParser->isMissingChannelNode($channels[2] ?? new StringNode('none'));

            return [
                'l'         => $lightnessMissing ? 0.0 : $this->runtime->argumentParser->asPercentage($channels[0] ?? null, 'to-space'),
                'c'         => $chromaMissing ? 0.0 : $this->runtime->argumentParser->asAbsoluteChannel($channels[1] ?? null, 'to-space', 150.0),
                'h'         => $hueMissing ? 0.0 : $this->runtime->argumentParser->normalizeHue($this->runtime->argumentParser->asHueAngle($channels[2] ?? null, 'to-space')),
                'a'         => $this->converter->parseAlphaPublic($alpha, 'to-space'),
                'l_missing' => $lightnessMissing,
                'c_missing' => $chromaMissing,
                'h_missing' => $hueMissing,
            ];
        }

        $rgb = $this->converter->toRgb($color);
        $lch = $this->runtime->spaceConverter->rgbToLch($rgb);

        return [
            'l'         => $lch->lValue(),
            'c'         => $lch->cValue(),
            'h'         => $lch->hValue(),
            'a'         => $lch->alpha,
            'l_missing' => false,
            'c_missing' => false,
            'h_missing' => false,
        ];
    }

    public function toHslWithMissingChannels(AstNode $color): ?HslColor
    {
        if (! ($color instanceof FunctionNode)) {
            $hsl = $this->converter->toHsl($color);

            return new HslColor(
                h: $hsl->h,
                s: $hsl->s,
                l: $hsl->l,
                a: $hsl->a,
            );
        }

        if (strtolower($color->name) !== 'hsl' && strtolower($color->name) !== 'hsla') {
            return null;
        }

        /** @var array<int, AstNode> $expandedArgs */
        $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);

        [$channels, $alpha] = $this->runtime->arguments->splitChannelsAndAlpha($expandedArgs);

        if (count($channels) !== 3) {
            return null;
        }

        $hNone = $this->runtime->argumentParser->isMissingChannelNode($channels[0]);
        $sNone = $this->runtime->argumentParser->isMissingChannelNode($channels[1]);
        $lNone = $this->runtime->argumentParser->isMissingChannelNode($channels[2]);

        $hue = $hNone ? null : $this->runtime->argumentParser->normalizeHue(
            $this->runtime->argumentParser->asNumber($channels[0], 'mix'),
        );

        $sat = $sNone ? null : $this->runtime->argumentParser->clamp(
            $this->runtime->argumentParser->asPercentage($channels[1], 'mix'),
            100.0,
        );

        $lig = $lNone ? null : $this->runtime->argumentParser->clamp(
            $this->runtime->argumentParser->asPercentage($channels[2], 'mix'),
            100.0,
        );

        $alp = 1.0;

        if ($alpha !== null) {
            $alp = $this->runtime->argumentParser->isMissingChannelNode($alpha)
                ? 0.0
                : $this->runtime->argumentParser->clamp(
                    $this->runtime->argumentParser->asNumber($alpha, 'mix'),
                    1.0,
                );
        }

        return new HslColor($hue, $sat, $lig, $alp);
    }

    public function isSemanticChannelMissing(AstNode $color): bool
    {
        if (! ($color instanceof FunctionNode)) {
            return false;
        }

        $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);
        $channelIndex = $this->runtime->channelSchema->lightnessIndexForFunction(strtolower($color->name));

        if ($channelIndex === null || ! isset($expandedArgs[$channelIndex])) {
            return false;
        }

        return $this->runtime->argumentParser->isMissingChannelNode($expandedArgs[$channelIndex]);
    }

    public function missingStringNode(): StringNode
    {
        return new StringNode('none');
    }

    private function toXyzD65GenericSpace(AstNode $color, string $space): AstNode
    {
        $alpha = $this->converter->toAlpha($color);

        $isGenericFunction = $color instanceof FunctionNode && strtolower($color->name) === 'color';

        if ($isGenericFunction) {
            $inputSpace = $this->converter->detectNativeColorSpace($color);

            if ($inputSpace !== $space) {
                if ($this->isRgbFamilySpace($inputSpace) && $this->isRgbFamilySpace($space)) {
                    return $this->convertGenericRgbFamilySpace($color, $inputSpace, $space);
                }

                $channels = $this->converter->extractChannelNodes($color);
                $noneChannels  = [];
                $numericValues = [];

                for ($i = 0; $i < 3; $i++) {
                    $node = $channels[$i + 1] ?? new StringNode('none');
                    if ($this->runtime->argumentParser->isMissingChannelNode($node)) {
                        $noneChannels[$i]  = true;
                        $numericValues[$i] = 0.0;
                    } else {
                        $noneChannels[$i]  = false;
                        $numericValues[$i] = $this->runtime->argumentParser->asNumber($node, 'to-space');
                    }
                }

                $forwardXyz = $this->forwardToXyzD65Unclamped($inputSpace, $numericValues);

                return $this->buildOutputFromXyzD65($space, $forwardXyz, $alpha, $noneChannels);
            }
        }

        if ($color instanceof FunctionNode) {
            $legacyChannels = $this->extractLegacySrgbChannelsUnclamped($color);

            if ($legacyChannels !== null) {
                if ($this->isRgbFamilySpace($space)) {
                    return $this->convertLegacyRgbFamilySpace($color, $space);
                }

                [$r, $g, $b] = $legacyChannels;

                $forwardXyz = $this->runtime->spaceConverter->srgbToXyzD65(
                    $r / 255.0,
                    $g / 255.0,
                    $b / 255.0,
                );

                $noneChannels = null;

                $name = strtolower($color->name);

                if ($name === 'rgb' || $name === 'rgba') {
                    /** @var array<int, AstNode> $expandedArgs */
                    $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);

                    $noneChannels = [
                        $this->runtime->argumentParser->isMissingChannelNode($expandedArgs[0] ?? new StringNode('none')),
                        $this->runtime->argumentParser->isMissingChannelNode($expandedArgs[1] ?? new StringNode('none')),
                        $this->runtime->argumentParser->isMissingChannelNode($expandedArgs[2] ?? new StringNode('none')),
                    ];
                }

                return $this->buildOutputFromXyzD65($space, $forwardXyz, $alpha, $noneChannels);
            }
        }

        if ($this->isRgbFamilySpace($space)) {
            [$r, $g, $b] = $this->extractUnclampedSrgbChannels($color);

            $resultChannels = $this->convertRgbFamilyChannels(
                'srgb',
                [$r / 255.0, $g / 255.0, $b / 255.0],
                $space,
            );

            return $this->buildRgbFamilyOutput($space, $resultChannels, $alpha, [false, false, false]);
        }

        $xyzD65 = $this->converter->toXyzD65($color);

        return $this->buildOutputFromXyzD65($space, $xyzD65, $alpha);
    }

    /**
     * @param array<int, float> $channels
     */
    private function forwardToXyzD65Unclamped(string $inputSpace, array $channels): XyzColor
    {
        $sc = $this->runtime->spaceConverter;

        return match ($inputSpace) {
            'a98-rgb'           => $sc->a98ToXyzD65($channels[0], $channels[1], $channels[2]),
            'rec2020'           => $sc->rec2020ToXyzD65($channels[0], $channels[1], $channels[2]),
            'display-p3'        => $sc->p3ToXyzD65($channels[0], $channels[1], $channels[2]),
            'display-p3-linear' => $sc->linP3ToXyzD65($channels[0], $channels[1], $channels[2]),
            'srgb-linear'       => $sc->linSrgbToXyzD65($channels[0], $channels[1], $channels[2]),
            'prophoto-rgb'      => $this->runtime->spaceConverter->prophotoToXyzD65($channels[0], $channels[1], $channels[2]),
            'xyz'               => new XyzColor(x: $channels[0], y: $channels[1], z: $channels[2]),
            'xyz-d50'           => $sc->xyzD50ToXyzD65(new XyzColor(x: $channels[0], y: $channels[1], z: $channels[2])),
            default             => $sc->srgbToXyzD65($channels[0], $channels[1], $channels[2]),
        };
    }

    private function convertGenericRgbFamilySpace(FunctionNode $color, string $inputSpace, string $targetSpace): AstNode
    {
        $channels = $this->converter->extractChannelNodes($color);
        $alpha    = $this->converter->toAlpha($color);

        $noneChannels  = [];
        $numericValues = [0.0, 0.0, 0.0];

        for ($i = 0; $i < 3; $i++) {
            $node = $channels[$i + 1] ?? new StringNode('none');
            if ($this->runtime->argumentParser->isMissingChannelNode($node)) {
                $noneChannels[$i]  = true;
                $numericValues[$i] = 0.0;
            } else {
                $noneChannels[$i]  = false;
                $numericValues[$i] = $this->runtime->argumentParser->asNumber($node, 'to-space');
            }
        }

        $resultChannels = $this->convertRgbFamilyChannels($inputSpace, $numericValues, $targetSpace);

        return $this->buildRgbFamilyOutput($targetSpace, $resultChannels, $alpha, $noneChannels);
    }

    private function convertLegacyRgbFamilySpace(FunctionNode $color, string $targetSpace): AstNode
    {
        $alpha = $this->converter->toAlpha($color);

        $legacyChannels = $this->extractLegacySrgbChannelsUnclamped($color);

        if ($legacyChannels === null) {
            throw new LogicException('Legacy srgb channels are required for rgb-family conversion.');
        }

        [$r, $g, $b] = $legacyChannels;

        $resultChannels = $this->convertRgbFamilyChannels(
            'srgb',
            [$r / 255.0, $g / 255.0, $b / 255.0],
            $targetSpace,
        );

        $noneChannels = [false, false, false];

        if (in_array(strtolower($color->name), ['rgb', 'rgba'], true)) {
            /** @var array<int, AstNode> $expandedArgs */
            $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);

            $noneChannels = [
                $this->runtime->argumentParser->isMissingChannelNode($expandedArgs[0] ?? new StringNode('none')),
                $this->runtime->argumentParser->isMissingChannelNode($expandedArgs[1] ?? new StringNode('none')),
                $this->runtime->argumentParser->isMissingChannelNode($expandedArgs[2] ?? new StringNode('none')),
            ];
        }

        return $this->buildRgbFamilyOutput($targetSpace, $resultChannels, $alpha, $noneChannels);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $resultChannels
     * @param array<int, bool> $noneChannels
     */
    private function buildRgbFamilyOutput(string $targetSpace, array $resultChannels, float $alpha, array $noneChannels): AstNode
    {
        $outputNodes = [new StringNode($targetSpace)];

        for ($i = 0; $i < 3; $i++) {
            $outputNodes[] = $noneChannels[$i]
                ? new StringNode('none')
                : new NumberNode($resultChannels[$i]);
        }

        return $this->converter->buildFunctionalColorNode('color', $outputNodes, $alpha);
    }


    private function convertGenericToXyzD65(FunctionNode $color, string $space): AstNode
    {
        $channels   = $this->converter->extractChannelNodes($color);
        $alpha      = $this->converter->toAlpha($color);
        $inputSpace = $this->converter->detectNativeColorSpace($color);

        $noneChannels  = [];
        $numericValues = [];

        for ($i = 0; $i < 3; $i++) {
            $node = $channels[$i + 1] ?? new StringNode('none');
            if ($this->runtime->argumentParser->isMissingChannelNode($node)) {
                $noneChannels[$i]  = true;
                $numericValues[$i] = 0.0;
            } else {
                $noneChannels[$i]  = false;
                $numericValues[$i] = $this->runtime->argumentParser->asNumber($node, 'to-space');
            }
        }

        $xyz = $this->forwardToXyzD65Unclamped($inputSpace, $numericValues);

        $outputNodes = [new StringNode($space)];

        for ($i = 0; $i < 3; $i++) {
            $outputNodes[] = $noneChannels[$i]
                ? new StringNode('none')
                : new NumberNode((float) match ($i) {
                    0 => $xyz->x,
                    1 => $xyz->y,
                    2 => $xyz->z,
                });
        }

        return $this->converter->buildFunctionalColorNode('color', $outputNodes, $alpha);
    }

    private function convertGenericToXyzD50(FunctionNode $color): AstNode
    {
        $channels   = $this->converter->extractChannelNodes($color);
        $alpha      = $this->converter->toAlpha($color);
        $inputSpace = $this->converter->detectNativeColorSpace($color);

        $noneChannels  = [];
        $numericValues = [];

        for ($i = 0; $i < 3; $i++) {
            $node = $channels[$i + 1] ?? new StringNode('none');
            if ($this->runtime->argumentParser->isMissingChannelNode($node)) {
                $noneChannels[$i]  = true;
                $numericValues[$i] = 0.0;
            } else {
                $noneChannels[$i]  = false;
                $numericValues[$i] = $this->runtime->argumentParser->asNumber($node, 'to-space');
            }
        }

        if ($inputSpace === 'prophoto-rgb') {
            $linR = $this->runtime->spaceConverter->linProphoto($numericValues[0]);
            $linG = $this->runtime->spaceConverter->linProphoto($numericValues[1]);
            $linB = $this->runtime->spaceConverter->linProphoto($numericValues[2]);

            $xyzD50 = $this->linearProphotoToXyzD50($linR, $linG, $linB);
        } else {
            $xyzD65 = $this->forwardToXyzD65Unclamped($inputSpace, $numericValues);
            $xyzD50 = $this->runtime->spaceConverter->xyzD65ToXyzD50($xyzD65);
        }

        $outputNodes = [new StringNode('xyz-d50')];

        for ($i = 0; $i < 3; $i++) {
            $outputNodes[] = $noneChannels[$i]
                ? new StringNode('none')
                : new NumberNode((float) match ($i) {
                    0 => $xyzD50->x,
                    1 => $xyzD50->y,
                    2 => $xyzD50->z,
                });
        }

        return $this->converter->buildFunctionalColorNode('color', $outputNodes, $alpha);
    }

    /** @param array<int, bool> $noneChannels */
    private function buildOutputFromXyzD65(string $space, XyzColor $xyz, float $alpha, ?array $noneChannels = null): AstNode
    {
        if ($space === 'display-p3-linear') {
            $linearValues = $this->runtime->spaceConverter->xyzD65ToLinearDisplayP3($xyz);

            if ($noneChannels !== null) {
                $outputNodes = [new StringNode($space)];

                for ($i = 0; $i < 3; $i++) {
                    $outputNodes[] = $noneChannels[$i]
                        ? new StringNode('none')
                        : new NumberNode($linearValues[$i]);
                }

                return $this->converter->buildFunctionalColorNode('color', $outputNodes, $alpha);
            }

            return $this->converter->buildFunctionalColorNode('color', [
                new StringNode($space),
                new NumberNode($linearValues[0]),
                new NumberNode($linearValues[1]),
                new NumberNode($linearValues[2]),
            ], $alpha);
        }

        if ($space === 'srgb-linear') {
            $linearValues = $this->runtime->spaceConverter->xyzD65ToLinSrgb($xyz);

            if ($noneChannels !== null) {
                $outputNodes = [new StringNode($space)];

                for ($i = 0; $i < 3; $i++) {
                    $outputNodes[] = $noneChannels[$i]
                        ? new StringNode('none')
                        : new NumberNode($linearValues[$i]);
                }

                return $this->converter->buildFunctionalColorNode('color', $outputNodes, $alpha);
            }

            return $this->converter->buildFunctionalColorNode('color', [
                new StringNode($space),
                new NumberNode($linearValues[0]),
                new NumberNode($linearValues[1]),
                new NumberNode($linearValues[2]),
            ], $alpha);
        }

        $sc = $this->runtime->spaceConverter;

        $resultChannels = match ($space) {
            'display-p3' => $sc->xyzD65ToP3Channels($xyz),
            'a98-rgb'    => $sc->xyzD65ToA98Channels($xyz),
            'rec2020'    => $sc->xyzD65ToRec2020Channels($xyz),
            default      => $sc->xyzD65ToSrgbChannels($xyz),
        };

        if ($noneChannels !== null) {
            $outputNodes = [new StringNode($space)];

            for ($i = 0; $i < 3; $i++) {
                $outputNodes[] = $noneChannels[$i]
                    ? new StringNode('none')
                    : new NumberNode($resultChannels[$i]);
            }

            return $this->converter->buildFunctionalColorNode('color', $outputNodes, $alpha);
        }

        return $this->converter->buildFunctionalColorNode('color', [
            new StringNode($space),
            new NumberNode($resultChannels[0]),
            new NumberNode($resultChannels[1]),
            new NumberNode($resultChannels[2]),
        ], $alpha);
    }

    private function toXyzD50GenericSpace(AstNode $color, string $space): AstNode
    {
        $alpha = $this->converter->toAlpha($color);

        $isGenericFunction = $color instanceof FunctionNode && strtolower($color->name) === 'color';

        if ($isGenericFunction) {
            $inputSpace = $this->converter->detectNativeColorSpace($color);
            $channels   = $this->converter->extractChannelNodes($color);

            $noneChannels  = [];
            $numericValues = [];

            for ($i = 0; $i < 3; $i++) {
                $node = $channels[$i + 1] ?? new StringNode('none');
                if ($this->runtime->argumentParser->isMissingChannelNode($node)) {
                    $noneChannels[$i]  = true;
                    $numericValues[$i] = 0.0;
                } else {
                    $noneChannels[$i]  = false;
                    $numericValues[$i] = $this->runtime->argumentParser->asNumber($node, 'to-space');
                }
            }

            if ($inputSpace === 'prophoto-rgb') {
                $linR = $this->runtime->spaceConverter->linProphoto($numericValues[0]);
                $linG = $this->runtime->spaceConverter->linProphoto($numericValues[1]);
                $linB = $this->runtime->spaceConverter->linProphoto($numericValues[2]);

                $xyzD50 = $this->linearProphotoToXyzD50($linR, $linG, $linB);
            } else {
                $xyzD65 = $this->forwardToXyzD65Unclamped($inputSpace, $numericValues);
                $xyzD50 = $this->runtime->spaceConverter->xyzD65ToXyzD50($xyzD65);
            }

            $prophotoChannels = $this->xyzD50ToProphotoRgbUnclamped($xyzD50);

            $outputNodes = [new StringNode($space)];

            for ($i = 0; $i < 3; $i++) {
                $outputNodes[] = $noneChannels[$i]
                    ? new StringNode('none')
                    : new NumberNode($prophotoChannels[$i]);
            }

            return $this->converter->buildFunctionalColorNode('color', $outputNodes, $alpha);
        }

        if ($color instanceof FunctionNode) {
            $legacyChannels = $this->extractLegacySrgbChannelsUnclamped($color);

            if ($legacyChannels !== null) {
                [$r, $g, $b] = $legacyChannels;

                $xyzD65 = $this->runtime->spaceConverter->srgbToXyzD65(
                    $r / 255.0,
                    $g / 255.0,
                    $b / 255.0,
                );

                $xyzD50 = $this->runtime->spaceConverter->xyzD65ToXyzD50($xyzD65);

                $prophotoChannels = $this->xyzD50ToProphotoRgbUnclamped($xyzD50);

                $noneChannels = null;

                $name = strtolower($color->name);

                if ($name === 'rgb' || $name === 'rgba') {
                    /** @var array<int, AstNode> $expandedArgs */
                    $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);

                    $noneChannels = [
                        $this->runtime->argumentParser->isMissingChannelNode($expandedArgs[0] ?? new StringNode('none')),
                        $this->runtime->argumentParser->isMissingChannelNode($expandedArgs[1] ?? new StringNode('none')),
                        $this->runtime->argumentParser->isMissingChannelNode($expandedArgs[2] ?? new StringNode('none')),
                    ];
                }

                $outputNodes = [new StringNode($space)];

                for ($i = 0; $i < 3; $i++) {
                    $outputNodes[] = $noneChannels !== null && $noneChannels[$i]
                        ? new StringNode('none')
                        : new NumberNode($prophotoChannels[$i]);
                }

                return $this->converter->buildFunctionalColorNode('color', $outputNodes, $alpha);
            }
        }

        $xyzD50 = $this->converter->toXyzD50($color);

        $prophotoChannels = $this->xyzD50ToProphotoRgbUnclamped($xyzD50);

        return $this->converter->buildFunctionalColorNode('color', [
            new StringNode($space),
            new NumberNode($prophotoChannels[0]),
            new NumberNode($prophotoChannels[1]),
            new NumberNode($prophotoChannels[2]),
        ], $alpha);
    }

    private function linearProphotoToXyzD50(float $linR, float $linG, float $linB): XyzColor
    {
        $m = self::PROPHOTO_TO_XYZ_D50_MATRIX;

        return new XyzColor(
            x: $m[0] * $linR + $m[1] * $linG + $m[2] * $linB,
            y: $m[3] * $linR + $m[4] * $linG + $m[5] * $linB,
            z: $m[8] * $linB,
        );
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function xyzD50ToProphotoRgbUnclamped(XyzColor $xyz): array
    {
        $sc = $this->runtime->spaceConverter;

        return [
            $sc->gamProphoto(
                1.3457989731028281 * (float) $xyz->x - 0.2555801000799753 * (float) $xyz->y - 0.0511062850675340 * (float) $xyz->z,
            ),
            $sc->gamProphoto(
                -0.5446224939028347 * (float) $xyz->x + 1.5082327413132781 * (float) $xyz->y + 0.0205360323914797 * (float) $xyz->z,
            ),
            $sc->gamProphoto(
                1.2119675456389454 * (float) $xyz->z,
            ),
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $channels
     * @return array{0: float, 1: float, 2: float}
     */
    private function convertRgbFamilyChannels(string $inputSpace, array $channels, string $targetSpace): array
    {
        $linearInput = [
            $this->toLinearRgbFamilyChannel($inputSpace, $channels[0]),
            $this->toLinearRgbFamilyChannel($inputSpace, $channels[1]),
            $this->toLinearRgbFamilyChannel($inputSpace, $channels[2]),
        ];

        $m = self::RGB_FAMILY_LINEAR_MATRICES[$this->rgbFamilyMatrixKey($inputSpace, $targetSpace)];

        return [
            $this->fromLinearRgbFamilyChannel($targetSpace, $m[0] * $linearInput[0] + $m[1] * $linearInput[1] + $m[2] * $linearInput[2]),
            $this->fromLinearRgbFamilyChannel($targetSpace, $m[3] * $linearInput[0] + $m[4] * $linearInput[1] + $m[5] * $linearInput[2]),
            $this->fromLinearRgbFamilyChannel($targetSpace, $m[6] * $linearInput[0] + $m[7] * $linearInput[1] + $m[8] * $linearInput[2]),
        ];
    }

    private function isRgbFamilySpace(string $space): bool
    {
        return in_array($space, self::RGB_FAMILY_SPACES, true);
    }

    private function rgbFamilyMatrixKey(string $source, string $target): string
    {
        return $this->rgbFamilyBaseSpace($source) . '|' . $this->rgbFamilyBaseSpace($target);
    }

    private function rgbFamilyBaseSpace(string $space): string
    {
        return $space === 'srgb-linear' ? 'srgb' : ($space === 'display-p3-linear' ? 'display-p3' : $space);
    }

    private function toLinearRgbFamilyChannel(string $space, float $channel): float
    {
        $sc = $this->runtime->spaceConverter;

        return match ($space) {
            'srgb', 'display-p3' => $sc->linSrgb($channel),
            'a98-rgb'            => $sc->linA98($channel),
            'rec2020'            => $sc->linRec2020($channel),
            default              => $channel,
        };
    }

    private function fromLinearRgbFamilyChannel(string $space, float $channel): float
    {
        $sc = $this->runtime->spaceConverter;

        return match ($space) {
            'srgb', 'display-p3' => $sc->gamSrgb($channel),
            'a98-rgb'            => $sc->gamA98($channel),
            'rec2020'            => $sc->gamRec2020($channel),
            default              => $channel,
        };
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function extractUnclampedHslChannels(FunctionNode $color): array
    {
        $ap = $this->runtime->argumentParser;

        /** @var array<int, AstNode> $expandedArgs */
        $expandedArgs = $ap->expandSingleSpaceListArgument($color->arguments);

        $hue = 0.0;
        $sat = 0.0;
        $lig = 0.0;

        if (isset($expandedArgs[0]) && ! $ap->isMissingChannelNode($expandedArgs[0])) {
            $node = $expandedArgs[0];
            if ($node instanceof NumberNode) {
                $hue = $this->runtime->spaceConverter->normalizeHue($ap->asNumber($node, 'to-space'));
            }
        }

        if (isset($expandedArgs[1]) && ! $ap->isMissingChannelNode($expandedArgs[1])) {
            $node = $expandedArgs[1];
            if ($node instanceof NumberNode) {
                $val = (float) $node->value;
                $sat = strtolower($node->unit ?? '') === '%' ? $val / 100.0 : $val / 100.0;
            }
        }

        if (isset($expandedArgs[2]) && ! $ap->isMissingChannelNode($expandedArgs[2])) {
            $node = $expandedArgs[2];
            if ($node instanceof NumberNode) {
                $val = (float) $node->value;
                $lig = strtolower($node->unit ?? '') === '%' ? $val / 100.0 : $val / 100.0;
            }
        }

        return [$hue, $sat, $lig];
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function extractUnclampedHwbChannels(FunctionNode $color): array
    {
        $ap = $this->runtime->argumentParser;

        /** @var array<int, AstNode> $expandedArgs */
        $expandedArgs = $ap->expandSingleSpaceListArgument($color->arguments);

        $hue   = 0.0;
        $white = 0.0;
        $black = 0.0;

        if (isset($expandedArgs[0]) && ! $ap->isMissingChannelNode($expandedArgs[0])) {
            $node = $expandedArgs[0];
            if ($node instanceof NumberNode) {
                $hue = $this->runtime->spaceConverter->normalizeHue($ap->asNumber($node, 'to-space'));
            }
        }

        if (isset($expandedArgs[1]) && ! $ap->isMissingChannelNode($expandedArgs[1])) {
            $node = $expandedArgs[1];
            if ($node instanceof NumberNode) {
                $val   = (float) $node->value;
                $white = strtolower($node->unit ?? '') === '%' ? $val / 100.0 : $val / 100.0;
            }
        }

        if (isset($expandedArgs[2]) && ! $ap->isMissingChannelNode($expandedArgs[2])) {
            $node = $expandedArgs[2];
            if ($node instanceof NumberNode) {
                $val   = (float) $node->value;
                $black = strtolower($node->unit ?? '') === '%' ? $val / 100.0 : $val / 100.0;
            }
        }

        return [$hue, $white, $black];
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null  [r, g, b in 0-255 unclamped, alpha]
     */
    public function extractLegacySrgbChannelsUnclamped(FunctionNode $color): ?array
    {
        $name = strtolower($color->name);

        if ($name === 'hsl' || $name === 'hsla') {
            [$hue, $sat, $lig] = $this->extractUnclampedHslChannels($color);
            [$r, $g, $b]       = $this->runtime->spaceConverter->hslToRgb($hue, $sat, $lig);

            $r *= 255.0;
            $g *= 255.0;
            $b *= 255.0;

            $alpha = $this->converter->toAlpha($color);

            return [$r, $g, $b, $alpha];
        }

        if ($name === 'hwb') {
            [$hue, $white, $black] = $this->extractUnclampedHwbChannels($color);
            [$r, $g, $b]           = $this->runtime->spaceConverter->hwbToRgb($hue, $white, $black);

            $r *= 255.0;
            $g *= 255.0;
            $b *= 255.0;

            $alpha = $this->converter->toAlpha($color);

            return [$r, $g, $b, $alpha];
        }

        if ($name === 'rgb' || $name === 'rgba') {
            return $this->extractUnclampedRgbChannels($color);
        }

        return null;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} [r, g, b in 0-255 unclamped, alpha]
     */
    private function extractUnclampedRgbChannels(FunctionNode $color): array
    {
        $ap = $this->runtime->argumentParser;

        /** @var array<int, AstNode> $expandedArgs */
        $expandedArgs = $ap->expandSingleSpaceListArgument($color->arguments);

        $r = 0.0;
        $g = 0.0;
        $b = 0.0;

        if (isset($expandedArgs[0]) && ! $ap->isMissingChannelNode($expandedArgs[0])) {
            $node = $expandedArgs[0];
            if ($node instanceof NumberNode) {
                $val = (float) $node->value;
                $r   = strtolower($node->unit ?? '') === '%' ? $val * 255.0 / 100.0 : $val;
            }
        }

        if (isset($expandedArgs[1]) && ! $ap->isMissingChannelNode($expandedArgs[1])) {
            $node = $expandedArgs[1];
            if ($node instanceof NumberNode) {
                $val = (float) $node->value;
                $g   = strtolower($node->unit ?? '') === '%' ? $val * 255.0 / 100.0 : $val;
            }
        }

        if (isset($expandedArgs[2]) && ! $ap->isMissingChannelNode($expandedArgs[2])) {
            $node = $expandedArgs[2];
            if ($node instanceof NumberNode) {
                $val = (float) $node->value;
                $b   = strtolower($node->unit ?? '') === '%' ? $val * 255.0 / 100.0 : $val;
            }
        }

        $alpha = $this->converter->toAlpha($color);

        return [$r, $g, $b, $alpha];
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function extractNativeLabLchOklabOklchUnclamped(FunctionNode $color): ?array
    {
        $name = strtolower($color->name);

        if (! in_array($name, ['lab', 'laba', 'lch', 'lcha', 'oklab', 'oklaba', 'oklch', 'oklcha'], true)) {
            return null;
        }

        $ap = $this->runtime->argumentParser;
        $sc = $this->runtime->spaceConverter;

        /** @var array<int, AstNode> $expandedArgs */
        $expandedArgs = $ap->expandSingleSpaceListArgument($color->arguments);

        $ch0 = $expandedArgs[0] ?? null;
        $ch1 = $expandedArgs[1] ?? null;
        $ch2 = $expandedArgs[2] ?? null;

        $isNone0 = $ch0 === null || $ap->isMissingChannelNode($ch0);
        $isNone1 = $ch1 === null || $ap->isMissingChannelNode($ch1);
        $isNone2 = $ch2 === null || $ap->isMissingChannelNode($ch2);

        if (in_array($name, ['lab', 'laba'], true)) {
            $l = $isNone0 ? 0.0 : $ap->asPercentage($ch0, 'to-space');
            $a = $isNone1 ? 0.0 : $ap->asNumber($ch1, 'to-space');
            $b = $isNone2 ? 0.0 : $ap->asNumber($ch2, 'to-space');

            $lin = $sc->xyzD65ToSrgbChannels($sc->labToXyzD65($l, $a, $b));

            return [$lin[0] * 255.0, $lin[1] * 255.0, $lin[2] * 255.0];
        }

        if (in_array($name, ['lch', 'lcha'], true)) {
            $l = $isNone0 ? 0.0 : $ap->asPercentage($ch0, 'to-space');
            $c = $isNone1 ? 0.0 : $ap->asNumber($ch1, 'to-space');
            $h = $isNone2 ? 0.0 : $ap->asNumber($ch2, 'to-space');

            $lin = $sc->xyzD65ToSrgbChannels($sc->lchToXyzD65($l, $c, $h));

            return [$lin[0] * 255.0, $lin[1] * 255.0, $lin[2] * 255.0];
        }

        if (in_array($name, ['oklab', 'oklaba'], true)) {
            $l = $isNone0 ? 0.0 : $ap->asPercentage($ch0, 'to-space') / 100.0;
            $a = $isNone1 ? 0.0 : $ap->asNumber($ch1, 'to-space');
            $b = $isNone2 ? 0.0 : $ap->asNumber($ch2, 'to-space');

            $lin = $sc->xyzD65ToSrgbChannels($sc->oklabToXyzD65($l, $a, $b));

            return [$lin[0] * 255.0, $lin[1] * 255.0, $lin[2] * 255.0];
        }

        $l = $isNone0 ? 0.0 : $ap->asPercentage($ch0, 'to-space') / 100.0;
        $c = $isNone1 ? 0.0 : $ap->asNumber($ch1, 'to-space');
        $h = $isNone2 ? 0.0 : $ap->asNumber($ch2, 'to-space');

        $lin = $sc->xyzD65ToSrgbChannels($sc->oklchToXyzD65($l, $c, $h));

        return [$lin[0] * 255.0, $lin[1] * 255.0, $lin[2] * 255.0];
    }

    private function buildColorMixNode(string $space, XyzColor $xyz, float $alpha): FunctionNode
    {
        $xyzNode = $this->converter->buildGenericColorFunctionNode('xyz', [
            (float) $xyz->x,
            (float) $xyz->y,
            (float) $xyz->z,
        ], $alpha);

        return new FunctionNode('color-mix', [
            new ListNode([
                new StringNode('in'),
                new StringNode($space),
            ], 'space'),
            new ListNode([
                $xyzNode,
                new NumberNode(100, '%'),
            ], 'space'),
            new StringNode('black'),
        ]);
    }

    private function extractXyzD65ForColorMix(AstNode $color): XyzColor
    {
        if ($color instanceof FunctionNode) {
            $unclamped = $this->computeUnclampedXyzD65($color);

            if ($unclamped !== null) {
                return $unclamped;
            }

            $xyz = $this->converter->toXyzD65WithAlpha($color);

            if ($xyz !== null) {
                return $xyz[0];
            }
        }

        return $this->runtime->spaceConverter->rgbToXyzD65($this->converter->toRgb($color));
    }

    private function computeUnclampedXyzD65(FunctionNode $color): ?XyzColor
    {
        $name = strtolower($color->name);

        $legacyChannels = $this->extractLegacySrgbChannelsUnclamped($color);

        if ($legacyChannels !== null) {
            return $this->runtime->spaceConverter->srgbToXyzD65(
                $legacyChannels[0] / 255.0,
                $legacyChannels[1] / 255.0,
                $legacyChannels[2] / 255.0,
            );
        }

        $xyz = $this->converter->toXyzD65WithAlpha($color);

        if ($xyz !== null) {
            return $xyz[0];
        }

        return null;
    }

    private function isLightnessOutOfRange(AstNode $color, string $nativeSpace): bool
    {
        $ap = $this->runtime->argumentParser;

        if (! ($color instanceof FunctionNode)) {
            return false;
        }

        $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);
        [$channels]   = $this->runtime->arguments->splitChannelsAndAlpha($expandedArgs);

        $lightnessNode = $channels[0] ?? null;

        if ($lightnessNode === null || $ap->isMissingChannelNode($lightnessNode)) {
            return false;
        }

        $lightness = match (true) {
            in_array($nativeSpace, ['oklch', 'oklcha', 'oklab', 'oklaba'], true)
                => $ap->asPercentage($lightnessNode, 'to-space'),
            in_array($nativeSpace, ['lch', 'lcha', 'lab', 'laba'], true)
                => $ap->asPercentage($lightnessNode, 'to-space'),
            default => 50.0,
        };

        return $lightness < -self::LIGHTNESS_BOUNDARY_EPSILON || $lightness > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON;
    }
}
