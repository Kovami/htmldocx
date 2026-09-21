<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader\Format;

use Dom\Element;
use Kovami\HtmlDocx\Docx\Reader\Theme;
use Kovami\HtmlDocx\Docx\Reader\Xml;
use Kovami\HtmlDocx\Model\Border;

/** Reads WordprocessingML property elements into format layers, resolving theme fonts and colours. */
final readonly class FormatParser
{
    /** ST_HighlightColor names. */
    private const array HIGHLIGHTS = [
        'black' => '000000', 'blue' => '0000FF', 'cyan' => '00FFFF', 'green' => '00FF00',
        'magenta' => 'FF00FF', 'red' => 'FF0000', 'yellow' => 'FFFF00', 'white' => 'FFFFFF',
        'darkBlue' => '000080', 'darkCyan' => '008080', 'darkGreen' => '008000', 'darkMagenta' => '800080',
        'darkRed' => '800000', 'darkYellow' => '808000', 'darkGray' => '808080', 'lightGray' => 'C0C0C0',
    ];

    public function __construct(private Theme $theme = new Theme()) {}

    public function run(?Element $rPr): RunFormat
    {
        if ($rPr === null) {
            return new RunFormat();
        }

        $strike = Xml::onOff($rPr, 'strike');
        $doubleStrike = Xml::onOff($rPr, 'dstrike');
        $highlight = Xml::val($rPr, 'highlight');
        $underline = Xml::val($rPr, 'u');

        return new RunFormat(
            fontFamily: $this->font(Xml::child($rPr, 'rFonts')),
            size: Xml::halfPoints(Xml::val($rPr, 'sz')),
            bold: Xml::onOff($rPr, 'b'),
            italic: Xml::onOff($rPr, 'i'),
            underline: $underline === null && Xml::child($rPr, 'u') !== null ? 'single' : $underline,
            strike: $strike === true || $doubleStrike === true ? true : ($strike ?? $doubleStrike),
            color: $this->color(Xml::child($rPr, 'color')),
            highlight: $highlight === null ? null : (self::HIGHLIGHTS[$highlight] ?? ($highlight === 'none' ? 'none' : null)),
            shading: $this->shading(Xml::child($rPr, 'shd')),
            verticalAlign: match (Xml::val($rPr, 'vertAlign')) {
                'superscript' => 'superscript',
                'subscript' => 'subscript',
                'baseline' => 'baseline',
                default => null,
            },
            caps: Xml::onOff($rPr, 'caps'),
            smallCaps: Xml::onOff($rPr, 'smallCaps'),
            spacing: Xml::twips(Xml::val($rPr, 'spacing')),
            shadow: Xml::onOff($rPr, 'shadow'),
            rtl: Xml::onOff($rPr, 'rtl'),
            hidden: Xml::onOff($rPr, 'vanish'),
        );
    }

    public function paragraph(?Element $pPr): ParagraphFormat
    {
        if ($pPr === null) {
            return new ParagraphFormat();
        }

        $ind = Xml::child($pPr, 'ind');
        $spacing = Xml::child($pPr, 'spacing');
        $numPr = Xml::child($pPr, 'numPr');
        $hanging = Xml::twips(Xml::attr($ind, 'hanging'));
        $firstLine = Xml::twips(Xml::attr($ind, 'firstLine'));
        $outline = Xml::int(Xml::val($pPr, 'outlineLvl'));

        return new ParagraphFormat(
            alignment: match (Xml::val($pPr, 'jc')) {
                'left', 'start' => 'left',
                'right', 'end' => 'right',
                'center' => 'center',
                'both', 'distribute', 'lowKashida', 'mediumKashida', 'highKashida', 'thaiDistribute' => 'both',
                default => null,
            },
            indentLeft: Xml::twips(Xml::attr($ind, 'left') ?? Xml::attr($ind, 'start')),
            indentRight: Xml::twips(Xml::attr($ind, 'right') ?? Xml::attr($ind, 'end')),
            firstLine: $hanging !== null ? -$hanging : $firstLine,
            spacingBefore: Xml::twips(Xml::attr($spacing, 'before'))
                ?? self::lines(Xml::attr($spacing, 'beforeLines')),
            spacingAfter: Xml::twips(Xml::attr($spacing, 'after'))
                ?? self::lines(Xml::attr($spacing, 'afterLines')),
            beforeAutospacing: self::attributeOnOff($spacing, 'beforeAutospacing'),
            afterAutospacing: self::attributeOnOff($spacing, 'afterAutospacing'),
            lineSpacing: Xml::twips(Xml::attr($spacing, 'line')),
            lineRule: Xml::attr($spacing, 'line') === null ? null : (Xml::attr($spacing, 'lineRule') ?? 'auto'),
            contextualSpacing: Xml::onOff($pPr, 'contextualSpacing'),
            keepNext: Xml::onOff($pPr, 'keepNext'),
            keepLines: Xml::onOff($pPr, 'keepLines'),
            pageBreakBefore: Xml::onOff($pPr, 'pageBreakBefore'),
            shading: $this->shading(Xml::child($pPr, 'shd')),
            borders: $this->borders(Xml::child($pPr, 'pBdr'), ['top', 'left', 'bottom', 'right', 'between']),
            numId: $numPr === null ? null : (Xml::val($numPr, 'numId') ?? null),
            level: $numPr === null ? null : Xml::int(Xml::val($numPr, 'ilvl')),
            outlineLevel: $outline !== null && $outline >= 0 && $outline <= 8 ? $outline : null,
            bidi: Xml::onOff($pPr, 'bidi'),
            mark: $this->run(Xml::child($pPr, 'rPr')),
        );
    }

    public function table(?Element $tblPr): TableFormat
    {
        if ($tblPr === null) {
            return new TableFormat();
        }

        return new TableFormat(
            styleId: Xml::val($tblPr, 'tblStyle'),
            width: self::width(Xml::child($tblPr, 'tblW')),
            alignment: match (Xml::val($tblPr, 'jc')) {
                'center' => 'center',
                'right', 'end' => 'right',
                'left', 'start' => 'left',
                default => null,
            },
            indent: self::width(Xml::child($tblPr, 'tblInd'))[0] ?? null,
            borders: $this->borders(Xml::child($tblPr, 'tblBorders'), ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']),
            cellMargins: self::margins(Xml::child($tblPr, 'tblCellMar')),
            shading: $this->shading(Xml::child($tblPr, 'shd')),
            bidi: Xml::onOff($tblPr, 'bidiVisual'),
            rowBandSize: Xml::int(Xml::val($tblPr, 'tblStyleRowBandSize')),
            columnBandSize: Xml::int(Xml::val($tblPr, 'tblStyleColBandSize')),
        );
    }

    public function cell(?Element $tcPr): CellFormat
    {
        if ($tcPr === null) {
            return new CellFormat();
        }

        $vMerge = Xml::child($tcPr, 'vMerge');
        $hMerge = Xml::child($tcPr, 'hMerge');

        return new CellFormat(
            width: self::width(Xml::child($tcPr, 'tcW')),
            gridSpan: Xml::int(Xml::val($tcPr, 'gridSpan')),
            verticalMerge: $vMerge === null ? null : (Xml::attr($vMerge, 'val') === 'restart' ? 'restart' : 'continue'),
            horizontalMerge: $hMerge === null ? null : (Xml::attr($hMerge, 'val') === 'restart' ? 'restart' : 'continue'),
            borders: $this->borders(Xml::child($tcPr, 'tcBorders'), ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']),
            shading: $this->shading(Xml::child($tcPr, 'shd')),
            verticalAlign: match (Xml::val($tcPr, 'vAlign')) {
                'center' => 'center',
                'bottom' => 'bottom',
                'top' => 'top',
                default => null,
            },
            margins: self::margins(Xml::child($tcPr, 'tcMar')),
            noWrap: Xml::onOff($tcPr, 'noWrap'),
        );
    }

    public function row(?Element $trPr): RowFormat
    {
        if ($trPr === null) {
            return new RowFormat();
        }

        $height = Xml::child($trPr, 'trHeight');

        return new RowFormat(
            height: Xml::twips(Xml::attr($height, 'val')),
            heightRule: $height === null ? null : (Xml::attr($height, 'hRule') ?? 'atLeast'),
            header: Xml::onOff($trPr, 'tblHeader'),
            gridBefore: Xml::int(Xml::val($trPr, 'gridBefore')),
            gridAfter: Xml::int(Xml::val($trPr, 'gridAfter')),
            deleted: Xml::child($trPr, 'del') !== null ? true : null,
        );
    }

    /**
     * @param  list<string>  $sides
     * @return array<string, Border>
     */
    public function borders(?Element $container, array $sides): array
    {
        $borders = [];

        foreach ($sides as $side) {
            $element = Xml::child($container, $side)
                ?? Xml::child($container, match ($side) {
                    'left' => 'start',
                    'right' => 'end',
                    default => $side,
                });

            if ($element === null) {
                continue;
            }

            $style = (string) Xml::attr($element, 'val');

            $borders[$side] = in_array($style, ['nil', 'none', ''], true)
                ? new Border('none', 0, 'auto')
                : new Border(
                    style: $style,
                    size: max(2, Xml::eighthPoints(Xml::attr($element, 'sz')) ?? 2),
                    color: $this->color($element, 'color') ?? 'auto',
                    space: max(0, Xml::int(Xml::attr($element, 'space')) ?? 0),
                );
        }

        return $borders;
    }

    /** A colour attribute with its themeColor/themeTint/themeShade companions: RRGGBB, "auto" or null. */
    public function color(?Element $element, string $attribute = 'val'): ?string
    {
        if ($element === null) {
            return null;
        }

        $themed = $this->theme->color(Xml::attr($element, 'themeColor'), Xml::attr($element, 'themeTint'), Xml::attr($element, 'themeShade'));

        if ($themed !== null) {
            return $themed;
        }

        $value = Xml::attr($element, $attribute);

        return match (true) {
            $value === null => null,
            strtolower($value) === 'auto' => 'auto',
            preg_match('/^[0-9a-f]{6}$/i', $value) === 1 => strtoupper($value),
            default => null,
        };
    }

    /** `w:shd`: the fill colour, or the pattern colour of a solid pattern; "auto" for no fill. */
    public function shading(?Element $shd): ?string
    {
        if ($shd === null) {
            return null;
        }

        $pattern = (string) Xml::attr($shd, 'val');

        if ($pattern === 'nil') {
            return 'auto';
        }

        if ($pattern === 'solid') {
            $color = $this->theme->color(Xml::attr($shd, 'themeColor'), Xml::attr($shd, 'themeTint'), Xml::attr($shd, 'themeShade'))
                ?? self::hex(Xml::attr($shd, 'color'));

            return $color ?? '000000';
        }

        return $this->theme->color(Xml::attr($shd, 'themeFill'), Xml::attr($shd, 'themeFillTint'), Xml::attr($shd, 'themeFillShade'))
            ?? self::hex(Xml::attr($shd, 'fill'))
            ?? 'auto';
    }

    private function font(?Element $rFonts): ?string
    {
        if ($rFonts === null) {
            return null;
        }

        foreach (['hAnsi', 'ascii'] as $slot) {
            $font = $this->theme->font(Xml::attr($rFonts, $slot . 'Theme')) ?? Xml::attr($rFonts, $slot);
            // No real font is named with these; a document that uses them is trying to break out of CSS.
            $font = trim(preg_replace('/[\x00-\x1F\x7F;{}()<>]/', '', (string) $font));

            if ($font !== '') {
                return $font;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    public static function width(?Element $element): ?array
    {
        if ($element === null) {
            return null;
        }

        $type = Xml::attr($element, 'type') ?? 'dxa';
        $raw = (string) Xml::attr($element, 'w');

        if (str_ends_with($raw, '%')) {
            return [(int) round((float) rtrim($raw, '%') * 50), 'pct'];
        }

        return [$type === 'pct' ? (Xml::int($raw) ?? 0) : (Xml::twips($raw) ?? 0), $type];
    }

    /**
     * @return array<string, int>
     */
    private static function margins(?Element $container): array
    {
        $margins = [];

        foreach (['top' => 'top', 'left' => 'left', 'bottom' => 'bottom', 'right' => 'right', 'start' => 'left', 'end' => 'right'] as $name => $side) {
            $width = self::width(Xml::child($container, $name));

            if ($width !== null && $width[1] !== 'pct') {
                $margins[$side] ??= $width[0];
            }
        }

        return $margins;
    }

    private static function hex(?string $value): ?string
    {
        return $value !== null && preg_match('/^[0-9a-f]{6}$/i', $value) === 1 ? strtoupper($value) : null;
    }

    private static function lines(?string $hundredths): ?int
    {
        $value = Xml::int($hundredths);

        return $value === null ? null : (int) round($value * 240 / 100);
    }

    private static function attributeOnOff(?Element $element, string $name): ?bool
    {
        $value = Xml::attr($element, $name);

        return $value === null ? null : ! in_array(strtolower($value), ['false', '0', 'off'], true);
    }
}
