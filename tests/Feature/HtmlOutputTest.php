<?php

declare(strict_types=1);

use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Image\CallbackImageHandler;
use Kovami\HtmlDocx\Model\ImageData;
use Kovami\HtmlDocx\Tests\Support\TestImage;

it('writes paragraphs and headings', function () {
    expect(markup(html('<h1>Title</h1><p>Body</p>')))
        ->toBe("<h1>Title</h1>\n<p>Body</p>");
});

it('keeps each paragraph\'s own spacing, which Word collapses the way CSS does', function () {
    // Between two paragraphs Word leaves the larger of space after and space before.
    expect(html('<p style="margin-bottom: 20px">a</p><p style="margin-top: 30px">b</p>'))
        ->toContain('margin: 2.07px 0 20px 0; line-height: 1.5; position: relative; top: -2.05px;">a</p>')
        ->toContain('margin: 30px 0 7.93px 0; line-height: 1.5; position: relative; top: -2.05px;">b</p>');
});

it('spells out every block\'s formatting, even what the editor\'s stylesheet already says', function () {
    // Content leaves the editor: shown elsewhere, it keeps looking like the document.
    expect(html('<p style="margin: 0 0 10px; line-height: 1.5">plain</p>'))
        ->toBe('<p style="font-family: Calibri, Carlito, sans-serif; font-size: 14.67px; color: #000000; margin: 2.07px 0 7.93px 0; line-height: 1.5; position: relative; top: -2.05px;">plain</p>');
});

it('keeps an empty paragraph visible and as tall as its paragraph mark', function () {
    expect(markup(html('<p>a</p><p><br></p>')))
        ->toBe("<p>a</p>\n<p><br></p>")
        ->and(markup(HtmlDocx::plain(testOptions())->fromHtml('<p>a</p><p><br></p>')->toHtml()))
        ->toBe("<p>a</p>\n<p><br></p>");
});

it('holds a SunEditor cell\'s lone empty line with a no-break space', function () {
    // SunEditor 3 turns <td><div><br></div></td> into <td><br></td>, losing the line's size.
    expect(markup(html('<table><tr><td><p><br></p></td><td><p>a</p><p><br></p></td></tr></table>')))
        ->toContain('<td><div>&nbsp;</div></td><td><div>a</div><div><br></div></td>');
});

it('doubles a trailing line break, which HTML would otherwise drop', function () {
    expect(markup(html('<p>line<br><br></p>')))->toBe('<p>line<br><br></p>');
});

it('preserves tabs and runs of spaces', function () {
    expect(html("<pre>a\tb  c</pre>"))
        ->toContain('white-space: pre-wrap;')
        // The block is indented 6.75pt, and Word's tab stops count from the margin.
        ->toContain("a<span style=\"position: relative; left: -9px; margin-right: -9px;\">\tb  c</span>");
});

it('keeps a shifted tab in place when no text follows it', function () {
    expect(html("<pre>a\t\nb\t</pre>"))->toContain("a\t<br")->toContain("b\t</p>");
});

it('keeps tabs on the grid of a block that is not indented', function () {
    expect(html("<p style=\"white-space: pre-wrap\">a\tb</p>"))->toContain("a\tb");
});

it('does not preserve whitespace around inline content', function () {
    expect(html('<p>before <img src="' . TestImage::pngDataUri(20, 10) . '" alt=""> after</p>'))
        ->not->toContain('pre-wrap');
});

it('writes inline formatting the way the editor produces it', function () {
    expect(markup(html('<p><strong>b</strong><em>i</em><u>u</u><del>s</del><sup>up</sup><sub>down</sub></p>')))
        ->toBe('<p><strong>b</strong><em>i</em><u>u</u><del>s</del><sup>up</sup><sub>down</sub></p>');
});

it('writes character formatting as one span per run', function () {
    expect(html('<p><span style="color: #ff0000; background-color: #ffff00; font-size: 20px">x</span></p>'))
        ->toEndWith('"><span style="font-size: 20px; color: #ff0000; background-color: #ffff00;">x</span></p>');
});

it('breaks a page before the paragraph that starts one', function () {
    expect(html('<p>a</p><p style="page-break-before: always">b</p>'))
        ->toContain('page-break-before: always;');
});

it('writes lists as nested ul and ol elements', function () {
    expect(markup(html('<ul><li>outer<ul><li>inner</li></ul></li></ul>')))
        ->toBe('<ul><li>outer<ul><li>inner</li></ul></li></ul>');
});

it('keeps the numbering an ordered list starts and continues at', function () {
    expect(markup(html('<ol start="5"><li>five</li><li value="9">nine</li><li>ten</li></ol>')))
        ->toBe('<ol start="5"><li>five</li><li value="9">nine</li><li>ten</li></ol>');
});

it('spells out markers CSS has no counter style for', function () {
    expect(html('<ol style="list-style-type: lower-greek"><li>alpha</li><li>beta</li></ol>'))
        ->toContain('list-style-type: &quot;α. &quot;')
        ->toContain('list-style-type: &quot;β. &quot;');
});

it('reads its own literal markers back unchanged', function () {
    $once = html('<ol style="list-style-type: lower-greek"><li>alpha</li><li>beta</li></ol>');

    expect(html($once))->toBe($once)
        ->and(roundTrip($once))->toBe($once);
});

it('writes formulas as the KaTeX spans the editor renders', function () {
    $source = '<p>f: <span class="__se__katex katex" contenteditable="false" data-exp="\sum_{i=1}^n i" data-font-size="1em">∑</span></p>';

    expect(html($source))
        ->toContain('se-math katex"')
        ->toContain('data-se-value="\sum_{i=1}^n i"');
});

it('keeps a formula through Word as an equation, not as its source text', function () {
    $source = '<p>f: <span class="__se__katex katex" data-exp="\frac{a+b}{2}">x</span>'
        . '<span class="__se__katex katex" data-exp="\sqrt[3]{x}">y</span></p>';

    // The package carries equations, not the text they are written with.
    expect(docx($source)->count('//m:oMath'))->toBe(2)
        ->and(docx($source)->paragraphTexts())->toBe(['f: '])
        ->and(roundTrip($source))
        ->toContain('data-se-value="\frac{a+b}{2}"')
        ->toContain('data-se-value="\sqrt[3]{x}"');
});

it('keeps a second paragraph of a list item inside the item', function () {
    expect(markup(html('<ol><li>first<p>second</p></li></ol>')))
        ->toContain('<li>first<p>second</p></li>');
});

it('indents a list whose items sit further in than the editor indents them', function () {
    expect(html('<ul style="padding-left: 80px"><li>far</li></ul>'))
        ->toContain('padding: 0 0 0 80px;');
});

it('writes tables in the shape SunEditor expects', function () {
    $html = html('<table><thead><tr><th>head</th></tr></thead><tbody><tr><td>body</td></tr></tbody></table>');

    expect($html)->toContain('<colgroup><col style="width: 100%;"></colgroup>')
        ->and(markup($html))
        ->toContain('<table class="se-table-size-100 se-table-layout-fixed">')
        ->toContain('<thead><tr><th><div>head</div></th></tr></thead>')
        ->toContain('<tbody><tr><td><div>body</div></td></tr></tbody>');
});

it('writes merged cells as colspan and rowspan', function () {
    $html = html('<table><tr><td rowspan="2">tall</td><td colspan="2">wide</td></tr><tr><td>a</td><td>b</td></tr></table>');

    expect(markup($html))->toContain('<td rowspan="2">')->toContain('<td colspan="2">');
});

it('writes a picture-only paragraph as an image component', function () {
    $html = html('<div class="se-component se-image-container __se__float-right"><figure><img src="' . TestImage::pngDataUri(40, 20) . '" alt="chart" style="width: 40px; height: 20px"></figure></div>');

    expect($html)
        // The component's 10px below: Word's picture line keeps 1.5's extra under the picture, the rest is spacing.
        ->toContain('<div class="se-component se-image-container __se__float-right" contenteditable="false" style="padding-bottom: 4.1px; margin-bottom: 5.87px;">')
        ->toContain('<figure style="margin: 0 0 0 auto; width: 40px;">')
        ->toContain('data-se-size="40px,20px"')
        ->toContain('alt="chart"');
});

it('embeds pictures as data URIs and lets a handler place them elsewhere', function () {
    $source = '<p><img src="' . TestImage::pngDataUri(40, 20) . '" alt="" style="width: 40px; height: 20px"></p>';
    $converter = converter();
    $handler = new CallbackImageHandler(fn(ImageData $image, string $description): string => '/media/' . $image->hash() . '.' . $image->extension);

    expect($converter->fromHtml($source)->toHtml())->toContain('src="data:image/png;base64,')
        ->and($converter->withImageHandler($handler)->fromDocument($converter->fromHtml($source)->document())->toHtml())->toMatch('~src="/media/[0-9a-f]{40}\.png"~');
});

it('leaves out pictures the handler declines', function () {
    $source = '<p><img src="' . TestImage::pngDataUri(40, 20) . '" alt="" style="width: 40px; height: 20px"></p>';
    $converter = converter()->withImageHandler(new CallbackImageHandler(static fn(): ?string => null));

    expect($converter->fromHtml($source)->toHtml())->not->toContain('<img');
});

it('links to bookmarks and to the web', function () {
    expect(html('<p><a href="#target">go</a></p><p id="target">here</p>'))
        ->toContain('<a href="#target">go</a>')
        ->toContain('<a id="target"></a>here');

    expect(html('<p><a href="https://example.com/a?b=1">site</a></p>'))
        ->toContain('href="https://example.com/a?b=1"');
});

it('prefixes generated ids so several documents can share a page', function () {
    expect(html('<p><a href="#target">go</a></p><p id="target">here</p>', testOptions(['idPrefix' => 'doc1-'])))
        ->toContain('href="#doc1-target"')
        ->toContain('id="doc1-target"');
});

it('writes lengths in the configured unit', function () {
    expect(html('<p style="margin-top: 30px">x</p>', testOptions(['cssUnit' => 'pt'])))
        ->toContain('margin: 24.05pt 0 5.95pt 0;');
});

it('wraps the content in a full document when asked', function () {
    $html = html('<p>x</p>', testOptions(['fullHtmlDocument' => true, 'language' => 'ru-RU']));

    expect($html)
        ->toStartWith("<!DOCTYPE html>\n<html lang=\"ru-RU\">")
        ->toContain('<meta charset="utf-8">')
        ->toContain('<body class="sun-editor-editable">')
        ->toContain('<style>')
        ->toEndWith("</body>\n</html>\n");
});

it('converts a whole editor document back and forth', function () {
    $once = roundTrip(sunEditorFixture());

    expect(markup($once))
        ->toContain('<h1>Квартальный отчёт</h1>')
        ->toContain('<table class="se-table-size-100 se-table-layout-fixed"')
        ->toContain('se-image-container')
        ->toContain('<li')
        ->toContain('href="#table"');

    // A second pass changes nothing: what the writer emits is what the reader reads.
    expect(roundTrip($once))->toBe($once);
});

it('draws a styled underline on the element that draws the line', function () {
    expect(roundTrip('<p><u style="text-decoration-style: double">twice</u></p>'))
        ->toEndWith('"><u style="text-decoration-style: double;">twice</u></p>');
});
