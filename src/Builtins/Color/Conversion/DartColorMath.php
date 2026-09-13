<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Conversion;

use LogicException;

use function abs;
use function atan2;
use function cos;
use function fmod;
use function in_array;
use function max;
use function min;
use function sin;
use function sqrt;

use const M_PI;

/**
 * @phpstan-import-type ChannelVector from ColorSpaceConverter
 *
 * @psalm-import-type ChannelVector from ColorSpaceConverter
 */
final readonly class DartColorMath
{
    private const D50 = [0.96429567642956771, 1.0, 0.82510460251046025];

    private const LAB_KAPPA = 903.2962962962963;

    private const LAB_EPSILON = 0.0088564516790356311;

    /**
     * Row-major transformation matrices (dart-sass conversions.dart)
     *
     * @var array<string, array<int, float>>
     */
    private const MATRICES = [
        'oklabToLms' => [
            01.00000000000000020, 00.39633777737617490, 00.21580375730991360,
            00.99999999999999980, -0.10556134581565854, -0.06385417282581334,
            00.99999999999999990, -0.08948417752981180, -1.29148554801940940,
        ],
        'lmsToOklab' => [
            00.21045426830931400, 00.79361777470230540, -0.00407204301161930,
            01.97799853243116840, -2.42859224204858000, 00.45059370961741100,
            00.02590404246554780, 00.78277171245752960, -0.80867575492307740,
        ],
        'linearSrgbToLinearDisplayP3' => [
            00.82246196871436230, 00.17753803128563775, 00.00000000000000000,
            00.03319419885096161, 00.96680580114903840, 00.00000000000000000,
            00.01708263072112003, 00.07239744066396346, 00.91051992861491650,
        ],
        'linearDisplayP3ToLinearSrgb' => [
            01.22494017628055980, -0.22494017628055996, 00.00000000000000000,
            -0.04205695470968816, 01.04205695470968800, 00.00000000000000000,
            -0.01963755459033443, -0.07863604555063188, 01.09827360014096630,
        ],
        'linearSrgbToLinearA98Rgb' => [
            00.71512560685562470, 00.28487439314437535, 00.00000000000000000,
            00.00000000000000000, 01.00000000000000000, 00.00000000000000000,
            00.00000000000000000, 00.04116194845011846, 00.95883805154988160,
        ],
        'linearA98RgbToLinearSrgb' => [
            01.39835574396077830, -0.39835574396077830, 00.00000000000000000,
            00.00000000000000000, 01.00000000000000000, 00.00000000000000000,
            00.00000000000000000, -0.04292898929447326, 01.04292898929447330,
        ],
        'linearSrgbToLinearRec2020' => [
            00.62740389593469900, 00.32928303837788370, 00.04331306568741722,
            00.06909728935823208, 00.91954039507545870, 00.01136231556630917,
            00.01639143887515027, 00.08801330787722575, 00.89559525324762400,
        ],
        'linearRec2020ToLinearSrgb' => [
            01.66049100210843450, -0.58764113878854950, -0.07284986331988487,
            -0.12455047452159074, 01.13289989712596030, -0.00834942260436947,
            -0.01815076335490530, -0.10057889800800737, 01.11872966136291270,
        ],
        'linearSrgbToXyzD65' => [
            00.41239079926595950, 00.35758433938387796, 00.18048078840183430,
            00.21263900587151036, 00.71516867876775590, 00.07219231536073371,
            00.01933081871559185, 00.11919477979462598, 00.95053215224966060,
        ],
        'xyzD65ToLinearSrgb' => [
            03.24096994190452130, -1.53738317757009350, -0.49861076029300330,
            -0.96924363628087980, 01.87596750150772060, 00.04155505740717561,
            00.05563007969699360, -0.20397695888897657, 01.05697151424287860,
        ],
        'linearSrgbToLms' => [
            00.41222146947076300, 00.53633253726173480, 00.05144599326750220,
            00.21190349581782520, 00.68069955064523420, 00.10739695353694050,
            00.08830245919005641, 00.28171883913612150, 00.62997870167382210,
        ],
        'lmsToLinearSrgb' => [
            04.07674163607595800, -3.30771153925806200, 00.23096990318210417,
            -1.26843797328503200, 02.60975734928768900, -0.34131937600265710,
            -0.00419607613867551, -0.70341861793593630, 01.70761469407461200,
        ],
        'linearSrgbToLinearProphotoRgb' => [
            00.52927697762261160, 00.33015450197849283, 00.14056852039889556,
            00.09836585954044917, 00.87347071290696180, 00.02816342755258900,
            00.01687534092138684, 00.11765941425612084, 00.86546524482249230,
        ],
        'linearProphotoRgbToLinearSrgb' => [
            02.03438084951699600, -0.72763578993413420, -0.30674505958286180,
            -0.22882573163305037, 01.23174254119010480, -0.00291680955705449,
            -0.00855882878391742, -0.15326670213803720, 01.16182553092195470,
        ],
        'linearSrgbToXyzD50' => [
            00.43606574687426936, 00.38515150959015960, 00.14307841996513868,
            00.22249317711056518, 00.71688701309448240, 00.06061980979495235,
            00.01392392146316939, 00.09708132423141015, 00.71409935681588070,
        ],
        'xyzD50ToLinearSrgb' => [
            03.13413585290011780, -1.61738599801804200, -0.49066221791109754,
            -0.97879547655577770, 01.91625437739598840, 00.03344287339036693,
            00.07195539255794733, -0.22897675981518200, 01.40538603511311820,
        ],
        'linearDisplayP3ToLinearA98Rgb' => [
            00.86400513747404840, 00.13599486252595164, 00.00000000000000000,
            -0.04205695470968816, 01.04205695470968800, 00.00000000000000000,
            -0.02056038078232985, -0.03250613804550798, 01.05306651882783790,
        ],
        'linearA98RgbToLinearDisplayP3' => [
            01.15009441814101840, -0.15009441814101834, 00.00000000000000000,
            00.04641729862941844, 00.95358270137058150, 00.00000000000000000,
            00.02388759479083904, 00.02650477632633013, 00.94960762888283080,
        ],
        'linearDisplayP3ToLinearRec2020' => [
            00.75383303436172180, 00.19859736905261630, 00.04756959658566187,
            00.04574384896535833, 00.94177721981169350, 00.01247893122294812,
            -0.00121034035451832, 00.01760171730108989, 00.98360862305342840,
        ],
        'linearRec2020ToLinearDisplayP3' => [
            01.34357825258433200, -0.28217967052613570, -0.06139858205819628,
            -0.06529745278911953, 01.07578791584857460, -0.01049046305945495,
            00.00282178726170095, -0.01959849452449406, 01.01677670726279310,
        ],
        'linearDisplayP3ToXyzD65' => [
            00.48657094864821626, 00.26566769316909294, 00.19821728523436250,
            00.22897456406974884, 00.69173852183650620, 00.07928691409374500,
            00.00000000000000000, 00.04511338185890257, 01.04394436890097570,
        ],
        'xyzD65ToLinearDisplayP3' => [
            02.49349691194142450, -0.93138361791912360, -0.40271078445071684,
            -0.82948896956157490, 01.76266406031834680, 00.02362468584194359,
            00.03584583024378433, -0.07617238926804170, 00.95688452400768730,
        ],
        'linearDisplayP3ToLms' => [
            00.48137985274995443, 00.46211837101131803, 00.05650177623872756,
            00.22883194181124472, 00.65321681938356760, 00.11795123880518774,
            00.08394575232299319, 00.22416527097756642, 00.69188897669944040,
        ],
        'lmsToLinearDisplayP3' => [
            03.12776897136187370, -2.25713576259163860, 00.12936679122976494,
            -1.09100901843779790, 02.41333171030692250, -0.32232269186912466,
            -0.02601080193857045, -0.50804133170416700, 01.53405213364273730,
        ],
        'linearDisplayP3ToLinearProphotoRgb' => [
            00.63168691934035890, 00.21393038569465722, 00.15438269496498390,
            00.08320371426648458, 00.88586513676302430, 00.03093114897049121,
            -0.00127273456473881, 00.05075510433665735, 00.95051763022808140,
        ],
        'linearProphotoRgbToLinearDisplayP3' => [
            01.63257560870691790, -0.37977161848259840, -0.25280399022431950,
            -0.15370040233755072, 01.16670254724250140, -0.01300214490495082,
            00.01039319529676572, -0.06280731264959440, 01.05241411735282870,
        ],
        'linearDisplayP3ToXyzD50' => [
            00.51514644296811600, 00.29200998206385770, 00.15713925139759397,
            00.24120032212525520, 00.69222254113138180, 00.06657713674336294,
            -0.00105013914714014, 00.04187827018907460, 00.78427647146852570,
        ],
        'xyzD50ToLinearDisplayP3' => [
            02.40393412185549730, -0.99003044249559310, -0.39761363181465614,
            -0.84227001614546880, 01.79895801610670820, 00.01604562477090472,
            00.04819381686413303, -0.09738519815446048, 01.27367136933212730,
        ],
        'linearA98RgbToLinearRec2020' => [
            00.87733384166365680, 00.07749370651571998, 00.04517245182062317,
            00.09662259146620378, 00.89152732024418050, 00.01185008828961569,
            00.02292106270284839, 00.04303668501067932, 00.93404225228647230,
        ],
        'linearRec2020ToLinearA98Rgb' => [
            01.15197839471591630, -0.09750305530240860, -0.05447533941350766,
            -0.12455047452159074, 01.13289989712596030, -0.00834942260436947,
            -0.02253038278105590, -0.04980650742838876, 01.07233689020944460,
        ],
        'linearA98RgbToXyzD65' => [
            00.57666904291013080, 00.18555823790654627, 00.18822864623499472,
            00.29734497525053616, 00.62736356625546600, 00.07529145849399789,
            00.02703136138641237, 00.07068885253582714, 00.99133753683763890,
        ],
        'xyzD65ToLinearA98Rgb' => [
            02.04158790381074600, -0.56500697427885960, -0.34473135077832950,
            -0.96924363628087980, 01.87596750150772060, 00.04155505740717561,
            00.01344428063203102, -0.11836239223101823, 01.01517499439120540,
        ],
        'linearA98RgbToLms' => [
            00.57643225961839410, 00.36991322261987963, 00.05365451776172635,
            00.29631647054222465, 00.59167613325218850, 00.11200739620558686,
            00.12347825101427760, 00.21949869837199862, 00.65702305061372380,
        ],
        'lmsToLinearA98Rgb' => [
            02.55403683861155660, -1.62197618068286990, 00.06793934207131327,
            -1.26843797328503200, 02.60975734928768900, -0.34131937600265710,
            -0.05623473593749381, -0.56704183956690610, 01.62327657550439990,
        ],
        'linearA98RgbToLinearProphotoRgb' => [
            00.74011750180477920, 00.11327951328898105, 00.14660298490623970,
            00.13755046469802620, 00.83307708026948400, 00.02937245503248977,
            00.02359772990871766, 00.07378347703906656, 00.90261879305221580,
        ],
        'linearProphotoRgbToLinearA98Rgb' => [
            01.38965124815152000, -0.16945907691487766, -0.22019217123664242,
            -0.22882573163305037, 01.23174254119010480, -0.00291680955705449,
            -0.01762544368426068, -0.09625702306122665, 01.11388246674548740,
        ],
        'linearA98RgbToXyzD50' => [
            00.60977504188618140, 00.20530000261929401, 00.14922063192409227,
            00.31112461220464155, 00.62565323083468560, 00.06322215696067286,
            00.01947059555648168, 00.06087908649415867, 00.74475492045981980,
        ],
        'xyzD50ToLinearA98Rgb' => [
            01.96246703637688060, -0.61074234048150730, -0.34135809808271540,
            -0.97879547655577770, 01.91625437739598840, 00.03344287339036693,
            00.02870443944957101, -0.14067486633170680, 01.34891418141379370,
        ],
        'linearRec2020ToXyzD65' => [
            00.63695804830129130, 00.14461690358620838, 00.16888097516417205,
            00.26270021201126703, 00.67799807151887100, 00.05930171646986194,
            00.00000000000000000, 00.02807269304908750, 01.06098505771079090,
        ],
        'xyzD65ToLinearRec2020' => [
            01.71665118797126760, -0.35567078377639240, -0.25336628137365980,
            -0.66668435183248900, 01.61648123663493900, 00.01576854581391113,
            00.01763985744531091, -0.04277061325780865, 00.94210312123547400,
        ],
        'linearRec2020ToLms' => [
            00.61675578486544440, 00.36019840122646335, 00.02304581390809228,
            00.26513305939263670, 00.63583937206784910, 00.09902756853951408,
            00.10010262952034828, 00.20390652261661452, 00.69599084786303720,
        ],
        'lmsToLinearRec2020' => [
            02.13990673043465130, -1.24638949376061800, 00.10648276332596668,
            -0.88473583575776740, 02.16323093836120070, -0.27849510260343340,
            -0.04857374640044396, -0.45450314971409640, 01.50307689611454040,
        ],
        'linearRec2020ToLinearProphotoRgb' => [
            00.83518733312972350, 00.04886884858605698, 00.11594381828421951,
            00.05403324519953363, 00.92891840856920440, 00.01704834623126199,
            -0.00234203897072539, 00.03633215316169465, 00.96600988580903070,
        ],
        'linearProphotoRgbToLinearRec2020' => [
            01.20065932951740800, -0.05756805370122346, -0.14309127581618444,
            -0.06994154955888504, 01.08061789759721400, -0.01067634803832895,
            00.00554147334294746, -0.04078219298657951, 01.03524071964363200,
        ],
        'linearRec2020ToXyzD50' => [
            00.67351546318827600, 00.16569726370390453, 00.12508294953738705,
            00.27905900514112060, 00.67531800574910980, 00.04562298910976962,
            -0.00193242713400438, 00.02997782679282923, 00.79705920285163550,
        ],
        'xyzD50ToLinearRec2020' => [
            01.64718490467176600, -0.39368189813164710, -0.23595963848828266,
            -0.68266410741738180, 01.64771461274440760, 00.01281708338512084,
            00.02966887665275675, -0.06292589642970030, 01.25355782018657710,
        ],
        'xyzD65ToLms' => [
            00.81902243799670300, 00.36190626005289034, -0.12887378152098788,
            00.03298365393238846, 00.92928686158634330, 00.03614466635064235,
            00.04817718935962420, 00.26423953175273080, 00.63354782846943080,
        ],
        'lmsToXyzD65' => [
            01.22687987584592430, -0.55781499446021710, 00.28139104566596460,
            -0.04057574521480084, 01.11228680328031730, -0.07171105806551635,
            -0.07637293667466007, -0.42149333240224324, 01.58692401983678180,
        ],
        'xyzD65ToLinearProphotoRgb' => [
            01.40319046337749790, -0.22301514479051668, -0.10160668507413790,
            -0.52623840216330720, 01.48163196292346440, 00.01701879027252688,
            -0.01120226528622150, 00.01824640347962099, 00.91124722749150480,
        ],
        'linearProphotoRgbToXyzD65' => [
            00.75559074229692100, 00.11271984265940525, 00.08214534209534540,
            00.26832184357857190, 00.71511525666179120, 00.01656289975963685,
            00.00391597276242580, -0.01293344283684181, 01.09807522083429450,
        ],
        'xyzD65ToXyzD50' => [
            01.04792979254499660, 00.02294687060160952, -0.05019226628920519,
            00.02962780877005567, 00.99043442675388000, -0.01707379906341879,
            -0.00924304064620452, 00.01505519149029816, 00.75187428142813700,
        ],
        'xyzD50ToXyzD65' => [
            00.95547342148807520, -0.02309845494876452, 00.06325924320057065,
            -0.02836970933386358, 01.00999539808130410, 00.02104144119191730,
            00.01231401486448199, -0.02050764929889898, 01.33036592624212400,
        ],
        'lmsToLinearProphotoRgb' => [
            01.73835514811572070, -0.98795094275144580, 00.24959579463572504,
            -0.70704940153292660, 01.93437004444013820, -0.22732064290721150,
            -0.08407882206239634, -0.35754060521141334, 01.44161942727380970,
        ],
        'linearProphotoRgbToLms' => [
            00.71544846056555340, 00.35279155007721186, -0.06824001064276530,
            00.27441164900156710, 00.66779764984123670, 00.05779070115719616,
            00.10978443261622942, 00.18619829115002018, 00.70401727623375040,
        ],
        'lmsToXyzD50' => [
            01.28858621817270600, -0.53787174449737450, 00.21358120275423640,
            -0.00253387643187372, 01.09231679887191650, -0.08978292244004273,
            -0.06937382305734124, -0.29500839894431263, 01.18948682451211420,
        ],
        'xyzD50ToLms' => [
            00.77070004204311720, 00.34924840261939616, -0.11202351884164681,
            00.00559649248368848, 00.93707234011367690, 00.06972568836252771,
            00.04633714262191069, 00.25277531574310524, 00.85145807674679600,
        ],
        'linearProphotoRgbToXyzD50' => [
            00.79776664490064230, 00.13518129740053308, 00.03134773412839220,
            00.28807482881940130, 00.71183523424187300, 00.00008993693872564,
            00.00000000000000000, 00.00000000000000000, 00.82510460251046020,
        ],
        'xyzD50ToLinearProphotoRgb' => [
            01.34578688164715830, -0.25557208737979464, -0.05110186497554526,
            -0.54463070512490190, 01.50824774284514680, 00.02052744743642139,
            00.00000000000000000, 00.00000000000000000, 01.21196754563894520,
        ],
    ];

    /**
     * @param ChannelVector $channels
     * @return ChannelVector
     */
    public function convert(string $from, string $to, array $channels): array
    {
        if ($from === $to) {
            return $channels;
        }

        return match ($from) {
            'hsl'               => $this->fromSrgb($to, $this->hslToSrgb(
                $channels[0] ?? 0.0,
                $channels[1] ?? 0.0,
                $channels[2] ?? 0.0,
            )),
            'hwb'               => $this->hwbConvert($to, $channels),
            'rgb'               => $this->fromSrgb($to, $this->scaleRgbLegacyChannels($channels)),
            'srgb'              => $this->fromSrgb($to, $channels),
            'srgb-linear'       => $this->srgbLinearConvert($to, $channels),
            'display-p3-linear' => $this->displayP3LinearConvert($to, $channels),
            'display-p3'        => $this->displayP3Convert($to, $channels),
            'a98-rgb',
            'prophoto-rgb',
            'rec2020',
            'xyz-d65'
                                => $this->convertLinear($from, $to, $channels),
            'xyz-d50'           => $this->xyzD50Convert($to, $channels),
            'lab'               => $this->labConvert($to, $channels),
            'lch'               => $this->lchConvert($to, $channels),
            'oklab'             => $this->oklabConvert($to, $channels),
            'oklch'             => $this->oklchConvert($to, $channels),
            default             => throw new LogicException("Unsupported source color space \"$from\"."),
        };
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float, 1: float, 2: float}
     */
    public function convertNumeric(string $from, string $to, array $channels): array
    {
        $converted = $this->convert($from, $to, [$channels[0] ?? 0.0, $channels[1] ?? 0.0, $channels[2] ?? 0.0]);

        return [
            $converted[0] ?? 0.0,
            $converted[1] ?? 0.0,
            $converted[2] ?? 0.0,
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float} hue degrees, saturation percent, lightness percent
     */
    public function srgbToHsl(float $r, float $g, float $b): array
    {
        [$hue, , $max, $min] = $this->computeHue($r, $g, $b);

        $lightness  = ($min + $max) / 2.0;
        $saturation = $lightness === 0.0 || $lightness === 1.0
            ? 0.0
            : 100.0 * ($max - $lightness) / min($lightness, 1.0 - $lightness);

        if ($saturation < 0) {
            $hue += 180.0;
        }

        $saturation = abs($saturation);

        if ($this->fuzzyEquals($saturation, 0.0)) {
            $hue = 0.0;
        }

        return [$this->positiveModulo($hue, 360.0), $saturation, $lightness * 100.0];
    }

    /**
     * @return array{0: float, 1: float, 2: float} hue degrees, whiteness percent, blackness percent
     */
    public function srgbToHwb(float $r, float $g, float $b): array
    {
        [$hue, , $max, $min] = $this->computeHue($r, $g, $b);

        return [$this->positiveModulo($hue, 360.0), $min * 100.0, 100.0 - $max * 100.0];
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    public function srgbToLinearSrgb(array $channels): array
    {
        [$r, $g, $b] = $channels;

        return [
            $r === null ? null : $this->srgbAndDisplayP3ToLinear($r),
            $g === null ? null : $this->srgbAndDisplayP3ToLinear($g),
            $b === null ? null : $this->srgbAndDisplayP3ToLinear($b),
        ];
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    public function linearSrgbToSrgb(array $channels): array
    {
        [$r, $g, $b] = $channels;

        return [
            $r === null ? null : $this->srgbAndDisplayP3FromLinear($r),
            $g === null ? null : $this->srgbAndDisplayP3FromLinear($g),
            $b === null ? null : $this->srgbAndDisplayP3FromLinear($b),
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float} sRGB channels in 0..1
     */
    public function hslToSrgb(float $hue, float $saturation, float $lightness): array
    {
        $scaledHue        = $this->positiveModulo($hue / 360.0, 1.0);
        $scaledSaturation = $saturation / 100.0;
        $scaledLightness  = $lightness / 100.0;

        $m2 = $scaledLightness <= 0.5
            ? $scaledLightness * ($scaledSaturation + 1.0)
            : $scaledLightness + $scaledSaturation - $scaledLightness * $scaledSaturation;
        $m1 = $scaledLightness * 2.0 - $m2;

        return [
            $this->hueToRgb($m1, $m2, $scaledHue + 1.0 / 3.0),
            $this->hueToRgb($m1, $m2, $scaledHue),
            $this->hueToRgb($m1, $m2, $scaledHue - 1.0 / 3.0),
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float} sRGB channels in 0..1
     */
    public function hwbToSrgb(float $hue, float $whiteness, float $blackness): array
    {
        $scaledHue       = $this->positiveModulo($hue, 360.0) / 360.0;
        $scaledWhiteness = $whiteness / 100.0;
        $scaledBlackness = $blackness / 100.0;

        $sum = $scaledWhiteness + $scaledBlackness;

        if ($sum > 1.0) {
            $scaledWhiteness /= $sum;
            $scaledBlackness /= $sum;
        }

        $factor = 1.0 - $scaledWhiteness - $scaledBlackness;

        return [
            $this->hueToRgb(0.0, 1.0, $scaledHue + 1.0 / 3.0) * $factor + $scaledWhiteness,
            $this->hueToRgb(0.0, 1.0, $scaledHue) * $factor + $scaledWhiteness,
            $this->hueToRgb(0.0, 1.0, $scaledHue - 1.0 / 3.0) * $factor + $scaledWhiteness,
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function labToLch(float $lightness, float $a, float $b): array
    {
        $chroma = sqrt($a * $a + $b * $b);
        $hue    = atan2($b, $a) * 180.0 / M_PI;

        return [$lightness, $chroma, $this->normalizeHue($hue)];
    }

    public function fuzzyEquals(float $number1, float $number2): bool
    {
        if ($number1 === $number2) {
            return true;
        }

        if (abs($number1) > 1e15 || abs($number2) > 1e15) {
            return false;
        }

        return abs($number1 - $number2) <= 1e-11
            || round($number1 * 1e11) === round($number2 * 1e11);
    }

    public function fuzzyIsInt(float $number): bool
    {
        return $this->fuzzyEquals($number, round($number));
    }

    public function trimPercent(float $value): string
    {
        $rounded = round($value, 10);
        $text    = sprintf('%.10f', $rounded);
        $text    = rtrim($text, '0');

        return rtrim($text, '.');
    }

    public function normalizeHue(float $hue, bool $invert = false): float
    {
        return $this->positiveModulo($this->positiveModulo($hue, 360.0) + 360.0 + ($invert ? 180.0 : 0.0), 360.0);
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function hwbConvert(string $to, array $channels): array
    {
        [$c0, $c1, $c2] = $channels;

        if ($c1 === null && $c2 === null) {
            if ($c0 === null) {
                return [null, null, null];
            }

            $converted = $this->fromSrgb($to, $this->hwbToSrgb($c0, 0.0, 0.0));

            return match ($to) {
                'hsl'          => [$converted[0], null, null],
                'lch', 'oklch' => [null, null, $converted[2]],
                default        => $converted,
            };
        }

        return $this->fromSrgb($to, $this->hwbToSrgb($c0 ?? 0.0, $c1 ?? 0.0, $c2 ?? 0.0));
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function scaleRgbLegacyChannels(array $channels): array
    {
        [$r, $g, $b] = $channels;

        return [
            $r === null ? null : $r / 255.0,
            $g === null ? null : $g / 255.0,
            $b === null ? null : $b / 255.0,
        ];
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function fromSrgb(string $to, array $channels): array
    {
        [$r, $g, $b] = $channels;

        return match ($to) {
            'hsl'         => $this->srgbToHsl($r ?? 0.0, $g ?? 0.0, $b ?? 0.0),
            'hwb'         => $this->srgbToHwb($r ?? 0.0, $g ?? 0.0, $b ?? 0.0),
            'rgb'         => [
                $r === null ? null : $r * 255.0,
                $g === null ? null : $g * 255.0,
                $b === null ? null : $b * 255.0,
            ],
            'srgb-linear' => $this->srgbToLinearSrgb($channels),
            default       => $this->convertLinear('srgb', $to, $channels),
        };
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function srgbLinearConvert(string $to, array $channels): array
    {
        if (in_array($to, ['rgb', 'hsl', 'hwb', 'srgb'], true)) {
            return $this->fromSrgb($to, $this->linearSrgbToSrgb($channels));
        }

        return $this->convertLinear('srgb-linear', $to, $channels);
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function displayP3LinearConvert(string $to, array $channels): array
    {
        if ($to === 'display-p3') {
            return $this->linearSrgbToSrgb($channels);
        }

        return $this->convertLinear('display-p3-linear', $to, $channels);
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function displayP3Convert(string $to, array $channels): array
    {
        if ($to === 'display-p3-linear') {
            return $this->srgbToLinearSrgb($channels);
        }

        return $this->convertLinear('display-p3', $to, $channels);
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function convertLinear(string $from, string $to, array $channels): array
    {
        $linearDest = match ($to) {
            'hsl', 'hwb'     => 'srgb',
            'lab', 'lch'     => 'xyz-d50',
            'oklab', 'oklch' => 'lms',
            default          => $to,
        };

        if ($linearDest === $from) {
            $transformed = $channels;
        } else {
            $matrix      = $this->transformationMatrix($from, $linearDest);
            $linearRed   = $this->toLinear($from, $channels[0] ?? 0.0);
            $linearGreen = $this->toLinear($from, $channels[1] ?? 0.0);
            $linearBlue  = $this->toLinear($from, $channels[2] ?? 0.0);

            $transformed = [
                $this->applyFromLinear($linearDest, $matrix[0] * $linearRed + $matrix[1] * $linearGreen + $matrix[2] * $linearBlue),
                $this->applyFromLinear($linearDest, $matrix[3] * $linearRed + $matrix[4] * $linearGreen + $matrix[5] * $linearBlue),
                $this->applyFromLinear($linearDest, $matrix[6] * $linearRed + $matrix[7] * $linearGreen + $matrix[8] * $linearBlue),
            ];
        }

        return match ($to) {
            'hsl', 'hwb'     => $this->fromSrgb($to, $transformed),
            'lab', 'lch'     => $this->xyzD50Convert($to, $transformed),
            'oklab', 'oklch' => $this->lmsConvert($to, $transformed),
            default          => [
                $channels[0] === null ? null : $transformed[0],
                $channels[1] === null ? null : $transformed[1],
                $channels[2] === null ? null : $transformed[2],
            ],
        };
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function xyzD50Convert(string $to, array $channels): array
    {
        if (! in_array($to, ['lab', 'lch'], true)) {
            return $this->convertLinear('xyz-d50', $to, $channels);
        }

        [$x, $y, $z] = $channels;

        $f0 = $this->convertComponentToLabF(($x ?? 0.0) / self::D50[0]);
        $f1 = $this->convertComponentToLabF(($y ?? 0.0) / self::D50[1]);
        $f2 = $this->convertComponentToLabF(($z ?? 0.0) / self::D50[2]);

        $lightness = 116.0 * $f1 - 16.0;

        $a = 500.0 * ($f0 - $f1);
        $b = 200.0 * ($f1 - $f2);

        return $to === 'lab'
            ? [$lightness, $a, $b]
            : $this->labToLch($lightness, $a, $b);
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function labConvert(string $to, array $channels): array
    {
        [$lightness, $a, $b] = $channels;

        if ($to === 'lch') {
            return $this->labToLch($lightness ?? 0.0, $a ?? 0.0, $b ?? 0.0);
        }

        return $this->labToNonLab($to, $lightness, $a ?? 0.0, $b ?? 0.0);
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function lchConvert(string $to, array $channels): array
    {
        [$lightness, $chroma, $hue] = $channels;

        $hueRadians = ($hue ?? 0.0) * M_PI / 180.0;

        $a = ($chroma ?? 0.0) * cos($hueRadians);
        $b = ($chroma ?? 0.0) * sin($hueRadians);

        return $this->labToNonLab($to, $lightness, $a, $b);
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function oklabConvert(string $to, array $channels): array
    {
        [$lightness, $a, $b] = $channels;

        if ($to === 'oklch') {
            return $this->labToLch($lightness ?? 0.0, $a ?? 0.0, $b ?? 0.0);
        }

        $matrix = self::MATRICES['oklabToLms'];
        $lValue = $lightness ?? 0.0;
        $aValue = $a ?? 0.0;
        $bValue = $b ?? 0.0;

        return $this->lmsConvert($to, [
            $this->cube($matrix[0] * $lValue + $matrix[1] * $aValue + $matrix[2] * $bValue),
            $this->cube($matrix[3] * $lValue + $matrix[4] * $aValue + $matrix[5] * $bValue),
            $this->cube($matrix[6] * $lValue + $matrix[7] * $aValue + $matrix[8] * $bValue),
        ]);
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function oklchConvert(string $to, array $channels): array
    {
        [$lightness, $chroma, $hue] = $channels;

        $hueRadians = ($hue ?? 0.0) * M_PI / 180.0;

        $a = ($chroma ?? 0.0) * cos($hueRadians);
        $b = ($chroma ?? 0.0) * sin($hueRadians);

        $matrix = self::MATRICES['oklabToLms'];
        $lValue = $lightness ?? 0.0;

        return $this->lmsConvert($to, [
            $this->cube($matrix[0] * $lValue + $matrix[1] * $a + $matrix[2] * $b),
            $this->cube($matrix[3] * $lValue + $matrix[4] * $a + $matrix[5] * $b),
            $this->cube($matrix[6] * $lValue + $matrix[7] * $a + $matrix[8] * $b),
        ]);
    }

    /**
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function labToNonLab(string $to, ?float $lightness, float $a, float $b): array
    {
        $lValue = $lightness ?? 0.0;
        $f1     = ($lValue + 16.0) / 116.0;

        return $this->xyzD50Convert($to, [
            $this->convertFToXorZ($a / 500.0 + $f1) * self::D50[0],
            ($lValue > self::LAB_KAPPA * self::LAB_EPSILON
                ? $this->cube(($lValue + 16.0) / 116.0) * 1.0
                : $lValue / self::LAB_KAPPA) * self::D50[1],
            $this->convertFToXorZ($f1 - $b / 200.0) * self::D50[2],
        ]);
    }

    /**
     * @param ChannelVector $channels
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function lmsConvert(string $to, array $channels): array
    {
        if (in_array($to, ['oklab', 'oklch'], true)) {
            [$long, $medium, $short] = $channels;

            $longScaled   = $this->cubeRootPreservingSign($long ?? 0.0);
            $mediumScaled = $this->cubeRootPreservingSign($medium ?? 0.0);
            $shortScaled  = $this->cubeRootPreservingSign($short ?? 0.0);

            $matrix    = self::MATRICES['lmsToOklab'];
            $lightness = $matrix[0] * $longScaled + $matrix[1] * $mediumScaled + $matrix[2] * $shortScaled;

            $a = $matrix[3] * $longScaled + $matrix[4] * $mediumScaled + $matrix[5] * $shortScaled;
            $b = $matrix[6] * $longScaled + $matrix[7] * $mediumScaled + $matrix[8] * $shortScaled;

            return $to === 'oklab'
                ? [$lightness, $a, $b]
                : $this->labToLch($lightness, $a, $b);
        }

        return $this->convertLinear('lms', $to, $channels);
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} hue, delta, max, min
     */
    private function computeHue(float $r, float $g, float $b): array
    {
        $max   = max(max($r, $g), $b);
        $min   = min(min($r, $g), $b);
        $delta = $max - $min;

        if ($max === $min) {
            $hue = 0.0;
        } elseif ($max === $r) {
            $hue = 60.0 * ($g - $b) / $delta + 360.0;
        } elseif ($max === $g) {
            $hue = 60.0 * ($b - $r) / $delta + 120.0;
        } else {
            $hue = 60.0 * ($r - $g) / $delta + 240.0;
        }

        return [$hue, $delta, $max, $min];
    }

    private function applyFromLinear(string $dest, float $channel): float
    {
        return match ($dest) {
            'srgb',
            'display-p3'   => $this->srgbAndDisplayP3FromLinear($channel),
            'rgb'          => $this->srgbAndDisplayP3FromLinear($channel) * 255.0,
            'a98-rgb'      => $this->sign($channel) * abs($channel) ** (256.0 / 563.0),
            'prophoto-rgb' => abs($channel) >= 1.0 / 512.0
                ? $this->sign($channel) * abs($channel) ** (1.0 / 1.8)
                : 16.0 * $channel,
            'rec2020'      => $this->sign($channel) * abs($channel) ** (1.0 / 2.4),
            default        => $channel,
        };
    }

    private function toLinear(string $space, float $channel): float
    {
        return match ($space) {
            'srgb',
            'display-p3'   => $this->srgbAndDisplayP3ToLinear($channel),
            'a98-rgb'      => $this->sign($channel) * abs($channel) ** (563.0 / 256.0),
            'prophoto-rgb' => abs($channel) <= 16.0 / 512.0
                ? $channel / 16.0
                : $this->sign($channel) * abs($channel) ** 1.8,
            'rec2020'      => $this->sign($channel) * abs($channel) ** 2.40,
            default        => $channel,
        };
    }

    /**
     * @return array<int, float>
     */
    private function transformationMatrix(string $from, string $to): array
    {
        return match ($to) {
            'srgb', 'srgb-linear', 'rgb' => match ($from) {
                'display-p3-linear' => self::MATRICES['linearDisplayP3ToLinearSrgb'],
                'display-p3'        => self::MATRICES['linearDisplayP3ToLinearSrgb'],
                'a98-rgb'           => self::MATRICES['linearA98RgbToLinearSrgb'],
                'prophoto-rgb'      => self::MATRICES['linearProphotoRgbToLinearSrgb'],
                'rec2020'           => self::MATRICES['linearRec2020ToLinearSrgb'],
                'xyz-d65'           => self::MATRICES['xyzD65ToLinearSrgb'],
                'xyz-d50'           => self::MATRICES['xyzD50ToLinearSrgb'],
                'lms'               => self::MATRICES['lmsToLinearSrgb'],
                default             => throw new LogicException("Unsupported matrix source \"$from\"."),
            },
            'display-p3', 'display-p3-linear' => match ($from) {
                'srgb'         => self::MATRICES['linearSrgbToLinearDisplayP3'],
                'srgb-linear'  => self::MATRICES['linearSrgbToLinearDisplayP3'],
                'a98-rgb'      => self::MATRICES['linearA98RgbToLinearDisplayP3'],
                'prophoto-rgb' => self::MATRICES['linearProphotoRgbToLinearDisplayP3'],
                'rec2020'      => self::MATRICES['linearRec2020ToLinearDisplayP3'],
                'xyz-d65'      => self::MATRICES['xyzD65ToLinearDisplayP3'],
                'xyz-d50'      => self::MATRICES['xyzD50ToLinearDisplayP3'],
                'lms'          => self::MATRICES['lmsToLinearDisplayP3'],
                default        => throw new LogicException("Unsupported matrix source \"$from\"."),
            },
            'a98-rgb' => match ($from) {
                'srgb'              => self::MATRICES['linearSrgbToLinearA98Rgb'],
                'srgb-linear'       => self::MATRICES['linearSrgbToLinearA98Rgb'],
                'display-p3'        => self::MATRICES['linearDisplayP3ToLinearA98Rgb'],
                'display-p3-linear' => self::MATRICES['linearDisplayP3ToLinearA98Rgb'],
                'prophoto-rgb'      => self::MATRICES['linearProphotoRgbToLinearA98Rgb'],
                'rec2020'           => self::MATRICES['linearRec2020ToLinearA98Rgb'],
                'xyz-d65'           => self::MATRICES['xyzD65ToLinearA98Rgb'],
                'xyz-d50'           => self::MATRICES['xyzD50ToLinearA98Rgb'],
                'lms'               => self::MATRICES['lmsToLinearA98Rgb'],
                default             => throw new LogicException("Unsupported matrix source \"$from\"."),
            },
            'prophoto-rgb' => match ($from) {
                'srgb'              => self::MATRICES['linearSrgbToLinearProphotoRgb'],
                'srgb-linear'       => self::MATRICES['linearSrgbToLinearProphotoRgb'],
                'display-p3'        => self::MATRICES['linearDisplayP3ToLinearProphotoRgb'],
                'display-p3-linear' => self::MATRICES['linearDisplayP3ToLinearProphotoRgb'],
                'a98-rgb'           => self::MATRICES['linearA98RgbToLinearProphotoRgb'],
                'rec2020'           => self::MATRICES['linearRec2020ToLinearProphotoRgb'],
                'xyz-d65'           => self::MATRICES['xyzD65ToLinearProphotoRgb'],
                'xyz-d50'           => self::MATRICES['xyzD50ToLinearProphotoRgb'],
                'lms'               => self::MATRICES['lmsToLinearProphotoRgb'],
                default             => throw new LogicException("Unsupported matrix source \"$from\"."),
            },
            'rec2020' => match ($from) {
                'srgb'              => self::MATRICES['linearSrgbToLinearRec2020'],
                'srgb-linear'       => self::MATRICES['linearSrgbToLinearRec2020'],
                'display-p3'        => self::MATRICES['linearDisplayP3ToLinearRec2020'],
                'display-p3-linear' => self::MATRICES['linearDisplayP3ToLinearRec2020'],
                'a98-rgb'           => self::MATRICES['linearA98RgbToLinearRec2020'],
                'prophoto-rgb'      => self::MATRICES['linearProphotoRgbToLinearRec2020'],
                'xyz-d65'           => self::MATRICES['xyzD65ToLinearRec2020'],
                'xyz-d50'           => self::MATRICES['xyzD50ToLinearRec2020'],
                'lms'               => self::MATRICES['lmsToLinearRec2020'],
                default             => throw new LogicException("Unsupported matrix source \"$from\"."),
            },
            'xyz-d65' => match ($from) {
                'srgb'              => self::MATRICES['linearSrgbToXyzD65'],
                'srgb-linear'       => self::MATRICES['linearSrgbToXyzD65'],
                'display-p3'        => self::MATRICES['linearDisplayP3ToXyzD65'],
                'display-p3-linear' => self::MATRICES['linearDisplayP3ToXyzD65'],
                'a98-rgb'           => self::MATRICES['linearA98RgbToXyzD65'],
                'prophoto-rgb'      => self::MATRICES['linearProphotoRgbToXyzD65'],
                'rec2020'           => self::MATRICES['linearRec2020ToXyzD65'],
                'xyz-d50'           => self::MATRICES['xyzD50ToXyzD65'],
                'lms'               => self::MATRICES['lmsToXyzD65'],
                default             => throw new LogicException("Unsupported matrix source \"$from\"."),
            },
            'xyz-d50' => match ($from) {
                'srgb'              => self::MATRICES['linearSrgbToXyzD50'],
                'srgb-linear'       => self::MATRICES['linearSrgbToXyzD50'],
                'display-p3'        => self::MATRICES['linearDisplayP3ToXyzD50'],
                'display-p3-linear' => self::MATRICES['linearDisplayP3ToXyzD50'],
                'a98-rgb'           => self::MATRICES['linearA98RgbToXyzD50'],
                'prophoto-rgb'      => self::MATRICES['linearProphotoRgbToXyzD50'],
                'rec2020'           => self::MATRICES['linearRec2020ToXyzD50'],
                'xyz-d65'           => self::MATRICES['xyzD65ToXyzD50'],
                'lms'               => self::MATRICES['lmsToXyzD50'],
                default             => throw new LogicException("Unsupported matrix source \"$from\"."),
            },
            'lms' => match ($from) {
                'srgb'              => self::MATRICES['linearSrgbToLms'],
                'srgb-linear'       => self::MATRICES['linearSrgbToLms'],
                'display-p3'        => self::MATRICES['linearDisplayP3ToLms'],
                'display-p3-linear' => self::MATRICES['linearDisplayP3ToLms'],
                'a98-rgb'           => self::MATRICES['linearA98RgbToLms'],
                'prophoto-rgb'      => self::MATRICES['linearProphotoRgbToLms'],
                'rec2020'           => self::MATRICES['linearRec2020ToLms'],
                'xyz-d65'           => self::MATRICES['xyzD65ToLms'],
                'xyz-d50'           => self::MATRICES['xyzD50ToLms'],
                default             => throw new LogicException("Unsupported matrix source \"$from\"."),
            },
            default => throw new LogicException("Unsupported matrix destination \"$to\"."),
        };
    }

    private function convertFToXorZ(float $component): float
    {
        $cubed = $this->cube($component);

        return $cubed > self::LAB_EPSILON ? $cubed : (116.0 * $component - 16.0) / self::LAB_KAPPA;
    }

    private function cube(float $value): float
    {
        return $value * $value * $value + 0.0;
    }

    private function convertComponentToLabF(float $component): float
    {
        return $component > self::LAB_EPSILON
            ? $component ** (1 / 3) + 0.0
            : (self::LAB_KAPPA * $component + 16.0) / 116.0;
    }

    private function cubeRootPreservingSign(float $number): float
    {
        return abs($number) ** (1 / 3) * $this->sign($number);
    }

    private function srgbAndDisplayP3ToLinear(float $channel): float
    {
        $abs = abs($channel);

        return $abs <= 0.04045
            ? $channel / 12.92
            : $this->sign($channel) * (($abs + 0.055) / 1.055) ** 2.4;
    }

    private function srgbAndDisplayP3FromLinear(float $channel): float
    {
        $abs = abs($channel);

        return $abs <= 0.0031308
            ? $channel * 12.92
            : $this->sign($channel) * (1.055 * $abs ** (1.0 / 2.4) - 0.055);
    }

    private function sign(float $value): float
    {
        if ($value === 0.0) {
            return $value;
        }

        return $value > 0 ? 1.0 : -1.0;
    }

    private function positiveModulo(float $value, float $divisor): float
    {
        $result = fmod($value, $divisor);

        if ($result < 0) {
            $result += $divisor;
        }

        return $result;
    }

    private function hueToRgb(float $m1, float $m2, float $hue): float
    {
        if ($hue < 0) {
            $hue += 1.0;
        }

        if ($hue > 1) {
            $hue -= 1.0;
        }

        return match (true) {
            $hue < 1.0 / 6.0 => $m1 + ($m2 - $m1) * $hue * 6.0,
            $hue < 1.0 / 2.0 => $m2,
            $hue < 2.0 / 3.0 => $m1 + ($m2 - $m1) * (2.0 / 3.0 - $hue) * 6.0,
            default          => $m1,
        };
    }
}
