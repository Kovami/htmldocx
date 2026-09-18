<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Model\DocumentMetadata;
use Kovami\HtmlDocx\Model\Field;
use Kovami\HtmlDocx\Model\HeaderFooter;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\ParagraphProperties;
use Kovami\HtmlDocx\Model\RunProperties;
use Kovami\HtmlDocx\Model\TextRun;
use Kovami\HtmlDocx\Tests\Support\Docx;
use Kovami\HtmlDocx\Tests\Support\DocxBuilder;
use Kovami\HtmlDocx\Tests\Support\DocxIntegrity;

/** The text of some blocks, fields showing their last value. */
function furnitureText(array $blocks): string
{
    $text = static function (array $inlines) use (&$text): string {
        $result = '';

        foreach ($inlines as $inline) {
            $result .= match (true) {
                $inline instanceof TextRun => $inline->text,
                $inline instanceof Field => "[{$inline->name}:{$inline->result}]",
                $inline instanceof Hyperlink => $text($inline->children),
                default => '',
            };
        }

        return $result;
    };

    return implode("\n", array_map(static fn($block): string => $block instanceof Paragraph ? $text($block->children) : '', $blocks));
}

/** @return array<string, string> "kind/type" => text */
function furniture(Document $document): array
{
    $result = [];

    foreach ($document->headersFooters as $headerFooter) {
        $result["{$headerFooter->kind}/{$headerFooter->type}"] = furnitureText($headerFooter->blocks);
    }

    return $result;
}

/** A document with a header on every page, another on the first, and a numbered footer. */
function furnishedDocument(): Document
{
    $run = new RunProperties(fontFamily: 'Calibri', size: 22, color: '000000');
    $paragraph = static fn(array $children): Paragraph => new Paragraph(new ParagraphProperties(spacingBefore: 0, spacingAfter: 0), $children);

    return new Document(
        blocks: [$paragraph([new TextRun('Body text', $run)])],
        defaultRunProperties: $run,
        styles: [],
        lists: [],
        pageLayout: PageLayout::a4Portrait(),
        metadata: new DocumentMetadata(new DateTimeImmutable('2026-01-02T03:04:05Z')),
        headersFooters: [
            new HeaderFooter(HeaderFooter::HEADER, HeaderFooter::DEFAULT, [$paragraph([new TextRun('Running head', $run)])]),
            new HeaderFooter(HeaderFooter::HEADER, HeaderFooter::FIRST, [$paragraph([new TextRun('Title page', $run)])]),
            new HeaderFooter(HeaderFooter::FOOTER, HeaderFooter::DEFAULT, [$paragraph([
                new TextRun('Page ', $run),
                new Field(Field::PAGE, '1', $run),
                new TextRun(' of ', $run),
                new Field(Field::NUMPAGES, '3', $run),
            ])]),
            new HeaderFooter(HeaderFooter::FOOTER, HeaderFooter::EVEN, [$paragraph([new TextRun('Even footer', $run)])]),
        ],
    );
}

it('reads the headers and footers of the last section as Word shows them', function () {
    $paragraph = static fn(string $text): string => "<w:p><w:r><w:t>{$text}</w:t></w:r></w:p>";

    $document = DocxBuilder::make()
        ->headerFooter('header', 'rIdH1', 'header1.xml', $paragraph('Running head'))
        ->headerFooter('header', 'rIdH2', 'header2.xml', $paragraph('Title page'))
        ->headerFooter('header', 'rIdH3', 'header3.xml', $paragraph('Even head'))
        ->headerFooter(
            'footer',
            'rIdF1',
            'footer1.xml',
            '<w:p><w:r><w:t xml:space="preserve">Page </w:t></w:r>'
            . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE   \* MERGEFORMAT </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>2</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>'
            . '<w:r><w:t xml:space="preserve"> of </w:t></w:r>'
            . '<w:fldSimple w:instr=" NUMPAGES "><w:r><w:t>5</w:t></w:r></w:fldSimple></w:p>',
        )
        ->body(
            // The first section names the default header; the last one inherits it.
            '<w:p><w:pPr><w:sectPr><w:headerReference w:type="default" r:id="rIdH1"/><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:pPr>'
            . '<w:r><w:t>Section one</w:t></w:r></w:p>'
            . $paragraph('Section two'),
        )
        ->section(
            '<w:headerReference w:type="first" r:id="rIdH2"/>'
            . '<w:headerReference w:type="even" r:id="rIdH3"/>'
            . '<w:footerReference w:type="default" r:id="rIdF1"/>'
            . '<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="850" w:bottom="1134" w:left="1701"/><w:titlePg/>',
        )
        ->read();

    // No w:evenAndOddHeaders in the settings, so Word never shows the even header.
    expect(furniture($document))->toBe([
        'header/default' => 'Running head',
        'header/first' => 'Title page',
        'footer/default' => 'Page [PAGE:2] of [NUMPAGES:5]',
    ]);

    $footer = $document->headersFooters[2]->blocks[0]->children;

    expect($footer[1])->toBeInstanceOf(Field::class)
        ->and($footer[1]->properties->bold)->toBeTrue();
});

it('shows even-page variants only when the settings ask for them', function () {
    $document = DocxBuilder::make()
        ->settings('<w:evenAndOddHeaders/>')
        ->headerFooter('footer', 'rIdF1', 'footer1.xml', '<w:p><w:r><w:t>Odd</w:t></w:r></w:p>')
        ->headerFooter('footer', 'rIdF2', 'footer2.xml', '<w:p><w:r><w:t>Even</w:t></w:r></w:p>')
        ->body('<w:p><w:r><w:t>Body</w:t></w:r></w:p>')
        ->section('<w:footerReference w:type="default" r:id="rIdF1"/><w:footerReference w:type="even" r:id="rIdF2"/><w:pgSz w:w="11906" w:h="16838"/>')
        ->read();

    expect(furniture($document))->toBe(['footer/default' => 'Odd', 'footer/even' => 'Even']);
});

it('drops an empty default header but keeps an empty first-page one, which hides it', function () {
    $document = DocxBuilder::make()
        ->headerFooter('header', 'rIdH1', 'header1.xml', '<w:p/>')
        ->headerFooter('footer', 'rIdF1', 'footer1.xml', '<w:p><w:r><w:t>Footer</w:t></w:r></w:p>')
        ->headerFooter('footer', 'rIdF2', 'footer2.xml', '<w:p/>')
        ->body('<w:p><w:r><w:t>Body</w:t></w:r></w:p>')
        ->section('<w:headerReference w:type="default" r:id="rIdH1"/><w:footerReference w:type="default" r:id="rIdF1"/>'
            . '<w:footerReference w:type="first" r:id="rIdF2"/><w:pgSz w:w="11906" w:h="16838"/><w:titlePg/>')
        ->read();

    expect(furniture($document))->toBe(['footer/default' => 'Footer', 'footer/first' => '']);
});

it('drops the blank variants Word writes for every type once one of that type has content', function () {
    $document = DocxBuilder::make()
        ->headerFooter('header', 'rIdH1', 'header1.xml', '<w:p><w:r><w:t>Head</w:t></w:r></w:p>')
        ->headerFooter('header', 'rIdH2', 'header2.xml', '<w:p/>')
        ->headerFooter('footer', 'rIdF1', 'footer1.xml', '<w:p/>')
        ->headerFooter('footer', 'rIdF2', 'footer2.xml', '<w:p><w:r><w:t>Cover</w:t></w:r></w:p>')
        ->body('<w:p><w:r><w:t>Body</w:t></w:r></w:p>')
        ->section('<w:headerReference w:type="default" r:id="rIdH1"/><w:headerReference w:type="first" r:id="rIdH2"/>'
            . '<w:footerReference w:type="default" r:id="rIdF1"/><w:footerReference w:type="first" r:id="rIdF2"/>'
            . '<w:pgSz w:w="11906" w:h="16838"/><w:titlePg/>')
        ->read();

    expect(furniture($document))->toBe(['header/default' => 'Head', 'footer/first' => 'Cover']);
});

it('leaves headers and footers out on request', function () {
    $document = DocxBuilder::make()
        ->headerFooter('header', 'rIdH1', 'header1.xml', '<w:p><w:r><w:t>Head</w:t></w:r></w:p>')
        ->body('<w:p><w:r><w:t>Body</w:t></w:r></w:p>')
        ->section('<w:headerReference w:type="default" r:id="rIdH1"/><w:pgSz w:w="11906" w:h="16838"/>')
        ->read(testOptions(['includeHeadersFooters' => false]));

    expect($document->headersFooters)->toBe([]);
});

it('writes header and footer parts and points the section at them', function () {
    $docx = Docx::fromBytes((new HtmlDocx(testOptions()))->writeDocx(furnishedDocument()));

    expect(DocxIntegrity::violations($docx))->toBe([])
        ->and($docx->count('//w:sectPr/w:headerReference'))->toBe(2)
        ->and($docx->count('//w:sectPr/w:footerReference'))->toBe(2)
        ->and($docx->count('//w:sectPr/w:titlePg'))->toBe(1)
        ->and($docx->count('//w:evenAndOddHeaders', null, 'word/settings.xml'))->toBe(1)
        ->and($docx->first('/w:hdr//w:t', null, 'word/header1.xml')?->textContent)->toBe('Running head')
        ->and($docx->first('/w:hdr//w:t', null, 'word/header2.xml')?->textContent)->toBe('Title page')
        ->and($docx->first('//w:fldSimple[1]', null, 'word/footer1.xml')?->getAttribute('w:instr'))->toBe(' PAGE ')
        ->and($docx->first('//w:fldSimple[2]', null, 'word/footer1.xml')?->getAttribute('w:instr'))->toBe(' NUMPAGES ')
        ->and($docx->count('//ct:Override[@ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"]', null, '[Content_Types].xml'))->toBe(2)
        ->and($docx->count('//ct:Override[@ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"]', null, '[Content_Types].xml'))->toBe(2);

    $firstHeader = $docx->first('//w:sectPr/w:headerReference[@w:type="first"]');
    $target = $docx->first('//rel:Relationship[@Id="' . $firstHeader?->getAttribute('r:id') . '"]', null, 'word/_rels/document.xml.rels');

    expect($target?->getAttribute('Target'))->toBe('header2.xml');
});

it('writes headers first and footers last in the HTML', function () {
    $html = (new HtmlDocx(testOptions()))->writeHtml(furnishedDocument());

    $p = '<p style="margin-bottom: 0;">';

    expect($html)->toStartWith('<div class="se-header">' . $p . 'Running head</p></div>' . "\n" . '<div class="se-header" data-type="first">' . $p . 'Title page</p></div>')
        ->toContain($p . 'Page <span class="se-field" data-field="PAGE">1</span> of <span class="se-field" data-field="NUMPAGES">3</span></p>')
        ->toEndWith('<div class="se-footer" data-type="even">' . $p . 'Even footer</p></div>')
        ->and(strpos($html, 'Body text'))->toBeLessThan(strpos($html, 'se-footer'));
});

it('reads its own header and footer sections back', function () {
    $converter = new HtmlDocx(testOptions());
    $read = $converter->readHtml($converter->writeHtml(furnishedDocument()));

    expect(furniture($read))->toBe([
        'header/default' => 'Running head',
        'header/first' => 'Title page',
        'footer/default' => 'Page [PAGE:1] of [NUMPAGES:3]',
        'footer/even' => 'Even footer',
    ])
        ->and(furnitureText($read->blocks))->toBe('Body text');
});

it('keeps headers and footers through DOCX to HTML and back', function () {
    $converter = new HtmlDocx(testOptions());
    $html = $converter->docxToHtml($converter->writeDocx(furnishedDocument()));
    $bytes = $converter->htmlToDocx($html);

    expect(DocxIntegrity::violations(Docx::fromBytes($bytes)))->toBe([])
        ->and($converter->docxToHtml($bytes))->toBe($html);
});

it('reads a second header of one type as ordinary content', function () {
    $document = (new HtmlDocx(testOptions()))->readHtml(
        '<div class="se-header"><p>One</p></div><div class="se-header"><p>Two</p></div><p>Body</p>',
    );

    expect(furniture($document))->toBe(['header/default' => 'One'])
        ->and(furnitureText($document->blocks))->toBe("Two\nBody");
});
