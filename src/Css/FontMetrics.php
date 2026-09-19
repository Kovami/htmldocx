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
        'helvetica' => 1.1753, 'helvetica neue' => 1.193, 'roboto' => 1.2002, 'pt sans' => 1.295, 'pt serif' => 1.294,
        'microsoft sans serif' => 1.1318, 'rockwell' => 1.1743, 'tw cen mt' => 1.0889, 'perpetua' => 1.146,
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

    /** Word's single line height as a multiple of the font size, or null for a font not measured. */
    public static function singleLine(string $family): ?float
    {
        return self::SINGLE_LINE[strtolower(trim($family))] ?? null;
    }
}
