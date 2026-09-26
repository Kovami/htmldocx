<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Tests\Support\TestImage;

function everything(): string
{
    return '<p>Text</p>'
        . '<p>A picture <img src="' . TestImage::pngDataUri(20, 10) . '" alt=""> in a line.</p>'
        . '<p><img src="' . TestImage::pngDataUri(20, 10) . '" alt=""></p>'
        . '<table><tr><td>Cell</td></tr></table>'
        . '<ul><li>Item</li></ul>'
        . '<p>See <a href="https://example.com">the link</a>.</p>'
        . '<p>Formula: <span class="__se__katex katex" data-exp="\\frac{a}{2}">x</span></p>'
        . '<p>Noted<sup><a href="#fn1" id="fnref1">1</a></sup></p>'
        . '<section class="footnotes"><ol><li id="fn1">The note.</li></ol></section>';
}

it('keeps everything by default, both ways', function () {
    $docx = docx(everything());
    $html = roundTrip(everything());

    expect($docx->count('//w:drawing'))->toBe(2)
        ->and($docx->count('//w:tbl'))->toBe(1)
        ->and($docx->count('//w:numPr'))->toBe(1)
        ->and($docx->count('//w:hyperlink'))->toBe(1)
        ->and($docx->count('//m:oMath'))->toBe(1)
        ->and($docx->count('//w:footnoteReference'))->toBe(1)
        ->and(substr_count($html, '<img'))->toBe(2)
        ->and($html)->toContain('<table')->toContain('<li')->toContain('the link')->toContain('The note.');
});

it('drops what a switch turns off, whole and with its text, from HTML', function (string $flag, string $xpath, string $gone) {
    $docx = docx(everything(), converter([$flag => false]));

    expect($docx->count($xpath))->toBe(0)
        ->and(implode("\n", $docx->paragraphTexts()))->not->toContain($gone)->toContain('Text');
})->with([
    'pictures' => ['includeImages', '//w:drawing', "\u{FFFF}"],
    'tables' => ['includeTables', '//w:tbl', 'Cell'],
    'lists' => ['includeLists', '//w:numPr', 'Item'],
    'links' => ['includeLinks', '//w:hyperlink', 'the link'],
    'formulas' => ['includeFormulas', '//m:oMath', "\u{FFFF}"],
    'notes' => ['includeNotes', '//w:footnoteReference', "\u{FFFF}"],
]);

it('drops what a switch turns off from DOCX too', function (string $flag, string $gone) {
    $bytes = converter()->fromHtml(everything())->toDocx();

    expect(converter([$flag => false])->fromDocx($bytes)->toHtml())->not->toContain($gone);
})->with([
    'pictures' => ['includeImages', '<img'],
    'tables' => ['includeTables', 'Cell'],
    'lists' => ['includeLists', 'Item'],
    'links' => ['includeLinks', 'the link'],
    'formulas' => ['includeFormulas', 'se-math'],
    'notes' => ['includeNotes', 'The note.'],
]);

it('drops a paragraph that held only what was switched off, and keeps the rest of its line', function () {
    $all = docx(everything());
    $docx = docx(everything(), converter(['includeImages' => false]));

    expect($docx->paragraphTexts())->toContain('A picture  in a line.')
        ->and($docx->count('/w:document/w:body/w:p'))->toBe($all->count('/w:document/w:body/w:p') - 1);
});

it('leaves the paragraph Word needs in a cell that held only what was switched off', function () {
    $docx = docx('<table><tr><td><img src="' . TestImage::pngDataUri(20, 10) . '" alt=""></td></tr></table>', converter(['includeImages' => false]));

    expect($docx->count('//w:drawing'))->toBe(0)
        ->and($docx->count('//w:tc/w:p'))->toBe(1);
});

it('switches per call, on a converter set up once', function () {
    $converter = converter(['includeImages' => false]);

    expect(substr_count($converter->fromHtml(everything())->toHtml(), '<img'))->toBe(0)
        ->and(substr_count($converter->with(includeImages: true)->fromHtml(everything())->toHtml(), '<img'))->toBe(2);
});

it('takes the options to change by name only', function () {
    converter()->with(false);
})->throws(InvalidArgumentException::class);

it('drops comments and headers from HTML too', function () {
    $html = '<div class="se-header">Head</div><p><span class="se-comment" data-comment="1">anchored</span></p>'
        . '<ol class="se-comments"><li data-comment="1" data-author="A">Remark</li></ol>';

    $docx = docx($html, converter(['includeComments' => false, 'includeHeadersFooters' => false]));

    expect($docx->has('word/comments.xml'))->toBeFalse()
        ->and($docx->count('//w:headerReference'))->toBe(0)
        ->and($docx->paragraphTexts())->toContain('anchored');
});
