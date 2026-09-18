<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

use Kovami\HtmlDocx\Model\Border;
use Kovami\HtmlDocx\Model\BorderSet;
use Kovami\HtmlDocx\Model\ParagraphProperties;
use Kovami\HtmlDocx\Model\RunProperties;

/**
 * Serializes pPr/rPr. Child elements follow the sequence order of the
 * WordprocessingML schema (CT_PPrBase, CT_RPr): Word treats out-of-order
 * elements as a corrupt document.
 */
final class PropertiesWriter
{
    public static function run(XmlBuilder $xml, RunProperties $properties, ?string $language = null): void
    {
        if ($properties->isEmpty() && $language === null) {
            return;
        }

        $xml->open('w:rPr');

        if ($properties->fontFamily !== null) {
            $font = $properties->fontFamily;
            $xml->leaf('w:rFonts', ['w:ascii' => $font, 'w:hAnsi' => $font, 'w:eastAsia' => $font, 'w:cs' => $font]);
        }

        self::toggle($xml, 'w:b', $properties->bold);
        self::toggle($xml, 'w:bCs', $properties->bold);
        self::toggle($xml, 'w:i', $properties->italic);
        self::toggle($xml, 'w:iCs', $properties->italic);
        self::toggle($xml, 'w:caps', $properties->caps);
        self::toggle($xml, 'w:smallCaps', $properties->smallCaps);
        self::toggle($xml, 'w:strike', $properties->strike);
        self::toggle($xml, 'w:shadow', $properties->shadow);

        if ($properties->color !== null) {
            $xml->leaf('w:color', ['w:val' => $properties->color]);
        }

        if ($properties->spacing !== null) {
            $xml->leaf('w:spacing', ['w:val' => $properties->spacing]);
        }

        if ($properties->size !== null) {
            $xml->leaf('w:sz', ['w:val' => $properties->size]);
            $xml->leaf('w:szCs', ['w:val' => $properties->size]);
        }

        if ($properties->underline !== null) {
            $xml->leaf('w:u', ['w:val' => $properties->underline]);
        }

        if ($properties->shading !== null) {
            self::shading($xml, $properties->shading);
        }

        if ($properties->verticalAlign !== null) {
            $xml->leaf('w:vertAlign', ['w:val' => $properties->verticalAlign]);
        }

        self::toggle($xml, 'w:rtl', $properties->rtl);

        if ($language !== null) {
            $xml->leaf('w:lang', ['w:val' => $language]);
        }

        $xml->close();
    }

    /**
     * @param  RunProperties|null  $markBase  formatting the paragraph mark inherits; null omits the mark's rPr
     */
    public static function paragraph(XmlBuilder $xml, ParagraphProperties $properties, ?RunProperties $markBase): void
    {
        $xml->open('w:pPr');

        if ($properties->styleId !== null) {
            $xml->leaf('w:pStyle', ['w:val' => $properties->styleId]);
        }

        if ($properties->keepNext) {
            $xml->leaf('w:keepNext');
        }

        if ($properties->keepLines) {
            $xml->leaf('w:keepLines');
        }

        if ($properties->pageBreakBefore) {
            $xml->leaf('w:pageBreakBefore');
        }

        if ($properties->numbering !== null) {
            $xml->open('w:numPr')
                ->leaf('w:ilvl', ['w:val' => $properties->numbering->level])
                ->leaf('w:numId', ['w:val' => $properties->numbering->numId])
                ->close();
        }

        self::borders($xml, 'w:pBdr', $properties->borders, ['top', 'left', 'bottom', 'right']);

        if ($properties->shading !== null) {
            self::shading($xml, $properties->shading);
        }

        if ($properties->bidi) {
            $xml->leaf('w:bidi');
        }

        if ($properties->spacingBefore !== null || $properties->spacingAfter !== null || $properties->lineSpacing !== null) {
            $xml->leaf('w:spacing', [
                'w:before' => $properties->spacingBefore,
                'w:after' => $properties->spacingAfter,
                'w:line' => $properties->lineSpacing,
                'w:lineRule' => $properties->lineSpacing === null ? null : $properties->lineRule,
            ]);
        }

        if ($properties->indentLeft !== 0 || $properties->indentRight !== 0 || $properties->firstLine !== 0) {
            $xml->leaf('w:ind', [
                'w:left' => $properties->indentLeft !== 0 ? $properties->indentLeft : null,
                'w:right' => $properties->indentRight !== 0 ? $properties->indentRight : null,
                'w:firstLine' => $properties->firstLine > 0 ? $properties->firstLine : null,
                'w:hanging' => $properties->firstLine < 0 ? -$properties->firstLine : null,
            ]);
        }

        if ($properties->alignment !== null) {
            $xml->leaf('w:jc', ['w:val' => $properties->alignment]);
        }

        if ($properties->outlineLevel !== null) {
            $xml->leaf('w:outlineLvl', ['w:val' => $properties->outlineLevel]);
        }

        if ($markBase !== null && $properties->markRunProperties !== null) {
            self::run($xml, $properties->markRunProperties->relativeTo($markBase));
        }

        $xml->close();
    }

    /**
     * @param  list<string>  $sides  in schema order
     */
    public static function borders(XmlBuilder $xml, string $element, BorderSet $borders, array $sides): void
    {
        $present = array_filter($sides, static fn(string $side): bool => self::side($borders, $side) !== null);

        if ($present === []) {
            return;
        }

        $xml->open($element);

        foreach ($present as $side) {
            self::border($xml, 'w:' . $side, self::side($borders, $side));
        }

        $xml->close();
    }

    public static function shading(XmlBuilder $xml, string $fill): void
    {
        $xml->leaf('w:shd', ['w:val' => 'clear', 'w:color' => 'auto', 'w:fill' => $fill]);
    }

    private static function border(XmlBuilder $xml, string $name, ?Border $border): void
    {
        if ($border === null) {
            return;
        }

        $xml->leaf($name, [
            'w:val' => $border->style,
            'w:sz' => $border->size,
            'w:space' => $border->space,
            'w:color' => $border->color,
        ]);
    }

    private static function side(BorderSet $borders, string $side): ?Border
    {
        return match ($side) {
            'top' => $borders->top,
            'left' => $borders->left,
            'bottom' => $borders->bottom,
            'right' => $borders->right,
            'insideH' => $borders->insideHorizontal,
            'insideV' => $borders->insideVertical,
            default => null,
        };
    }

    private static function toggle(XmlBuilder $xml, string $name, ?bool $value): void
    {
        if ($value !== null) {
            $xml->leaf($name, $value ? [] : ['w:val' => '0']);
        }
    }
}
