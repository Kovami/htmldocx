<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

/**
 * How tall Word makes a single-spaced line of a font, as a multiple of the
 * font size: the larger of the font's Windows ascent + descent and its
 * ascent + descent + line gap. Word's multiple line spacing multiplies this,
 * CSS's unitless `line-height` multiplies the font size itself.
 *
 * Measured from the fonts Word and the common systems ship; metric-compatible
 * substitutes (Carlito, Caladea, Liberation) share their original's value.
 */
final class FontMetrics
{
    // ponytail: a fixed table of common fonts; a font not in it keeps CSS's own line height.
    private const array SINGLE_LINE = [
        'aptos' => 1.2847, 'aptos display' => 1.2847, 'aptos narrow' => 1.2847, 'aptos light' => 1.2847,
        'aptos semibold' => 1.2847,
        'calibri' => 1.2207, 'calibri light' => 1.2207, 'carlito' => 1.2207,
        'cambria' => 1.1724, 'caladea' => 1.1724,
        'candara' => 1.2207, 'consolas' => 1.1709, 'constantia' => 1.2207, 'corbel' => 1.2207,
        'arial' => 1.1499, 'liberation sans' => 1.1499, 'arial narrow' => 1.1475, 'arial black' => 1.4102,
        'times new roman' => 1.1499, 'liberation serif' => 1.1499,
        'courier new' => 1.1328, 'liberation mono' => 1.1328,
        'georgia' => 1.1362, 'verdana' => 1.2153, 'tahoma' => 1.207, 'trebuchet ms' => 1.1611,
        'segoe ui' => 1.3301, 'segoe ui light' => 1.3301, 'segoe ui semibold' => 1.3301,
        'garamond' => 1.125, 'book antiqua' => 1.2056, 'bookman old style' => 1.1738,
        'century gothic' => 1.2261, 'century' => 1.2021, 'palatino linotype' => 1.3491,
        'franklin gothic book' => 1.1338, 'franklin gothic medium' => 1.1338, 'gill sans mt' => 1.1597,
        'lucida console' => 1.0, 'lucida sans unicode' => 1.5366, 'comic sans ms' => 1.3936, 'impact' => 1.2197,
        'helvetica' => 1.2, 'helvetica neue' => 1.193, 'roboto' => 1.2002, 'pt sans' => 1.295, 'pt serif' => 1.294,
        'microsoft sans serif' => 1.1318, 'rockwell' => 1.1743, 'tw cen mt' => 1.0889, 'perpetua' => 1.146,
    ];

    /**
     * How Chromium lays out a line of the font, as [ascent, descent,
     * `line-height: normal`], multiples of the font size. Measured in
     * Chromium on macOS with Word's own font files; fonts macOS does not have
     * are left out.
     */
    // ponytail: measured on macOS only; Chromium on Windows reads the Windows metrics, which differ for a few of these.
    private const array CSS_LINE = [
        'aptos' => [0.939, 0.282, 1.221], 'aptos narrow' => [0.939, 0.282, 1.221], 'aptos light' => [0.939, 0.282, 1.221],
        'aptos semibold' => [0.939, 0.282, 1.221], 'calibri' => [0.952, 0.269, 1.221], 'calibri light' => [0.952, 0.269, 1.221],
        'cambria' => [0.95, 0.222, 1.172], 'constantia' => [0.751, 0.249, 1.221], 'corbel' => [0.744, 0.256, 1.208], 'arial' => [0.905, 0.212, 1.15],
        'arial narrow' => [0.936, 0.212, 1.148], 'arial black' => [1.101, 0.31, 1.411], 'times new roman' => [0.891, 0.216, 1.107],
        'courier new' => [0.833, 0.3, 1.133], 'georgia' => [0.917, 0.219, 1.136], 'verdana' => [1.005, 0.21, 1.215], 'tahoma' => [1.0, 0.207, 1.207],
        'trebuchet ms' => [0.939, 0.222, 1.161], 'garamond' => [0.862, 0.263, 1.125], 'book antiqua' => [0.923, 0.282, 1.205],
        'bookman old style' => [0.942, 0.232, 1.174], 'century gothic' => [1.006, 0.22, 1.226], 'century' => [0.986, 0.216, 1.202],
        'palatino linotype' => [1.05, 0.299, 1.349], 'franklin gothic book' => [0.917, 0.217, 1.134],
        'franklin gothic medium' => [0.917, 0.217, 1.134], 'gill sans mt' => [0.929, 0.23, 1.159], 'lucida console' => [0.789, 0.211, 1.0],
        'lucida sans unicode' => [1.097, 0.44, 1.537], 'comic sans ms' => [1.102, 0.292, 1.394], 'impact' => [1.009, 0.211, 1.22],
        'helvetica' => [0.92, 0.23, 1.15], 'helvetica neue' => [0.952, 0.213, 1.193], 'pt sans' => [0.9, 0.276, 1.295],
        'pt serif' => [1.018, 0.276, 1.294], 'microsoft sans serif' => [0.922, 0.21, 1.132], 'rockwell' => [0.946, 0.229, 1.175],
        'tw cen mt' => [0.856, 0.233, 1.089], 'perpetua' => [0.82, 0.326, 1.146],
    ];

    /**
     * Windows ascent, as a multiple of the font size, of the fonts whose
     * ascent is below Symbol's, which Word's bullets are drawn in: a line
     * holds the tallest ascent of its fonts, so Word makes a bulleted line
     * in them taller.
     */
    private const array ASCENT = [
        'calibri' => 0.9521, 'calibri light' => 0.9521, 'carlito' => 0.9521,
        'cambria' => 0.9502, 'caladea' => 0.9502,
        'arial' => 0.9053, 'liberation sans' => 0.9053,
        'times new roman' => 0.8911, 'liberation serif' => 0.8911,
    ];

    public const float SYMBOL_ASCENT = 1.0054;

    /** Windows ascent as a multiple of the font size; null when it is not below Symbol's or not measured. */
    public static function ascent(string $family): ?float
    {
        return self::ASCENT[strtolower(trim($family))] ?? null;
    }

    /**
     * How much lower a browser sets the first line of a paragraph than Word
     * does, in points, for a line box `$lineHeightPt` tall (null: `normal`).
     * Chromium centres the font's content in the line box; Word puts the
     * font's line gap above the text and a multiple's extra space below the
     * line, so its baseline sits at the single line less the descent.
     * Null for a font not measured.
     */
    public static function baselineShift(string $family, float $sizePt, ?float $lineHeightPt): ?float
    {
        $key = strtolower(trim($family));
        $single = self::SINGLE_LINE[$key] ?? null;
        [$ascent, $descent, $normal] = self::CSS_LINE[$key] ?? [null, null, null];

        if ($single === null || $ascent === null) {
            return null;
        }

        return (($lineHeightPt ?? $normal * $sizePt) + ($ascent + $descent) * $sizePt) / 2 - $single * $sizePt;
    }

    /**
     * How much taller a browser makes a line holding text in this style than
     * Word does, in points: [above, below]. A browser raises or lowers a
     * superscript's or subscript's own line box and grows the line to hold
     * it; Word keeps the line and moves only the glyphs. Measured in Chromium
     * over Calibri, Helvetica Neue, Arial and Times at 11–16pt, normal and 1.5
     * line height: 0.23 and 0.20 of the text size, give or take a pixel. A
     * script with no line height of its own (`line-height: 0`) grows nothing.
     *
     * @return array{0: float, 1: float}
     */
    public static function scriptGrowth(ComputedStyle $style): array
    {
        // The computed line height never is 0 (Word has no such line), so read the declaration.
        if (preg_match('/^0(\.0*)?([a-z]+|%)?$/', (string) $style->value('line-height')) === 1) {
            return [0.0, 0.0];
        }

        return match ($style->verticalPosition) {
            'super' => [0.23 * $style->fontSizePt, 0.0],
            'sub' => [0.0, 0.20 * $style->fontSizePt],
            default => [0.0, 0.0],
        };
    }

    /**
     * How far a browser's line box reaches below the baseline, in points:
     * the font's descent and half its leading. A picture standing on the
     * baseline alone in its line leaves that much under it, where Word's line
     * ends at the picture. Null for a font not measured.
     */
    public static function belowBaseline(string $family, float $sizePt, ?float $lineHeightPt): ?float
    {
        [$ascent, $descent, $normal] = self::CSS_LINE[strtolower(trim($family))] ?? [null, null, null];

        if ($ascent === null) {
            return null;
        }

        return $descent * $sizePt + (($lineHeightPt ?? $normal * $sizePt) - ($ascent + $descent) * $sizePt) / 2;
    }

    /**
     * Whether a browser makes a line taller to hold a run in another font
     * where Word does not. Word's line is the tallest single line of its
     * fonts; a browser's holds each run's box, its own half-leading around
     * its own font, and Courier New's in Calibri reaches 0.075em lower.
     * The run is taken with the line height it inherits from the paragraph.
     */
    public static function outgrowsLine(ComputedStyle $run, ComputedStyle $paragraph): bool
    {
        $box = static function (ComputedStyle $style) use ($paragraph): ?array {
            [$ascent, $descent, $normal] = self::CSS_LINE[strtolower(trim($style->fontFamily))] ?? [null, null, null];
            $single = self::singleLine($style->fontFamily);

            if ($ascent === null || $single === null) {
                return null;
            }

            $size = $style->fontSizePt;
            $height = $paragraph->lineHeight;
            $line = $height === null ? $normal * $size : ($height->multiple !== null ? $height->multiple * $size : $height->points);
            $half = ($line - ($ascent + $descent) * $size) / 2;

            return [$ascent * $size + $half, $descent * $size + $half, $single * $size];
        };
        [$run, $paragraph] = [$box($run), $box($paragraph)];

        return $run !== null && $paragraph !== null && $run[2] <= $paragraph[2]
            && ($run[0] > $paragraph[0] + 0.01 || $run[1] > $paragraph[1] + 0.01);
    }

    /** Word's single line height as a multiple of the font size, or null for a font not measured. */
    public static function singleLine(string $family): ?float
    {
        return self::SINGLE_LINE[strtolower(trim($family))] ?? null;
    }
}
