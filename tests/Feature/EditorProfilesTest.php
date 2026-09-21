<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Editor;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Model\Formula;

/** Editor HTML → model → editor HTML, through a profile. */
function profileHtml(Editor $editor, string $source): string
{
    return HtmlDocx::for($editor, testOptions())->fromHtml($source)->toHtml();
}

const KATEX = '<p><span class="__se__katex katex" data-exp="x^2 + \frac{a}{b}">x</span></p>';

it('writes a formula in the shape its editor\'s math plugin reads', function (Editor $editor, string $shape) {
    expect(profileHtml($editor, KATEX))->toContain($shape);
})->with([
    'CKEditor (ckeditor5-math, MathJax)' => [Editor::CKEditor, '<span class="math-tex">\(x^2 + \frac{a}{b}\)</span>'],
    'TinyMCE (MathJax)' => [Editor::TinyMce, '<span class="math-tex">\(x^2 + \frac{a}{b}\)</span>'],
    'TipTap (Mathematics)' => [Editor::TipTap, '<span data-type="inline-math" data-latex="x^2 + \frac{a}{b}">\(x^2 + \frac{a}{b}\)</span>'],
]);

it('reads a formula back from every shape an editor keeps', function (string $html, string $latex, bool $display) {
    $formula = HtmlDocx::plain(testOptions())->fromHtml("<p>{$html}</p>")->document()->blocks[0]->children[0];

    expect($formula)->toBeInstanceOf(Formula::class)
        ->and($formula->latex)->toBe($latex)
        ->and($formula->display)->toBe($display);
})->with([
    'math-tex, inline' => ['<span class="math-tex">\(x^2\)</span>', 'x^2', false],
    'math-tex, display' => ['<span class="math-tex">\[ a + b \]</span>', 'a + b', true],
    'TipTap inline math' => ['<span data-type="inline-math" data-latex="y_1"></span>', 'y_1', false],
    'TipTap block math' => ['<span data-type="block-math" data-latex="y_2"></span>', 'y_2', true],
    'MathML' => ['<math><semantics><mi>z</mi><annotation encoding="application/x-tex">z</annotation></semantics></math>', 'z', false],
    'MathML, display' => ['<math display="block"><semantics><mi>z</mi><annotation encoding="application/x-tex">z</annotation></semantics></math>', 'z', true],
    'SunEditor KaTeX' => ['<span class="__se__katex katex" data-exp="k">k</span>', 'k', false],
]);

it('keeps a formula on a line of its own on the way through HTML', function (?Editor $editor, string $shape) {
    $docx = HtmlDocx::plain(testOptions())->fromHtml('<p><math display="block"><semantics><mi>z</mi><annotation encoding="application/x-tex">z</annotation></semantics></math></p>')->toDocx();
    $converter = $editor === null ? HtmlDocx::plain(testOptions()) : HtmlDocx::for($editor, testOptions());

    expect($converter->fromDocx($docx)->toHtml())->toContain($shape);
})->with([
    'plain' => [null, '<math display="block">'],
    'CKEditor' => [Editor::CKEditor, '<span class="math-tex">\[z\]</span>'],
]);

it('writes cell lines as paragraphs, which editors keep', function (Editor $editor) {
    expect(profileHtml($editor, '<table><tr><td>cell</td></tr></table>'))->toMatch('~<td[^>]*><p [^>]*>cell</p></td>~');
})->with([Editor::CKEditor, Editor::TinyMce, Editor::TipTap]);

it('keeps a TipTap list item\'s text in a paragraph and its font on the item', function () {
    expect(profileHtml(Editor::TipTap, '<ol><li>one</li></ol>'))
        ->toMatch('~<li style="font-family: [^"]*; font-size: [^"]*; color: #000000;"><p style="[^"]*margin: [^"]*">one</p></li>~');
});

it('sizes TipTap\'s columns on the cells, in pixels', function () {
    expect(profileHtml(Editor::TipTap, '<table style="width: 300pt"><colgroup><col style="width: 100pt"><col style="width: 200pt"></colgroup><tr><td>a</td><td>b</td></tr><tr><td colspan="2">c</td></tr></table>'))
        ->toContain(' colwidth="133"><p ')
        ->toContain(' colwidth="267"><p ')
        ->toContain(' colwidth="133,267"><p ');
});

it('gives pictures their size as attributes too, which every editor keeps', function () {
    $png = Kovami\HtmlDocx\Tests\Support\TestImage::pngDataUri(4, 4);

    expect(profileHtml(Editor::CKEditor, "<p><img src=\"{$png}\" style=\"width: 20px; height: 10px\"></p>"))
        ->toContain('style="width: 20px; height: 10px;" width="20" height="10"');
});

it('finds a note list an editor stripped of its section by the ids of its items', function () {
    $document = HtmlDocx::plain(testOptions())->fromHtml(
        '<p>Text<sup><a href="#footnote-1">1</a></sup></p><ol><li id="footnote-1"><p>A note. <a href="#footnote-ref-1">↩</a></p></li></ol>',
    )->document();

    expect($document->notes)->toHaveCount(1)
        ->and($document->notes[0]->type)->toBe('footnote')
        ->and($document->blocks)->toHaveCount(1);
});

it('reads an editor\'s empty paragraph as empty', function () {
    $blocks = HtmlDocx::for(Editor::TinyMce, testOptions())->fromHtml('<p>a</p><p>&nbsp;</p><p>b</p>')->document()->blocks;

    expect($blocks[1]->children)->toBe([]);
});

it('reads a CKEditor table figure as the table, with the figure\'s width', function () {
    $document = HtmlDocx::for(Editor::CKEditor, testOptions())->fromHtml(
        '<p>Before</p><figure class="table" style="width: 50%;"><table><tbody><tr><td>cell</td></tr></tbody></table></figure>',
    )->document();
    $table = $document->blocks[1];

    expect($table)->toBeInstanceOf(Kovami\HtmlDocx\Model\Table::class)
        ->and($table->properties->width)->toBe(intdiv($document->pageLayout->contentWidthTwips(), 2))
        ->and($table->marginTop)->toBe(0);
});

it('reads CKEditor\'s cells with the borders its content stylesheet draws', function () {
    $cell = HtmlDocx::for(Editor::CKEditor, testOptions())
        ->fromHtml('<figure class="table"><table><tbody><tr><td style="border-color: #000000; border-width: 2px;">cell</td></tr></tbody></table></figure>')
        ->document()->blocks[0]->rows[0]->cells[0];

    expect($cell->properties->borders->top?->style)->toBe('single')
        ->and($cell->properties->borders->top?->color)->toBe('000000');
});

it('keeps a bookmark at the start of a paragraph as the paragraph\'s id, which editors keep', function () {
    expect(profileHtml(Editor::CKEditor, '<h2 id="table">Table</h2><p><a href="#table">see</a></p>'))
        ->toContain(' id="table">Table</h2>')
        ->not->toContain('<a id=');
});

it('recognises a note\'s back link whose mark lost its id', function () {
    $document = HtmlDocx::plain(testOptions())->fromHtml(
        '<p>Text<sup><a href="#footnote-1">1</a></sup></p><ol><li id="footnote-1"><p>A note.<a href="#footnote-ref-1"> ↩</a></p></li></ol>',
    )->document();

    expect(HtmlDocx::plain(testOptions())->fromDocument($document)->toHtml())
        ->toContain('<a href="#footnote-ref-1" role="doc-backlink"> ↩</a></p></li>')
        ->not->toContain('footnote_ref');
});

it('writes a CKEditor table in its figure, which carries the width and no margins', function () {
    $html = profileHtml(Editor::CKEditor, '<table style="width: 50%; margin-left: auto; margin-right: auto"><tr><td>a</td></tr></table>');

    expect($html)->toStartWith('<figure class="table" style="width: 50%; margin: 0 auto 0 auto;"><table style="width: 100%; ')
        ->and(profileHtml(Editor::CKEditor, $html))->toBe($html);
});

it('reads text an editor leaves unformatted in the typography that editor shows', function (?Editor $editor, string $font, int $halfPoints, ?int $lineSpacing) {
    $options = new Kovami\HtmlDocx\Options(createdAt: new DateTimeImmutable('2026-01-02T03:04:05Z'));
    $converter = $editor === null ? HtmlDocx::plain($options) : HtmlDocx::for($editor, $options);
    $paragraph = $converter->fromHtml('<p>plain text</p>')->document()->blocks[0];

    expect($paragraph->children[0]->properties->fontFamily)->toBe($font)
        ->and($paragraph->children[0]->properties->size)->toBe($halfPoints)
        ->and($paragraph->properties->lineSpacing)->toBe($lineSpacing);
})->with([
    // Each editor's own content stylesheet: what a user of it sees.
    'CKEditor: Helvetica, medium, 1.5' => [Editor::CKEditor, 'Helvetica', 24, 300],
    'TinyMCE: the system font, medium, 1.4' => [Editor::TinyMce, 'Segoe UI', 24, 253],
    'SunEditor: Helvetica Neue 13px, 1.5' => [Editor::SunEditor, 'Helvetica Neue', 20, 302],
    'plain HTML: the options\' own base' => [null, 'Calibri', 22, null],
]);

it('lets the options replace an editor\'s typography, for an application whose CSS differs', function () {
    $options = new Kovami\HtmlDocx\Options(fontFamily: 'Times New Roman', fontSizePt: 12.0, createdAt: new DateTimeImmutable('2026-01-02T03:04:05Z'));
    $run = HtmlDocx::for(Editor::CKEditor, $options)->fromHtml('<p>plain text</p>')->document()->blocks[0]->children[0];

    expect($run->properties->fontFamily)->toBe('Times New Roman')
        ->and($run->properties->size)->toBe(24);
});

it('keeps the margins of a CKEditor quotation apart from its paragraph\'s, as its stylesheet does', function () {
    // ckeditor5-content.css gives blockquote overflow: hidden, so the margins inside it do not collapse through it.
    $paragraph = HtmlDocx::for(Editor::CKEditor, testOptions())->fromHtml('<blockquote><p>quoted</p></blockquote>')->document()->blocks[0];

    expect($paragraph->properties->spacingBefore)->toBe(440)
        ->and($paragraph->properties->spacingAfter)->toBe(440);
});
