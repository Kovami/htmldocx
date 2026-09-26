<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

use Kovami\HtmlDocx\Docx\Namespaces;
use Kovami\HtmlDocx\Model\ListDefinition;

/** Writes word/numbering.xml: one abstract definition and one instance per list. */
final class NumberingPart
{
    /**
     * Word's own bullets, drawn from symbol fonts: a list Word makes looks
     * like this, and so does one from HTML.
     */
    private const array WORD_BULLETS = [
        "\u{2022}" => ["\u{F0B7}", 'Symbol'],
        "\u{25E6}" => ['o', 'Courier New'],
        "\u{25AA}" => ["\u{F0A7}", 'Wingdings'],
    ];

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
                // A disc stays in the font it was in: Symbol's makes Word's line a little taller.
                [$text, $font] = $level->format === 'bullet' && ($level->symbolBullet || $level->text !== "\u{2022}") ? self::WORD_BULLETS[$level->text] ?? [$level->text, null] : [$level->text, null];

                $xml->open('w:lvl', ['w:ilvl' => $level->level])
                    ->leaf('w:start', ['w:val' => $level->start])
                    ->leaf('w:numFmt', ['w:val' => $level->format]);

                if ($level->suffix !== 'tab') {
                    $xml->leaf('w:suff', ['w:val' => $level->suffix]);
                }

                $xml->leaf('w:lvlText', ['w:val' => $text])
                    ->leaf('w:lvlJc', ['w:val' => $level->alignment])
                    ->open('w:pPr');

                if ($level->markerTab !== null) {
                    $xml->open('w:tabs')->leaf('w:tab', ['w:val' => 'num', 'w:pos' => $level->markerTab])->close();
                }

                $xml->leaf('w:ind', ['w:left' => $level->indentLeft, 'w:hanging' => $level->hanging])
                    ->close();

                if ($font !== null) {
                    $xml->open('w:rPr')
                        ->leaf('w:rFonts', ['w:ascii' => $font, 'w:hAnsi' => $font, 'w:hint' => 'default'])
                        ->close();
                }

                $xml->close();
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
