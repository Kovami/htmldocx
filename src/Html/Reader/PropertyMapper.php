<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Kovami\HtmlDocx\Css\BorderEdge;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\Length;
use Kovami\HtmlDocx\Css\LineHeight;
use Kovami\HtmlDocx\Model\Border;
use Kovami\HtmlDocx\Model\RunProperties;

/** Translates computed CSS values into WordprocessingML vocabulary. */
final class PropertyMapper
{
    public function run(ComputedStyle $style): RunProperties
    {
        return new RunProperties(
            fontFamily: $style->fontFamily,
            size: max(2, (int) round($style->fontSizePt * 2)),
            bold: $style->bold,
            italic: $style->italic,
            underline: match ($style->underline) {
                null => 'none',
                'double' => 'double',
                'dotted' => 'dotted',
                'dashed' => 'dash',
                'wavy' => 'wave',
                default => 'single',
            },
            strike: $style->strike,
            color: $style->color,
            shading: $style->inlineBackground,
            verticalAlign: match ($style->verticalPosition) {
                'super' => 'superscript',
                'sub' => 'subscript',
                default => 'baseline',
            },
            caps: $style->textTransform === 'uppercase',
            smallCaps: $style->smallCaps,
            spacing: Length::pointsToTwips($style->letterSpacingPt),
            shadow: $style->shadow,
            rtl: $style->direction === 'rtl',
            kerning: $style->kerning,
        );
    }

    public function border(?BorderEdge $edge, float $paddingPt = 0.0): ?Border
    {
        if ($edge === null) {
            return null;
        }

        return new Border(
            style: match ($edge->style) {
                'dotted' => 'dotted',
                'dashed' => 'dashed',
                'double' => 'double',
                'groove' => 'threeDEngrave',
                'ridge' => 'threeDEmboss',
                'inset' => 'inset',
                'outset' => 'outset',
                default => 'single',
            },
            size: max(2, min(96, (int) round($edge->widthPt * 8))),
            color: $edge->color,
            space: max(0, min(31, (int) round($paddingPt))),
        );
    }

    public function alignment(?string $textAlign): ?string
    {
        return match ($textAlign) {
            'center' => 'center',
            'right' => 'right',
            'justify' => 'both',
            'left' => 'left',
            default => null,
        };
    }

    /**
     * @param  float  $singleLine  the font's single line as a multiple of its size, to read
     *                             a multiple of the size as Word's multiple of that line
     * @param  float|null  $fontSizePt  the CSS font size: Word only has half points (13px
     *                                  becomes 10pt), and the multiple applies to what it has
     * @return array{0: int|null, 1: string|null} line value and ST_LineSpacingRule
     */
    public function lineSpacing(?LineHeight $lineHeight, float $singleLine = 1.0, ?float $fontSizePt = null): array
    {
        $wordSize = $fontSizePt === null ? null : max(2, (int) round($fontSizePt * 2)) / 2;
        // Below 0.01 pt the difference is how the size was written (1.33px for 1pt), not a size Word lacks.
        $rounding = $wordSize === null || abs($fontSizePt - $wordSize) < 0.01 ? 1.0 : $fontSizePt / $wordSize;

        return match (true) {
            $lineHeight?->multiple !== null => [max(1, (int) round($lineHeight->multiple / $singleLine * 240 * $rounding)), 'auto'],
            $lineHeight?->points !== null => [max(1, Length::pointsToTwips($lineHeight->points)), 'atLeast'],
            default => [null, null],
        };
    }
}
