<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Tests\Support;

/**
 * A package written the way Microsoft Word writes one: a table using a style
 * from Word's gallery (with its conditional formatting), footnotes and
 * endnotes with Word's own reference styles, an Office Math equation and a
 * text box in both the modern and the fallback vocabulary.
 *
 * Word opens this document without repairing it and renders every part of
 * it, which is what makes it worth testing against.
 */
final class WordDocument
{
    public static function bytes(): string
    {
        return DocxBuilder::make()
            ->styles(self::styles())
            ->theme(self::theme())
            ->notes('footnote', self::note('footnote', 'Сноска, как её пишет Word.'))
            ->notes('endnote', self::note('endnote', 'Концевая сноска.'))
            ->body(self::paragraph() . self::table() . self::equation() . self::textBox())
            ->toBytes();
    }

    private static function styles(): string
    {
        return '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:asciiTheme="minorHAnsi" w:hAnsiTheme="minorHAnsi"/>'
            . '<w:sz w:val="22"/><w:lang w:val="ru-RU"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="259" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/>'
            . '<w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/>'
            . '<w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            . '<w:style w:type="table" w:styleId="GridTable4-Accent1"><w:name w:val="Grid Table 4 Accent 1"/>'
            . '<w:basedOn w:val="TableNormal"/><w:uiPriority w:val="49"/>'
            . '<w:tblPr><w:tblStyleRowBandSize w:val="1"/><w:tblStyleColBandSize w:val="1"/>' . self::borders('8EAADB') . '</w:tblPr>'
            . '<w:tblStylePr w:type="firstRow"><w:rPr><w:b/><w:color w:val="FFFFFF" w:themeColor="background1"/></w:rPr>'
            . '<w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="4472C4" w:themeFill="accent1"/></w:tcPr></w:tblStylePr>'
            . '<w:tblStylePr w:type="lastRow"><w:rPr><w:b/></w:rPr>'
            . '<w:tcPr><w:tcBorders><w:top w:val="double" w:sz="4" w:space="0" w:color="4472C4"/></w:tcBorders></w:tcPr></w:tblStylePr>'
            . '<w:tblStylePr w:type="firstCol"><w:rPr><w:b/></w:rPr></w:tblStylePr>'
            . '<w:tblStylePr w:type="band1Horz"><w:tcPr>'
            . '<w:shd w:val="clear" w:color="auto" w:fill="D9E2F3" w:themeFill="accent1" w:themeFillTint="33"/></w:tcPr></w:tblStylePr>'
            . '</w:style>'
            . '<w:style w:type="character" w:styleId="FootnoteReference"><w:name w:val="footnote reference"/>'
            . '<w:rPr><w:vertAlign w:val="superscript"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="FootnoteText"><w:name w:val="footnote text"/>'
            . '<w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:rPr><w:sz w:val="20"/></w:rPr></w:style>';
    }

    private static function theme(): string
    {
        return '<a:themeElements><a:clrScheme name="Office">'
            . '<a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
            . '<a:dk2><a:srgbClr val="44546A"/></a:dk2><a:lt2><a:srgbClr val="E7E6E6"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="4472C4"/></a:accent1><a:accent2><a:srgbClr val="ED7D31"/></a:accent2>'
            . '<a:accent3><a:srgbClr val="A5A5A5"/></a:accent3><a:accent4><a:srgbClr val="FFC000"/></a:accent4>'
            . '<a:accent5><a:srgbClr val="5B9BD5"/></a:accent5><a:accent6><a:srgbClr val="70AD47"/></a:accent6>'
            . '<a:hlink><a:srgbClr val="0563C1"/></a:hlink><a:folHlink><a:srgbClr val="954F72"/></a:folHlink></a:clrScheme>'
            . '<a:fontScheme name="Office"><a:majorFont><a:latin typeface="Calibri Light"/></a:majorFont>'
            . '<a:minorFont><a:latin typeface="Calibri"/></a:minorFont></a:fontScheme></a:themeElements>';
    }

    private static function borders(string $color): string
    {
        $sides = '';

        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) {
            $sides .= "<w:{$side} w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"{$color}\"/>";
        }

        return "<w:tblBorders>{$sides}</w:tblBorders>";
    }

    private static function paragraph(): string
    {
        $reference = static fn(string $type): string => '<w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr>'
            . "<w:{$type}Reference w:id=\"2\"/></w:r>";

        return '<w:p><w:r><w:t>Абзац со сноской</w:t></w:r>' . $reference('footnote')
            . '<w:r><w:t xml:space="preserve"> и концевой сноской</w:t></w:r>' . $reference('endnote')
            . '<w:r><w:t>.</w:t></w:r></w:p>';
    }

    private static function table(): string
    {
        $cell = static fn(string $text): string => '<w:tc><w:tcPr><w:tcW w:w="4675" w:type="dxa"/></w:tcPr>'
            . '<w:p><w:r><w:t>' . $text . '</w:t></w:r></w:p></w:tc>';

        return '<w:tbl><w:tblPr><w:tblStyle w:val="GridTable4-Accent1"/><w:tblW w:w="0" w:type="auto"/>'
            . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="1" w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="4675"/><w:gridCol w:w="4675"/></w:tblGrid>'
            . '<w:tr><w:trPr><w:tblHeader/></w:trPr>' . $cell('Показатель') . $cell('Значение') . '</w:tr>'
            . '<w:tr>' . $cell('Выручка') . $cell('1 000') . '</w:tr>'
            . '<w:tr>' . $cell('Затраты') . $cell('400') . '</w:tr>'
            . '<w:tr>' . $cell('Итого') . $cell('600') . '</w:tr>'
            . '</w:tbl><w:p/>';
    }

    private static function equation(): string
    {
        $square = static fn(string $base): string => '<m:sSup><m:e><m:r><m:t>' . $base . '</m:t></m:r></m:e>'
            . '<m:sup><m:r><m:t>2</m:t></m:r></m:sup></m:sSup>';

        return '<w:p><m:oMathPara><m:oMath>'
            . $square('a') . '<m:r><m:t>+</m:t></m:r>' . $square('b') . '<m:r><m:t>=</m:t></m:r>' . $square('c')
            . '</m:oMath></m:oMathPara></w:p>';
    }

    /** Word writes a text box twice: for readers that know DrawingML shapes, and for those that only know VML. */
    private static function textBox(): string
    {
        $content = '<w:txbxContent><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Текст в надписи</w:t></w:r></w:p></w:txbxContent>';

        return '<w:p><w:r><mc:AlternateContent>'
            . '<mc:Choice Requires="wps"><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="2286000" cy="762000"/><wp:docPr id="7" name="Надпись 1"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.microsoft.com/office/word/2010/wordprocessingShape">'
            . '<wps:wsp><wps:cNvSpPr txBox="1"/><wps:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="2286000" cy="762000"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></wps:spPr>'
            . '<wps:txbx>' . $content . '</wps:txbx><wps:bodyPr wrap="square"/></wps:wsp>'
            . '</a:graphicData></a:graphic></wp:inline></w:drawing></mc:Choice>'
            . '<mc:Fallback><w:pict><v:shape id="_x0000_s1026" type="#_x0000_t202" style="width:180pt;height:60pt">'
            . '<v:textbox>' . $content . '</v:textbox></v:shape></w:pict></mc:Fallback>'
            . '</mc:AlternateContent></w:r></w:p>';
    }

    /** The separators Word keeps at ids -1 and 0, then the note itself. */
    private static function note(string $type, string $text): string
    {
        $spacing = '<w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>';
        $notes = '';

        foreach ([-1 => 'separator', 0 => 'continuationSeparator'] as $id => $special) {
            $notes .= "<w:{$type} w:type=\"{$special}\" w:id=\"{$id}\"><w:p>{$spacing}<w:r><w:{$special}/></w:r></w:p></w:{$type}>";
        }

        return $notes . "<w:{$type} w:id=\"2\"><w:p><w:pPr><w:pStyle w:val=\"FootnoteText\"/></w:pPr>"
            . '<w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr>' . "<w:{$type}Ref/></w:r>"
            . '<w:r><w:t xml:space="preserve"> ' . $text . '</w:t></w:r></w:p>' . "</w:{$type}>";
    }
}
