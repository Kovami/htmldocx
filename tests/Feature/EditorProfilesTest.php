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
