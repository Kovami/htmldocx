<?php

declare(strict_types=1);

use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Tests\Support\DocxBuilder;

/** DOCX → plain HTML, with the warnings the conversion gave. */
function plainHtml(DocxBuilder $docx, array &$warnings = []): string
{
    return HtmlDocx::plain(testOptions())
        ->withWarningHandler(static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        })
        ->fromDocx($docx->toBytes())
        ->toHtml();
}

it('spells out the font, colour, margins and line height of every block', function () {
    $html = plainHtml(DocxBuilder::make()->body(
        '<w:p><w:pPr><w:spacing w:before="0" w:after="160" w:line="276" w:lineRule="auto"/></w:pPr>'
        . '<w:r><w:rPr><w:rFonts w:ascii="Aptos"/><w:sz w:val="24"/></w:rPr><w:t>Body</w:t></w:r></w:p>',
    ));

    expect($html)->toBe(
        '<p style="font-family: &quot;Times New Roman&quot;; font-size: 13.33px; color: #000000; margin: 0 0 10.67px 0; line-height: 1.15;">'
        . '<span style="font-family: Aptos; font-size: 16px;">Body</span></p>',
    );
});

it('writes single spacing as the font\'s own line height', function () {
    $html = plainHtml(DocxBuilder::make()->body('<w:p><w:r><w:t>x</w:t></w:r></w:p>'));

    expect($html)->toContain('line-height: normal;');
});

it('uses no editor classes and no editor stylesheet', function () {
    $html = HtmlDocx::plain(testOptions(['fullHtmlDocument' => true]))
        ->fromDocx(DocxBuilder::make()->body(
            '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/></w:tblPr><w:tblGrid><w:gridCol w:w="9355"/></w:tblGrid>'
            . '<w:tr><w:tc><w:p><w:r><w:t>cell</w:t></w:r></w:p></w:tc></w:tr></w:tbl>',
        )->toBytes())
        ->toHtml();

    expect($html)->not->toContain('se-')
        ->and($html)->not->toContain('__se__')
        ->and($html)->not->toContain('sun-editor')
        ->and($html)->not->toContain('<style')
        ->and($html)->toContain('<body>');
});

it('draws table cells itself, over whatever borders an editor gives them', function () {
    $html = plainHtml(DocxBuilder::make()->body(
        '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/></w:tblPr><w:tblGrid><w:gridCol w:w="9355"/></w:tblGrid>'
        . '<w:tr><w:tc><w:p><w:r><w:t>cell</w:t></w:r></w:p></w:tc></w:tr></w:tbl>',
    ));

    expect($html)->toContain('<table style="width: 100%; border-collapse: collapse; table-layout: fixed;')
        ->and($html)->toContain('<td style="border: none; vertical-align: top; padding: 0 7.2px 0 7.2px;">');
});

it('writes a picture alone in its paragraph as an ordinary image', function () {
    $html = HtmlDocx::plain(testOptions())
        ->fromHtml('<p style="text-align: center"><img src="' . Kovami\HtmlDocx\Tests\Support\TestImage::pngDataUri(10, 10) . '" width="10" height="10" alt="dot"></p>')
        ->toHtml();

    expect($html)->toStartWith('<p style="text-align: center;')
        ->and($html)->toContain('<img src="data:image/png;base64,')
        ->and($html)->not->toContain('<figure');
});

it('reads its own plain HTML back to the same document', function () {
    $converter = HtmlDocx::plain(testOptions());
    $first = plainHtml(DocxBuilder::make()->body(
        '<w:p><w:pPr><w:spacing w:after="120"/><w:ind w:left="720"/></w:pPr><w:r><w:t>Single spaced</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:spacing w:line="360" w:lineRule="auto"/><w:jc w:val="center"/></w:pPr>'
        . '<w:r><w:t xml:space="preserve">E = mc</w:t></w:r><w:r><w:rPr><w:vertAlign w:val="superscript"/></w:rPr><w:t>2</w:t></w:r></w:p>',
    ));

    expect($converter->fromDocx($converter->fromHtml($first)->toDocx())->toHtml())->toBe($first);
});
