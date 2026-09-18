<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

use Kovami\HtmlDocx\Docx\Namespaces;
use Kovami\HtmlDocx\Model\ListDefinition;

/** Writes word/numbering.xml: one abstract definition and one instance per list. */
final class NumberingPart
{
    /**
     * @param  list<ListDefinition>  $lists
     */
    public static function toXml(array $lists): string
    {
        $xml = new XmlBuilder();
        $xml->open('w:numbering', ['xmlns:w' => Namespaces::W]);

        foreach ($lists as $list) {
            $xml->open('w:abstractNum', ['w:abstractNumId' => $list->numId])
                ->leaf('w:multiLevelType', ['w:val' => 'hybridMultilevel']);

            foreach ($list->levels as $level) {
                $xml->open('w:lvl', ['w:ilvl' => $level->level])
                    ->leaf('w:start', ['w:val' => $level->start])
                    ->leaf('w:numFmt', ['w:val' => $level->format])
                    ->leaf('w:lvlText', ['w:val' => $level->text])
                    ->leaf('w:lvlJc', ['w:val' => 'left'])
                    ->open('w:pPr')
                    ->leaf('w:ind', ['w:left' => $level->indentLeft, 'w:hanging' => $level->hanging])
                    ->close()
                    ->close();
            }

            $xml->close();
        }

        foreach ($lists as $list) {
            $xml->open('w:num', ['w:numId' => $list->numId])
                ->leaf('w:abstractNumId', ['w:val' => $list->numId])
                ->open('w:lvlOverride', ['w:ilvl' => $list->startLevel])
                ->leaf('w:startOverride', ['w:val' => $list->levels[$list->startLevel]->start])
                ->close()
                ->close();
        }

        return $xml->close()->toString();
    }
}
