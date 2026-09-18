<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

use Kovami\HtmlDocx\Docx\Namespaces;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Model\RunProperties;

/** Writes word/styles.xml. */
final class StylesPart
{
    public static function toXml(Document $document): string
    {
        $defaults = $document->defaultRunProperties;

        $xml = new XmlBuilder;
        $xml->open('w:styles', ['xmlns:w' => Namespaces::W]);

        $xml->open('w:docDefaults')->open('w:rPrDefault');
        PropertiesWriter::run($xml, new RunProperties(
            fontFamily: $defaults->fontFamily,
            size: $defaults->size,
            color: $defaults->color,
        ), $document->metadata->language);
        $xml->close()
            ->open('w:pPrDefault')->open('w:pPr')->leaf('w:spacing', ['w:after' => 0, 'w:line' => 240, 'w:lineRule' => 'auto'])->close()->close()
            ->close();

        foreach ($document->styles as $style) {
            $xml->open('w:style', [
                'w:type' => 'paragraph',
                'w:default' => $style->isDefault ? '1' : null,
                'w:styleId' => $style->id,
            ]);
            $xml->leaf('w:name', ['w:val' => $style->name]);

            if ($style->basedOn !== null) {
                $xml->leaf('w:basedOn', ['w:val' => $style->basedOn]);
            }

            if ($style->paragraph->outlineLevel !== null) {
                $xml->leaf('w:next', ['w:val' => 'Normal'])->leaf('w:uiPriority', ['w:val' => 9]);
            }

            $xml->leaf('w:qFormat');
            PropertiesWriter::paragraph($xml, $style->paragraph, null);
            PropertiesWriter::run($xml, $style->run->relativeTo($defaults));
            $xml->close();
        }

        $xml->open('w:style', ['w:type' => 'character', 'w:default' => '1', 'w:styleId' => 'DefaultParagraphFont'])
            ->leaf('w:name', ['w:val' => 'Default Paragraph Font'])->leaf('w:uiPriority', ['w:val' => 1])
            ->leaf('w:semiHidden')->leaf('w:unhideWhenUsed')
            ->close();

        $xml->open('w:style', ['w:type' => 'table', 'w:default' => '1', 'w:styleId' => 'TableNormal'])
            ->leaf('w:name', ['w:val' => 'Normal Table'])->leaf('w:uiPriority', ['w:val' => 99])
            ->leaf('w:semiHidden')->leaf('w:unhideWhenUsed')
            ->open('w:tblPr')
            ->leaf('w:tblInd', ['w:w' => 0, 'w:type' => 'dxa'])
            ->open('w:tblCellMar')
            ->leaf('w:top', ['w:w' => 0, 'w:type' => 'dxa'])
            ->leaf('w:left', ['w:w' => 108, 'w:type' => 'dxa'])
            ->leaf('w:bottom', ['w:w' => 0, 'w:type' => 'dxa'])
            ->leaf('w:right', ['w:w' => 108, 'w:type' => 'dxa'])
            ->close()
            ->close()
            ->close();

        $xml->open('w:style', ['w:type' => 'numbering', 'w:default' => '1', 'w:styleId' => 'NoList'])
            ->leaf('w:name', ['w:val' => 'No List'])->leaf('w:uiPriority', ['w:val' => 99])
            ->leaf('w:semiHidden')->leaf('w:unhideWhenUsed')
            ->close();

        return $xml->close()->toString();
    }
}
