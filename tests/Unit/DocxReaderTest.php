<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Exceptions\HtmlDocxException;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\Bookmark;
use Kovami\HtmlDocx\Model\Field;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\ImageRun;
use Kovami\HtmlDocx\Model\Inline;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\TextRun;
use Kovami\HtmlDocx\Options;
use Kovami\HtmlDocx\Tests\Support\DocxBuilder;
use Kovami\HtmlDocx\Tests\Support\TestImage;

/** The plain text of a block, links included. */
function blockText(Block $block): string
{
    if (! $block instanceof Paragraph) {
        return '';
    }

    $text = static function (array $inlines) use (&$text): string {
        $result = '';

        foreach ($inlines as $inline) {
            $result .= match (true) {
                $inline instanceof TextRun => $inline->text,
                $inline instanceof Field => $inline->result,
                $inline instanceof Hyperlink => $text($inline->children),
                default => '',
            };
        }

        return $result;
    };

    return $text($block->children);
}

/**
 * @param  list<Block>  $blocks
 * @return list<string>
 */
function blockTexts(array $blocks): array
{
    return array_map(blockText(...), $blocks);
}

function firstRun(Paragraph $paragraph): ?TextRun
{
    foreach ($paragraph->children as $inline) {
        if ($inline instanceof TextRun) {
            return $inline;
        }
    }

    return null;
}

function paragraphOf(array $blocks, string $text): Paragraph
{
    foreach ($blocks as $block) {
        if ($block instanceof Paragraph && blockText($block) === $text) {
            return $block;
        }
    }

    throw new RuntimeException("No paragraph reads \"{$text}\".");
}

it('reads paragraphs and their direct run formatting', function () {
    $document = DocxBuilder::make()->body(
        '<w:p><w:r><w:rPr><w:b/><w:i/><w:sz w:val="28"/><w:color w:val="FF0000"/></w:rPr><w:t xml:space="preserve">bold italic</w:t></w:r></w:p>'
        .'<w:p><w:r><w:t>plain</w:t></w:r></w:p>'
    )->read();

    $run = firstRun(paragraphOf($document->blocks, 'bold italic'));

    expect(blockTexts($document->blocks))->toBe(['bold italic', 'plain'])
        ->and($run?->properties->bold)->toBeTrue()
        ->and($run?->properties->italic)->toBeTrue()
        ->and($run?->properties->size)->toBe(28)
        ->and($run?->properties->color)->toBe('FF0000')
        ->and(firstRun(paragraphOf($document->blocks, 'plain'))?->properties->bold)->toBeFalse();
});

it('resolves a chain of styles, direct formatting winning over all of them', function () {
    $document = DocxBuilder::make()
        ->styles(
            '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Cambria"/><w:sz w:val="20"/></w:rPr></w:rPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:styleId="Base"><w:name w:val="Base"/><w:rPr><w:sz w:val="24"/><w:color w:val="112233"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Derived"><w:name w:val="Derived"/><w:basedOn w:val="Base"/><w:rPr><w:b/></w:rPr></w:style>'
        )
        ->body('<w:p><w:pPr><w:pStyle w:val="Derived"/></w:pPr><w:r><w:rPr><w:color w:val="00FF00"/></w:rPr><w:t>styled</w:t></w:r></w:p>')
        ->read();

    $run = firstRun(paragraphOf($document->blocks, 'styled'));

    expect($run?->properties->fontFamily)->toBe('Cambria')
        ->and($run?->properties->size)->toBe(24)
        ->and($run?->properties->bold)->toBeTrue()
        ->and($run?->properties->color)->toBe('00FF00');
});

it('treats bold and italic as toggles a run can switch off', function () {
    $document = DocxBuilder::make()
        ->styles('<w:style w:type="paragraph" w:styleId="Loud"><w:name w:val="Loud"/><w:rPr><w:b/><w:i/></w:rPr></w:style>')
        ->body(
            '<w:p><w:pPr><w:pStyle w:val="Loud"/></w:pPr><w:r><w:rPr><w:b w:val="0"/></w:rPr><w:t>quiet</w:t></w:r></w:p>'
        )
        ->read();

    $run = firstRun(paragraphOf($document->blocks, 'quiet'));

    expect($run?->properties->bold)->toBeFalse()
        ->and($run?->properties->italic)->toBeTrue();
});

it('resolves theme fonts and theme colours', function () {
    $document = DocxBuilder::make()
        ->theme(
            '<a:themeElements>'
            .'<a:clrScheme name="Test"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
            .'<a:dk2><a:srgbClr val="44546A"/></a:dk2><a:lt2><a:srgbClr val="E7E6E6"/></a:lt2>'
            .'<a:accent1><a:srgbClr val="4472C4"/></a:accent1><a:accent2><a:srgbClr val="ED7D31"/></a:accent2>'
            .'<a:accent3><a:srgbClr val="A5A5A5"/></a:accent3><a:accent4><a:srgbClr val="FFC000"/></a:accent4>'
            .'<a:accent5><a:srgbClr val="5B9BD5"/></a:accent5><a:accent6><a:srgbClr val="70AD47"/></a:accent6>'
            .'<a:hlink><a:srgbClr val="0563C1"/></a:hlink><a:folHlink><a:srgbClr val="954F72"/></a:folHlink></a:clrScheme>'
            .'<a:fontScheme name="Test"><a:majorFont><a:latin typeface="Georgia"/></a:majorFont><a:minorFont><a:latin typeface="Verdana"/></a:minorFont></a:fontScheme>'
            .'</a:themeElements>'
        )
        ->body(
            '<w:p><w:r><w:rPr><w:rFonts w:asciiTheme="minorHAnsi"/><w:color w:themeColor="accent1"/></w:rPr><w:t>minor</w:t></w:r></w:p>'
            .'<w:p><w:r><w:rPr><w:rFonts w:asciiTheme="majorHAnsi"/><w:color w:themeColor="accent2" w:themeTint="99"/></w:rPr><w:t>major</w:t></w:r></w:p>'
        )
        ->read();

    $minor = firstRun(paragraphOf($document->blocks, 'minor'));
    $major = firstRun(paragraphOf($document->blocks, 'major'));

    expect($minor?->properties->fontFamily)->toBe('Verdana')
        ->and($minor?->properties->color)->toBe('4472C4')
        ->and($major?->properties->fontFamily)->toBe('Georgia')
        // A tint lightens the theme colour instead of using it as it is.
        ->and($major?->properties->color)->not->toBe('ED7D31');
});

it('numbers paragraphs the way Word counts them', function () {
    $document = DocxBuilder::make()
        ->numbering(
            '<w:abstractNum w:abstractNumId="0">'
            .'<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>'
            .'<w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1.%2."/><w:pPr><w:ind w:left="1440" w:hanging="360"/></w:pPr></w:lvl>'
            .'</w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
        )
        ->body(
            '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>one</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>one one</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>one two</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>two</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>two one</w:t></w:r></w:p>'
        )
        ->read();

    $label = static fn (string $text): ?string => paragraphOf($document->blocks, $text)->properties->numbering?->label;

    expect($label('one'))->toBe('1.')
        ->and($label('one one'))->toBe('1.1.')
        ->and($label('one two'))->toBe('1.2.')
        ->and($label('two'))->toBe('2.')
        // The deeper level restarts once the level above it moves on.
        ->and($label('two one'))->toBe('2.1.')
        ->and(paragraphOf($document->blocks, 'one one')->properties->numbering?->level)->toBe(1)
        ->and(paragraphOf($document->blocks, 'one')->properties->indentLeft)->toBe(720);
});

it('honours a start override on a numbering instance', function () {
    $document = DocxBuilder::make()
        ->numbering(
            '<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="upperLetter"/><w:lvlText w:val="%1)"/></w:lvl></w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
            .'<w:num w:numId="2"><w:abstractNumId w:val="0"/><w:lvlOverride w:ilvl="0"><w:startOverride w:val="3"/></w:lvlOverride></w:num>'
        )
        ->body(
            '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>first</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr><w:r><w:t>restarted</w:t></w:r></w:p>'
        )
        ->read();

    expect(paragraphOf($document->blocks, 'first')->properties->numbering?->label)->toBe('A)')
        ->and(paragraphOf($document->blocks, 'restarted')->properties->numbering?->label)->toBe('C)');
});

it('gives a heading style its outline level', function () {
    $document = DocxBuilder::make()
        ->styles('<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/></w:style>')
        ->body('<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>section</w:t></w:r></w:p>')
        ->read();

    expect(paragraphOf($document->blocks, 'section')->properties->outlineLevel)->toBe(1);
});

it('keeps insertions, drops deletions and shows fields as their result', function () {
    $document = DocxBuilder::make()->body(
        '<w:p><w:ins w:id="1" w:author="A" w:date="2026-01-01T00:00:00Z"><w:r><w:t>kept </w:t></w:r></w:ins>'
        .'<w:del w:id="2" w:author="A" w:date="2026-01-01T00:00:00Z"><w:r><w:delText>gone </w:delText></w:r></w:del>'
        .'<w:r><w:t>plain</w:t></w:r></w:p>'
        .'<w:p><w:fldSimple w:instr=" PAGE "><w:r><w:t>7</w:t></w:r></w:fldSimple></w:p>'
    )->read();

    expect(blockTexts($document->blocks))->toBe(['kept plain', '7']);
});

it('reads hyperlinks, both external and to a bookmark', function () {
    $document = DocxBuilder::make()
        ->relationship('rId9', 'hyperlink', 'https://example.com/a', external: true)
        ->body(
            '<w:p><w:hyperlink r:id="rId9"><w:r><w:t>out</w:t></w:r></w:hyperlink></w:p>'
            .'<w:p><w:hyperlink w:anchor="target"><w:r><w:t>in</w:t></w:r></w:hyperlink></w:p>'
            .'<w:p><w:bookmarkStart w:id="1" w:name="target"/><w:bookmarkEnd w:id="1"/><w:r><w:t>here</w:t></w:r></w:p>'
            .'<w:p><w:bookmarkStart w:id="2" w:name="unused"/><w:bookmarkEnd w:id="2"/><w:r><w:t>elsewhere</w:t></w:r></w:p>'
        )
        ->read();

    $link = static function (string $text) use ($document): ?Hyperlink {
        foreach (paragraphOf($document->blocks, $text)->children as $inline) {
            if ($inline instanceof Hyperlink) {
                return $inline;
            }
        }

        return null;
    };

    $bookmarks = static fn (Paragraph $paragraph): array => array_values(array_filter(
        $paragraph->children,
        static fn (Inline $inline): bool => $inline instanceof Bookmark,
    ));

    expect($link('out')?->url)->toBe('https://example.com/a')
        ->and($link('in')?->anchor)->toBe('target')
        // Only bookmarks something points at are worth keeping.
        ->and($bookmarks(paragraphOf($document->blocks, 'here')))->toHaveCount(1)
        ->and($bookmarks(paragraphOf($document->blocks, 'elsewhere')))->toBe([]);
});

it('reads footnotes and endnotes from their own parts', function () {
    $document = DocxBuilder::make()
        ->notes('footnote',
            '<w:footnote w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:footnote>'
            .'<w:footnote w:id="1"><w:p><w:r><w:footnoteRef/></w:r><w:r><w:t xml:space="preserve"> the note</w:t></w:r></w:p></w:footnote>'
        )
        ->notes('endnote',
            '<w:endnote w:id="1"><w:p><w:r><w:endnoteRef/></w:r><w:r><w:t xml:space="preserve"> the endnote</w:t></w:r></w:p></w:endnote>'
        )
        ->body(
            '<w:p><w:r><w:t>claim</w:t></w:r><w:r><w:footnoteReference w:id="1"/></w:r><w:r><w:endnoteReference w:id="1"/></w:r></w:p>'
        )
        ->read();

    expect($document->notes)->toHaveCount(2)
        ->and($document->notes[0]->type)->toBe('footnote')
        ->and(blockText($document->notes[0]->blocks[0]))->toBe('the note')
        ->and($document->notes[1]->type)->toBe('endnote')
        ->and(blockText($document->notes[1]->blocks[0]))->toBe('the endnote');
});

it('drops hidden text unless it is asked for', function () {
    $builder = DocxBuilder::make()->body(
        '<w:p><w:r><w:t xml:space="preserve">visible </w:t></w:r><w:r><w:rPr><w:vanish/></w:rPr><w:t>secret</w:t></w:r></w:p>'
    );

    expect(blockTexts($builder->read()->blocks))->toBe(['visible '])
        ->and(blockTexts($builder->read(new Options(includeHiddenText: true))->blocks))->toBe(['visible secret']);
});

it('maps symbol-font characters to Unicode', function () {
    $document = DocxBuilder::make()->body(
        '<w:p><w:r><w:sym w:font="Wingdings" w:char="F0FC"/></w:r></w:p>'
        .'<w:p><w:r><w:sym w:font="Symbol" w:char="F0B7"/></w:r></w:p>'
    )->read();

    expect(blockTexts($document->blocks))->toBe(["\u{2713}", "\u{2022}"]);
});

it('starts a new page where a section ends', function () {
    $document = DocxBuilder::make()->body(
        '<w:p><w:pPr><w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:type w:val="nextPage"/></w:sectPr></w:pPr><w:r><w:t>first section</w:t></w:r></w:p>'
        .'<w:p><w:r><w:t>second section</w:t></w:r></w:p>'
    )->read();

    expect(paragraphOf($document->blocks, 'first section')->properties->pageBreakBefore)->toBeFalse()
        ->and(paragraphOf($document->blocks, 'second section')->properties->pageBreakBefore)->toBeTrue();
});

it('takes a moved-away paragraph as deleted and the moved-in one as kept', function () {
    $document = DocxBuilder::make()->body(
        '<w:p><w:moveFrom w:id="1" w:author="A" w:date="2026-01-01T00:00:00Z"><w:r><w:t>gone</w:t></w:r></w:moveFrom>'
        .'<w:moveTo w:id="2" w:author="A" w:date="2026-01-01T00:00:00Z"><w:r><w:t>moved here</w:t></w:r></w:moveTo></w:p>'
    )->read();

    expect(blockTexts($document->blocks))->toBe(['moved here']);
});

it('ignores comment anchors and their marks', function () {
    $document = DocxBuilder::make()->body(
        '<w:p><w:commentRangeStart w:id="1"/><w:r><w:t>reviewed</w:t></w:r><w:commentRangeEnd w:id="1"/>'
        .'<w:r><w:rPr><w:rStyle w:val="CommentReference"/></w:rPr><w:commentReference w:id="1"/></w:r></w:p>'
    )->read();

    expect(blockTexts($document->blocks))->toBe(['reviewed']);
});

it('drops the spacing between paragraphs of one style when Word does', function () {
    $document = DocxBuilder::make()
        ->styles('<w:style w:type="paragraph" w:styleId="Tight"><w:name w:val="Tight"/><w:pPr><w:spacing w:before="200" w:after="200"/><w:contextualSpacing/></w:pPr></w:style>')
        ->body(
            '<w:p><w:pPr><w:pStyle w:val="Tight"/></w:pPr><w:r><w:t>one</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:pStyle w:val="Tight"/></w:pPr><w:r><w:t>two</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>other style</w:t></w:r></w:p>'
        )
        ->read();

    expect(paragraphOf($document->blocks, 'one')->properties->spacingBefore)->toBe(200)
        ->and(paragraphOf($document->blocks, 'one')->properties->spacingAfter)->toBe(0)
        ->and(paragraphOf($document->blocks, 'two')->properties->spacingBefore)->toBe(0)
        // The last one of the run keeps its spacing towards a paragraph of another style.
        ->and(paragraphOf($document->blocks, 'two')->properties->spacingAfter)->toBe(200);
});

it('reads a floating picture and which side text flows around', function () {
    $anchor = '<w:r><w:drawing><wp:anchor behindDoc="0" simplePos="0" relativeHeight="1" locked="0" layoutInCell="1" allowOverlap="1">'
        .'<wp:simplePos x="0" y="0"/>'
        .'<wp:positionH relativeFrom="column"><wp:align>right</wp:align></wp:positionH>'
        .'<wp:positionV relativeFrom="paragraph"><wp:posOffset>0</wp:posOffset></wp:positionV>'
        .'<wp:extent cx="952500" cy="476250"/><wp:wrapSquare wrapText="bothSides"/>'
        .'<wp:docPr id="1" name="Floating"/>'
        .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
        .'<pic:nvPicPr><pic:cNvPr id="1" name="Floating"/><pic:cNvPicPr/></pic:nvPicPr>'
        .'<pic:blipFill><a:blip r:embed="rIdImage"/></pic:blipFill><pic:spPr/></pic:pic>'
        .'</a:graphicData></a:graphic></wp:anchor></w:drawing></w:r>';

    $document = DocxBuilder::make()
        ->part('media/image1.png', TestImage::png(20, 10), 'image/png')
        ->relationship('rIdImage', 'image', 'media/image1.png')
        ->body('<w:p>'.$anchor.'<w:r><w:t>text beside it</w:t></w:r></w:p>')
        ->read();

    $paragraph = $document->blocks[0];
    $image = $paragraph->children[0];

    expect($image)->toBeInstanceOf(ImageRun::class)
        ->and($image->float)->toBe('right');
});

it('refuses a package that is not a Word document', function () {
    $types = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>';
    $rels = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
    $read = static fn (string $bytes): Closure => static fn () => (new HtmlDocx)->readDocx($bytes);

    expect($read('this is not a package'))->toThrow(HtmlDocxException::class)
        ->and($read(zipBytes(['word/document.xml' => ['<w:document/>', true]])))->toThrow(HtmlDocxException::class)
        ->and($read(zipBytes([
            '[Content_Types].xml' => [$types, true],
            '_rels/.rels' => [$rels, true],
            'word/document.xml' => ['<?xml version="1.0"?><w:notADocument xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>', true],
        ])))->toThrow(HtmlDocxException::class);
});

it('does not resolve entities a document declares', function () {
    $document = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<!DOCTYPE w:document [<!ENTITY secret "leaked">]>'
        .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .'<w:body><w:p><w:r><w:t>&secret;</w:t></w:r></w:p></w:body></w:document>';

    $bytes = zipBytes([
        '[Content_Types].xml' => ['<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>', true],
        '_rels/.rels' => ['<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>', true],
        'word/document.xml' => [$document, true],
    ]);

    $texts = static function () use ($bytes): array {
        return blockTexts((new HtmlDocx)->readDocx($bytes)->blocks);
    };

    // Either the parser refuses the doctype or it leaves the entity unresolved;
    // what must never happen is the entity being expanded.
    try {
        expect($texts())->not->toContain('leaked');
    } catch (HtmlDocxException) {
        expect(true)->toBeTrue();
    }
});

it('keeps the decompression limits it is given', function () {
    $bytes = DocxBuilder::make()->body('<w:p><w:r><w:t>'.str_repeat('a', 5000).'</w:t></w:r></w:p>')->toBytes();

    expect(fn () => (new HtmlDocx(new Options(maxDocxTotalBytes: 1024)))->readDocx($bytes))
        ->toThrow(HtmlDocxException::class);
});
