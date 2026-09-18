<?php

declare(strict_types=1);

use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Tests\Support\Docx;

function colorOf(string $html, string $text): ?string
{
    $docx = docx($html);

    return $docx->val('w:rPr/w:color', $docx->run($text));
}

it('orders rules by specificity, then source order', function () {
    $css = '<style>#id { color: #0000ff } .cls { color: #00ff00 } p { color: #ff0000 } p.cls { color: #ff00ff } .late { color: #111111 } .late2 { color: #222222 }</style>';

    expect(colorOf($css . '<p id="id" class="cls">id wins</p>', 'id wins'))->toBe('0000FF')
        ->and(colorOf($css . '<p class="cls">compound wins</p>', 'compound wins'))->toBe('FF00FF')
        ->and(colorOf($css . '<div class="late late2">source order</div>', 'source order'))->toBe('222222');
});

it('lets inline styles beat stylesheets and !important beat inline styles', function () {
    $css = '<style>.a { color: #ff0000 } .b { color: #00ff00 !important }</style>';

    expect(colorOf($css . '<p class="a" style="color: #0000ff">inline</p>', 'inline'))->toBe('0000FF')
        ->and(colorOf($css . '<p class="b" style="color: #0000ff">important</p>', 'important'))->toBe('00FF00')
        ->and(colorOf($css . '<p class="b" style="color: #0000ff !important">both important</p>', 'both important'))->toBe('0000FF');
});

it('supports the full selector syntax of the HTML5 DOM', function (string $selector, string $html) {
    expect(colorOf("<style>{$selector} { color: #abcdef }</style>{$html}", 'target'))->toBe('ABCDEF');
})->with([
    'child combinator' => ['div > span', '<div><span>target</span></div>'],
    'adjacent sibling' => ['h1 + p', '<h1>h</h1><p>target</p>'],
    'general sibling' => ['h1 ~ p', '<h1>h</h1><div></div><p>target</p>'],
    'attribute' => ['[data-kind="x"]', '<p data-kind="x">target</p>'],
    'attribute prefix' => ['a[href^="https"]', '<p><a href="https://x">target</a></p>'],
    'structural pseudo-class' => ['li:nth-child(2)', '<ul><li>one</li><li>target</li></ul>'],
    'negation' => ['p:not(.skip)', '<p class="skip">skip</p><p>target</p>'],
    'is()' => [':is(h2, h3) span', '<h3><span>target</span></h3>'],
    'universal' => ['* .x', '<div><b class="x">target</b></div>'],
]);

it('ignores rules that cannot apply to printed content', function () {
    $css = '<style>p::before { color: #ff0000 } a:hover { color: #00ff00 } @media screen { p { color: #0000ff } } @font-face { font-family: x } p[ { broken }</style>';

    expect(colorOf($css . '<p>unaffected</p>', 'unaffected'))->toBeNull();
});

it('applies @media print and @media all', function () {
    expect(colorOf('<style>@media print { p { color: #123456 } }</style><p>print</p>', 'print'))->toBe('123456')
        ->and(colorOf('<style>@media all and (min-width: 1px) { p { color: #654321 } }</style><p>all</p>', 'all'))->toBe('654321');
});

it('survives comments, strings with braces and data URLs in stylesheets', function () {
    $css = '<style>/* p { color: red } */ .x::after { content: "}{;" } .y { background: url("data:image/png;base64,AAA;B") no-repeat; color: #0a0b0c }</style>';

    expect(colorOf($css . '<p class="y">tricky</p>', 'tricky'))->toBe('0A0B0C');
});

it('inherits text properties but not box properties', function () {
    $docx = docx('<div style="color: #ff0000; border: 1px solid #000; margin-left: 20pt"><p>child</p><p style="margin-left: 10pt">own margin</p></div>');

    expect($docx->val('w:rPr/w:color', $docx->run('child')))->toBe('FF0000')
        ->and($docx->count('w:pPr/w:pBdr/*', $docx->paragraph('child')))->toBe(4)
        ->and(Docx::attr($docx->first('w:pPr/w:ind', $docx->paragraph('own margin')), 'left'))->toBe('615');
});

it('does not render display: none subtrees, including via stylesheet', function () {
    expect(docx('<style>.hide { display: none }</style><p>shown</p><div class="hide"><p>hidden</p></div>')->paragraphTexts())->toBe(['shown']);
});

it('lets display change how elements flow', function () {
    expect(docx('<style>span.block { display: block } div.inline { display: inline }</style><p>a<span class="block">b</span>c</p><div class="inline">d</div><div class="inline">e</div>')->paragraphTexts())
        ->toBe(['a', 'b', 'c', 'de']);
});

it('applies extra and replacement default stylesheets from the options', function () {
    $extra = new HtmlDocx(testOptions(['extraStylesheet' => 'p { color: #0f0f0f }']));
    $replaced = new HtmlDocx(testOptions(['defaultStylesheet' => 'h1 { font-size: 30pt; font-weight: bold; }']));

    expect(docx('<p>extra</p>', $extra)->val('//w:r/w:rPr/w:color'))->toBe('0F0F0F')
        ->and(docx('<h1>x</h1>', $replaced)->val("//w:style[@w:styleId='Heading1']/w:rPr/w:sz", null, 'word/styles.xml'))->toBe('60')
        ->and(docx('<p>no suneditor spacing</p>', $replaced)->first('//w:p/w:pPr/w:spacing'))->toBeNull();
});

it('lets document styles override the extra stylesheet', function () {
    $converter = new HtmlDocx(testOptions(['extraStylesheet' => 'p { color: #0f0f0f }']));

    expect(docx('<style>p { color: #f0f0f0 }</style><p>doc</p>', $converter)->val('//w:r/w:rPr/w:color'))->toBe('F0F0F0');
});
