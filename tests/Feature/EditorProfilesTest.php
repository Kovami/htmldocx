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

it('reads a formula back from every shape an editor keeps', function (string $html, string $latex) {
    $formula = HtmlDocx::plain(testOptions())->fromHtml("<p>{$html}</p>")->document()->blocks[0]->children[0];

    expect($formula)->toBeInstanceOf(Formula::class)
        ->and($formula->latex)->toBe($latex);
})->with([
    'math-tex, inline' => ['<span class="math-tex">\(x^2\)</span>', 'x^2'],
    'math-tex, display' => ['<span class="math-tex">\[ a + b \]</span>', 'a + b'],
    'TipTap inline math' => ['<span data-type="inline-math" data-latex="y_1"></span>', 'y_1'],
    'MathML' => ['<math><semantics><mi>z</mi><annotation encoding="application/x-tex">z</annotation></semantics></math>', 'z'],
    'SunEditor KaTeX' => ['<span class="__se__katex katex" data-exp="k">k</span>', 'k'],
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
