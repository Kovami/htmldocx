<?php

declare(strict_types=1);

use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Tests\Support\WordDocument;

/**
 * Microsoft Word opens this document without repairing it and renders every
 * part of it, so what the reader makes of it is what a real Word document
 * looks like on the way into an editor.
 */
function wordHtml(): string
{
    return converter()->fromDocx(WordDocument::bytes())->toHtml();
}

it('reads a document written the way Word writes one, losing nothing', function () {
    $html = wordHtml();

    foreach (['Абзац со сноской', 'Показатель', 'Выручка', 'Итого', 'Текст в надписи', 'Сноска, как её пишет Word.', 'Концевая сноска.'] as $text) {
        expect($html)->toContain($text);
    }
});

it('paints a table styled from Word\'s gallery', function () {
    $html = wordHtml();

    expect($html)
        // The header row: white text on the accent colour the style names.
        ->toContain('<th style="border: 0.67px solid #8eaadb; background-color: #4472c4;')
        ->toContain('color: #ffffff; margin: 0 0 10.67px 0; line-height: 1.317; position: relative; top: -0.71px;">Показатель</div>')
        // The first body row is banded, the one after it is not.
        ->toContain('background-color: #dae3f3;')
        // The first column is bold, the last row has the style's double rule.
        ->toContain('<strong>Выручка</strong>')
        ->toContain('border-top: 0.67px double #4472c4;')
        ->toContain('<strong>600</strong>');
});

it('keeps Word\'s footnotes and endnotes with their marks and text', function () {
    $html = wordHtml();

    expect($html)
        ->toContain('<sup style="line-height: 0;"><a href="#footnote-1" id="footnote-ref-1">1</a></sup>')
        ->toContain('<sup style="line-height: 0;"><a href="#endnote-1" id="endnote-ref-1">i</a></sup>')
        ->toContain('<li id="footnote-1">')
        ->toContain('<li id="endnote-1">')
        // The note styles are resolved: footnote text is 10pt.
        ->toContain('font-size: 13.33px; color: #000000; margin: 0 0 0 0; line-height: 1.221;">Сноска, как её пишет Word.')
        ->toContain('<a href="#footnote-ref-1">');
});

it('turns an Office Math equation into a KaTeX span', function () {
    expect(wordHtml())
        ->toContain('class="__se__katex katex"')
        ->toContain('data-exp="a^{2}+b^{2}=c^{2}"');
});

it('reads the text of a text box, whichever vocabulary Word used', function () {
    expect(wordHtml())->toContain('<strong>Текст в надписи</strong>');
});

it('writes a package Word can open back', function () {
    $converter = converter();
    $again = $converter->fromDocx($converter->fromDocx(WordDocument::bytes())->toDocx())->document();

    // Everything that survives a second package survives Word too: the notes,
    // the table and the text the formula became.
    expect($again->notes)->toHaveCount(2)
        ->and($converter->fromDocument($again)->toHtml())
        ->toContain('Показатель')
        ->toContain('<li id="footnote-1">')
        ->toContain('a^{2}+b^{2}=c^{2}');
});
