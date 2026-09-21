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
     * How tall Chromium makes a line of the font, as [content (ascent +
     * descent), `line-height: normal`], multiples of the font size. Measured
     * in Chromium on macOS with Word's own font files; fonts macOS does not
     * have are left out.
     */
    // ponytail: measured on macOS only; Chromium on Windows reads the Windows metrics, which differ for a few of these.
    private const array CSS_LINE = [
        'aptos' => [1.221, 1.221], 'aptos narrow' => [1.221, 1.221], 'aptos light' => [1.221, 1.221], 'aptos semibold' => [1.221, 1.221],
        'calibri' => [1.221, 1.221], 'calibri light' => [1.221, 1.221], 'cambria' => [1.172, 1.172],
        'constantia' => [1.0, 1.221], 'corbel' => [1.0, 1.208],
        'arial' => [1.117, 1.15], 'arial narrow' => [1.148, 1.148], 'arial black' => [1.411, 1.411],
        'times new roman' => [1.107, 1.107], 'courier new' => [1.133, 1.133], 'georgia' => [1.136, 1.136],
        'verdana' => [1.215, 1.215], 'tahoma' => [1.207, 1.207], 'trebuchet ms' => [1.161, 1.161],
        'garamond' => [1.125, 1.125], 'book antiqua' => [1.205, 1.205], 'bookman old style' => [1.174, 1.174],
        'century gothic' => [1.226, 1.226], 'century' => [1.202, 1.202], 'palatino linotype' => [1.349, 1.349],
        'franklin gothic book' => [1.134, 1.134], 'franklin gothic medium' => [1.134, 1.134], 'gill sans mt' => [1.159, 1.159],
        'lucida console' => [1.0, 1.0], 'lucida sans unicode' => [1.537, 1.537], 'comic sans ms' => [1.394, 1.394],
        'impact' => [1.22, 1.22], 'helvetica' => [1.15, 1.15], 'helvetica neue' => [1.165, 1.193],
        'pt sans' => [1.176, 1.295], 'pt serif' => [1.294, 1.294], 'microsoft sans serif' => [1.132, 1.132],
        'rockwell' => [1.175, 1.175], 'tw cen mt' => [1.089, 1.089], 'perpetua' => [1.146, 1.146],
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
        [$content, $normal] = self::CSS_LINE[$key] ?? [null, null];

        if ($single === null || $content === null) {
            return null;
        }

        return (($lineHeightPt ?? $normal * $sizePt) + $content * $sizePt) / 2 - $single * $sizePt;
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

    /** Word's single line height as a multiple of the font size, or null for a font not measured. */
    public static function singleLine(string $family): ?float
    {
        return self::SINGLE_LINE[strtolower(trim($family))] ?? null;
    }
}
