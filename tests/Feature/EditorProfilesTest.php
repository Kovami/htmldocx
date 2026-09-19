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
