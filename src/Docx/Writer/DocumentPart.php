<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

use DateTimeZone;
use Kovami\HtmlDocx\Docx\Namespaces;
use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\Bookmark;
use Kovami\HtmlDocx\Model\BreakRun;
use Kovami\HtmlDocx\Model\Comment;
use Kovami\HtmlDocx\Model\CommentEnd;
use Kovami\HtmlDocx\Model\CommentStart;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Model\Field;
use Kovami\HtmlDocx\Model\Formula;
use Kovami\HtmlDocx\Model\HeaderFooter;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\ImageRun;
use Kovami\HtmlDocx\Model\Inline;
use Kovami\HtmlDocx\Model\Note;
use Kovami\HtmlDocx\Model\NoteReference;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\RunProperties;
use Kovami\HtmlDocx\Model\Table;
use Kovami\HtmlDocx\Model\TableCell;
use Kovami\HtmlDocx\Model\TabRun;
use Kovami\HtmlDocx\Model\TextRun;

/**
 * Writes word/document.xml and the parts that hold content beside it:
 * footnotes, endnotes, headers, footers and comments. One instance writes
 * one part, with that part's relationships.
 */
final class DocumentPart
{
    /** Word's ids for the rules drawn above notes; real notes are numbered from 1. */
    private const int SEPARATOR_ID = -1;

    private const int CONTINUATION_ID = 0;

    /** The namespaces content may use, declared on every part's root. */
    private const array NAMESPACES = [
        'xmlns:w' => Namespaces::W,
        'xmlns:r' => Namespaces::R,
        'xmlns:wp' => Namespaces::WP,
        'xmlns:a' => Namespaces::A,
        'xmlns:pic' => Namespaces::PIC,
        'xmlns:m' => Namespaces::M,
    ];

    /**
     * @param  list<array{kind: string, type: string, id: string}>  $headersFooters  header and footer parts by relationship id
     */
    public function __construct(
        private readonly Document $document,
        private readonly Relationships $relationships,
        private readonly MediaRegistry $media,
        private readonly array $headersFooters = [],
    ) {}

    public function toXml(): string
    {
        $xml = new XmlBuilder();
        $xml->open('w:document', self::NAMESPACES);
        $xml->open('w:body');

        foreach ($this->document->blocks as $block) {
            $this->block($xml, $block);
        }

        $this->sectionProperties($xml);

        return $xml->close()->close()->toString();
    }

    /**
     * word/footnotes.xml or word/endnotes.xml; null when the document has
     * no notes of that kind.
     *
     * @param  string  $type  {@see Note::FOOTNOTE} or {@see Note::ENDNOTE}
     */
    public function notesXml(string $type): ?string
    {
        $notes = array_values(array_filter($this->document->notes, static fn(Note $note): bool => $note->type === $type));

        if ($notes === []) {
            return null;
        }

        $isEndnote = $type === Note::ENDNOTE;
        $element = $isEndnote ? 'w:endnote' : 'w:footnote';

        $xml = new XmlBuilder();
        $xml->open($isEndnote ? 'w:endnotes' : 'w:footnotes', self::NAMESPACES);

        // The rules Word draws above notes and above their continuation.
        foreach ([self::SEPARATOR_ID => 'separator', self::CONTINUATION_ID => 'continuationSeparator'] as $id => $special) {
            $xml->open($element, ['w:type' => $special, 'w:id' => $id])
                ->open('w:p')
                ->open('w:pPr')->leaf('w:spacing', ['w:after' => 0, 'w:line' => 240, 'w:lineRule' => 'auto'])->close()
                ->open('w:r')->leaf('w:' . $special)->close()
                ->close()
                ->close();
        }

        foreach ($notes as $note) {
            $xml->open($element, ['w:id' => $note->number]);
            $mark = $isEndnote ? 'w:endnoteRef' : 'w:footnoteRef';
            $first = true;

            foreach ($note->blocks as $block) {
                if ($first && $block instanceof Paragraph) {
                    // The note's own number, which Word renders from this element.
                    $this->paragraph($xml, $block, fn(): XmlBuilder => $xml->open('w:r')->leaf($mark)->close());
                    $first = false;

                    continue;
                }

                $first = false;
                $this->block($xml, $block);
            }

            if ($note->blocks === []) {
                $this->paragraph($xml, new Paragraph(), fn(): XmlBuilder => $xml->open('w:r')->leaf($mark)->close());
            }

            $xml->close();
        }

        return $xml->close()->toString();
    }

    /** word/headerN.xml or word/footerN.xml. */
    public function headerFooterXml(HeaderFooter $headerFooter): string
    {
        $xml = new XmlBuilder();
        $xml->open($headerFooter->kind === HeaderFooter::HEADER ? 'w:hdr' : 'w:ftr', self::NAMESPACES);

        foreach (self::endingInParagraph($headerFooter->blocks) as $block) {
            $this->block($xml, $block);
        }

        return $xml->close()->toString();
    }

    /**
     * word/comments.xml; null when the document has no comments. Threads and
     * the done state need Word 2013's commentsExtended part, which finds a
     * comment by the w14:paraId of its last paragraph.
     */
    public function commentsXml(bool $withParagraphIds): ?string
    {
        if ($this->document->comments === []) {
            return null;
        }

        $root = self::NAMESPACES;

        if ($withParagraphIds) {
            $root += ['xmlns:mc' => Namespaces::MC, 'xmlns:w14' => Namespaces::W14, 'mc:Ignorable' => 'w14'];
        }

        $xml = new XmlBuilder();
        $xml->open('w:comments', $root);

        foreach ($this->document->comments as $comment) {
            $xml->open('w:comment', [
                'w:id' => $comment->id,
                'w:author' => $comment->author ?? '',
                'w:date' => $comment->date?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'w:initials' => $comment->initials,
            ]);

            $blocks = self::endingInParagraph($comment->blocks);
            $last = count($blocks) - 1;

            foreach ($blocks as $index => $block) {
                if (! $block instanceof Paragraph) {
                    $this->block($xml, $block);

                    continue;
                }

                $this->paragraph(
                    $xml,
                    $block,
                    // The mark Word draws where the comment begins.
                    $index === 0 ? fn(): XmlBuilder => $xml->open('w:r')->leaf('w:annotationRef')->close() : null,
                    $withParagraphIds && $index === $last ? ['w14:paraId' => self::paragraphId($comment), 'w14:textId' => '77777777'] : [],
                );
            }

            $xml->close();
        }

        return $xml->close()->toString();
    }

    /**
     * word/commentsExtended.xml; null when no comment replies to another
     * or is marked done, which is all this part says.
     */
    public static function commentsExtendedXml(Document $document): ?string
    {
        $needed = array_filter($document->comments, static fn(Comment $comment): bool => $comment->parentId !== null || $comment->resolved);

        if ($needed === []) {
            return null;
        }

        $byId = [];

        foreach ($document->comments as $comment) {
            $byId[$comment->id] = $comment;
        }

        $xml = new XmlBuilder();
        $xml->open('w15:commentsEx', ['xmlns:mc' => Namespaces::MC, 'xmlns:w15' => Namespaces::W15, 'mc:Ignorable' => 'w15']);

        foreach ($document->comments as $comment) {
            $parent = $comment->parentId === null ? null : ($byId[$comment->parentId] ?? null);

            $xml->leaf('w15:commentEx', [
                'w15:paraId' => self::paragraphId($comment),
                'w15:paraIdParent' => $parent === null ? null : self::paragraphId($parent),
                'w15:done' => $comment->resolved ? '1' : '0',
            ]);
        }

        return $xml->close()->toString();
    }

    /** A w14:paraId: eight hex digits below 0x80000000, unique in the package. */
    private static function paragraphId(Comment $comment): string
    {
        return sprintf('%08X', 0x10000000 + $comment->id);
    }

    /**
     * Word wants a paragraph after a table that closes a header, footer or comment.
     *
     * @param  list<Block>  $blocks
     * @return non-empty-list<Block>
     */
    private static function endingInParagraph(array $blocks): array
    {
        $last = $blocks === [] ? null : $blocks[count($blocks) - 1];

        return $last instanceof Paragraph ? $blocks : [...$blocks, new Paragraph()];
    }

    private function block(XmlBuilder $xml, Block $block): void
    {
        match (true) {
            $block instanceof Paragraph => $this->paragraph($xml, $block),
            $block instanceof Table => $this->table($xml, $block),
            default => null,
        };
    }

    /**
     * @param  callable(): mixed|null  $prefix  writes runs before the paragraph's own content
     * @param  array<string, string>  $attributes  of the w:p element
     */
    private function paragraph(XmlBuilder $xml, Paragraph $paragraph, ?callable $prefix = null, array $attributes = []): void
    {
        $base = $this->document->style($paragraph->properties->styleId)->run ?? $this->document->defaultRunProperties;

        $xml->open('w:p', $attributes);
        PropertiesWriter::paragraph($xml, $paragraph->properties, $base);

        if ($prefix !== null) {
            $prefix();
        }

        foreach ($paragraph->children as $inline) {
            $this->inline($xml, $inline, $base);
        }

        $xml->close();
    }

    private function inline(XmlBuilder $xml, Inline $inline, RunProperties $base): void
    {
        match (true) {
            $inline instanceof TextRun => $this->run($xml, $inline->properties, $base, fn() => $xml->text('w:t', $inline->text, ['xml:space' => 'preserve'])),
            $inline instanceof BreakRun => $this->run($xml, $inline->properties, $base, fn() => $xml->leaf('w:br', ['w:type' => $inline->type === BreakRun::PAGE ? BreakRun::PAGE : null])),
            $inline instanceof TabRun => $this->run($xml, $inline->properties, $base, fn() => $xml->leaf('w:tab')),
            $inline instanceof Formula => $this->formula($xml, $inline, $base),
            $inline instanceof ImageRun => $this->run($xml, $inline->properties, $base, fn() => $this->drawing($xml, $inline)),
            $inline instanceof NoteReference => $this->run($xml, $inline->properties, $base, fn() => $xml->leaf(
                $inline->type === Note::ENDNOTE ? 'w:endnoteReference' : 'w:footnoteReference',
                ['w:id' => $inline->number],
            )),
            $inline instanceof Hyperlink => $this->hyperlink($xml, $inline, $base),
            $inline instanceof Bookmark => $xml
                ->leaf('w:bookmarkStart', ['w:id' => $inline->id, 'w:name' => $inline->name])
                ->leaf('w:bookmarkEnd', ['w:id' => $inline->id]),
            $inline instanceof Field => $this->field($xml, $inline, $base),
            $inline instanceof CommentStart => $xml->leaf('w:commentRangeStart', ['w:id' => $inline->id]),
            // The reference run is what ties the range to the comment's text.
            $inline instanceof CommentEnd => $xml
                ->leaf('w:commentRangeEnd', ['w:id' => $inline->id])
                ->open('w:r')->leaf('w:commentReference', ['w:id' => $inline->id])->close(),
            default => null,
        };
    }

    /** A field Word recomputes on layout, showing its last value until then. */
    private function field(XmlBuilder $xml, Field $field, RunProperties $base): void
    {
        $xml->open('w:fldSimple', ['w:instr' => " {$field->name} "]);
        $this->run($xml, $field->properties, $base, fn() => $xml->text('w:t', $field->result, ['xml:space' => 'preserve']));
        $xml->close();
    }

    /** Office Math, so Word lays the formula out and can edit it. */
    private function formula(XmlBuilder $xml, Formula $formula, RunProperties $base): void
    {
        if ($formula->display) {
            $xml->open('m:oMathPara');
        }

        $xml->open('m:oMath');
        LatexToOmml::write($xml, $formula->latex, $formula->properties->relativeTo($base));
        $xml->close();

        if ($formula->display) {
            $xml->close();
        }
    }

    private function run(XmlBuilder $xml, RunProperties $properties, RunProperties $base, callable $content): void
    {
        $xml->open('w:r');
        PropertiesWriter::run($xml, $properties->relativeTo($base));
        $content();
        $xml->close();
    }

    private function hyperlink(XmlBuilder $xml, Hyperlink $hyperlink, RunProperties $base): void
    {
        $xml->open('w:hyperlink', [
            'r:id' => $hyperlink->url === null ? null : $this->relationships->add(Relationships::HYPERLINK, $hyperlink->url, external: true),
            'w:anchor' => $hyperlink->anchor,
            'w:history' => '1',
        ]);

        foreach ($hyperlink->children as $child) {
            $this->inline($xml, $child, $base);
        }

        $xml->close();
    }

    private function drawing(XmlBuilder $xml, ImageRun $image): void
    {
        $id = $this->media->nextDrawingId();
        $relationshipId = $this->media->register($image->image, $this->relationships);
        $name = "Picture {$id}";

        $xml->open('w:drawing');

        if ($image->float === null) {
            $xml->open('wp:inline', ['distT' => 0, 'distB' => 0, 'distL' => 0, 'distR' => 0]);
        } else {
            // Floated: anchored to its paragraph at one side of the column, the
            // text wrapping around it an eighth of an inch away.
            $xml->open('wp:anchor', [
                'distT' => 0, 'distB' => 0, 'distL' => 114300, 'distR' => 114300, 'simplePos' => 0, 'relativeHeight' => $id,
                'behindDoc' => 0, 'locked' => 0, 'layoutInCell' => 1, 'allowOverlap' => 1,
            ])
                ->leaf('wp:simplePos', ['x' => 0, 'y' => 0])
                ->open('wp:positionH', ['relativeFrom' => 'column'])->text('wp:align', $image->float)->close()
                ->open('wp:positionV', ['relativeFrom' => 'paragraph'])->text('wp:posOffset', '0')->close();
        }

        $xml->leaf('wp:extent', ['cx' => $image->width, 'cy' => $image->height])
            ->leaf('wp:effectExtent', ['l' => 0, 't' => 0, 'r' => 0, 'b' => 0]);

        if ($image->float !== null) {
            $xml->leaf('wp:wrapSquare', ['wrapText' => 'bothSides']);
        }

        $xml->leaf('wp:docPr', ['id' => $id, 'name' => $name, 'descr' => $image->description === '' ? null : $image->description])
            ->open('wp:cNvGraphicFramePr')->leaf('a:graphicFrameLocks', ['noChangeAspect' => 1])->close()
            ->open('a:graphic')
            ->open('a:graphicData', ['uri' => Namespaces::PIC])
            ->open('pic:pic')
            ->open('pic:nvPicPr')->leaf('pic:cNvPr', ['id' => $id, 'name' => $name])->leaf('pic:cNvPicPr')->close()
            ->open('pic:blipFill')->leaf('a:blip', ['r:embed' => $relationshipId])->open('a:stretch')->leaf('a:fillRect')->close()->close()
            ->open('pic:spPr')
            ->open('a:xfrm')->leaf('a:off', ['x' => 0, 'y' => 0])->leaf('a:ext', ['cx' => $image->width, 'cy' => $image->height])->close()
            ->open('a:prstGeom', ['prst' => 'rect'])->leaf('a:avLst')->close()
            ->close()
            ->close()
            ->close()
            ->close()
            ->close()
            ->close();
    }

    private function table(XmlBuilder $xml, Table $table): void
    {
        $properties = $table->properties;

        $xml->open('w:tbl')->open('w:tblPr');

        if ($properties->bidi) {
            $xml->leaf('w:bidiVisual');
        }

        $xml->leaf('w:tblW', ['w:w' => $properties->width, 'w:type' => 'dxa']);

        if ($properties->alignment !== null) {
            $xml->leaf('w:jc', ['w:val' => $properties->alignment]);
        }

        if ($properties->indentLeft !== 0 && $properties->alignment === null) {
            $xml->leaf('w:tblInd', ['w:w' => $properties->indentLeft, 'w:type' => 'dxa']);
        }

        PropertiesWriter::borders($xml, 'w:tblBorders', $properties->borders, ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']);

        if ($properties->shading !== null) {
            PropertiesWriter::shading($xml, $properties->shading);
        }

        $xml->leaf('w:tblLayout', ['w:type' => 'fixed'])
            ->leaf('w:tblLook', ['w:val' => '04A0', 'w:firstRow' => 1, 'w:lastRow' => 0, 'w:firstColumn' => 1, 'w:lastColumn' => 0, 'w:noHBand' => 0, 'w:noVBand' => 1])
            ->close();

        $xml->open('w:tblGrid');

        foreach ($table->gridColumns as $width) {
            $xml->leaf('w:gridCol', ['w:w' => $width]);
        }

        $xml->close();

        foreach ($table->rows as $row) {
            $xml->open('w:tr');

            if ($row->isHeader || $row->minHeight !== null) {
                $xml->open('w:trPr');

                if ($row->minHeight !== null) {
                    $xml->leaf('w:trHeight', ['w:val' => $row->minHeight, 'w:hRule' => 'atLeast']);
                }

                if ($row->isHeader) {
                    $xml->leaf('w:tblHeader');
                }

                $xml->close();
            }

            foreach ($row->cells as $cell) {
                $this->cell($xml, $cell);
            }

            $xml->close();
        }

        $xml->close();
    }

    private function cell(XmlBuilder $xml, TableCell $cell): void
    {
        $properties = $cell->properties;

        $xml->open('w:tc')->open('w:tcPr');
        $xml->leaf('w:tcW', ['w:w' => $properties->width, 'w:type' => 'dxa']);

        if ($properties->gridSpan > 1) {
            $xml->leaf('w:gridSpan', ['w:val' => $properties->gridSpan]);
        }

        if ($properties->verticalMerge !== null) {
            $xml->leaf('w:vMerge', ['w:val' => $properties->verticalMerge]);
        }

        PropertiesWriter::borders($xml, 'w:tcBorders', $properties->borders, ['top', 'left', 'bottom', 'right']);

        if ($properties->shading !== null) {
            PropertiesWriter::shading($xml, $properties->shading);
        }

        if ($properties->noWrap) {
            $xml->leaf('w:noWrap');
        }

        if ($properties->margins !== null) {
            $xml->open('w:tcMar')
                ->leaf('w:top', ['w:w' => $properties->margins->top, 'w:type' => 'dxa'])
                ->leaf('w:left', ['w:w' => $properties->margins->left, 'w:type' => 'dxa'])
                ->leaf('w:bottom', ['w:w' => $properties->margins->bottom, 'w:type' => 'dxa'])
                ->leaf('w:right', ['w:w' => $properties->margins->right, 'w:type' => 'dxa'])
                ->close();
        }

        if ($properties->verticalAlign !== null) {
            $xml->leaf('w:vAlign', ['w:val' => $properties->verticalAlign]);
        }

        $xml->close();

        foreach ($cell->blocks as $block) {
            $this->block($xml, $block);
        }

        $xml->close();
    }

    private function sectionProperties(XmlBuilder $xml): void
    {
        $page = $this->document->pageLayout;

        $xml->open('w:sectPr');

        foreach ([HeaderFooter::HEADER, HeaderFooter::FOOTER] as $kind) {
            foreach ($this->headersFooters as $part) {
                if ($part['kind'] === $kind) {
                    $xml->leaf("w:{$kind}Reference", ['w:type' => $part['type'], 'r:id' => $part['id']]);
                }
            }
        }

        $xml->leaf('w:pgSz', [
            'w:w' => $page->widthTwips,
            'w:h' => $page->heightTwips,
            'w:orient' => $page->isLandscape() ? 'landscape' : null,
        ])
            ->leaf('w:pgMar', [
                'w:top' => $page->marginTopTwips,
                'w:right' => $page->marginRightTwips,
                'w:bottom' => $page->marginBottomTwips,
                'w:left' => $page->marginLeftTwips,
                'w:header' => 708,
                'w:footer' => 708,
                'w:gutter' => 0,
            ])
            ->leaf('w:cols', ['w:space' => 708]);

        foreach ($this->headersFooters as $part) {
            if ($part['type'] === HeaderFooter::FIRST) {
                $xml->leaf('w:titlePg');

                break;
            }
        }

        $xml->close();
    }
}
