<?php

declare(strict_types=1);

it('collapses whitespace like a browser', function (string $html, array $expected) {
    expect(docx($html)->paragraphTexts())->toBe($expected);
})->with([
    'runs of spaces and newlines' => ["<p>  a \n\n  b\t c  </p>", ['a b c']],
    'across element boundaries' => ['<p>a <b> b </b> <i> c</i></p>', ['a b c']],
    'indentation between blocks' => ["<div>\n  <p>one</p>\n  <p>two</p>\n</div>", ['one', 'two']],
    'space before a line break' => ['<p>a   <br>   b</p>', ["a\nb"]],
    'non-breaking spaces survive' => ['<p>a&nbsp;&nbsp;b</p>', ["a\u{00A0}\u{00A0}b"]],
    'text directly in body' => ['loose <em>text</em>', ['loose text']],
]);

it('keeps words separated by whitespace-only nodes between inline elements', function () {
    expect(docx('<p><b>A</b> <i>B</i></p>')->paragraphTexts())->toBe(['A B']);
});

it('renders <br> as line breaks inside one paragraph', function () {
    $docx = docx('<p>one<br>two<br><br>four</p>');

    expect($docx->paragraphTexts())->toBe(["one\ntwo\n\nfour"])
        ->and($docx->count('//w:br'))->toBe(3);
});

it('ignores a trailing <br> the way block rendering does', function () {
    expect(docx('<p>text<br></p>')->count('//w:br'))->toBe(0);
});

it('keeps SunEditor empty lines as empty paragraphs', function () {
    $docx = docx('<p>first</p><p><br></p><p>third</p>');

    expect($docx->count('/w:document/w:body/w:p'))->toBe(3)
        ->and($docx->text($docx->paragraphs()[1]))->toBe('');
});

it('drops truly empty blocks', function () {
    expect(docx('<p>a</p><p></p><div> </div><p>b</p>')->count('/w:document/w:body/w:p'))->toBe(2);
});

it('preserves whitespace, newlines and tabs in <pre>', function () {
    $docx = docx("<pre>line 1\n    indented\tafter tab</pre>");

    expect($docx->paragraphTexts())->toBe(["line 1\n    indented\tafter tab"])
        ->and($docx->count('//w:tab'))->toBe(1);
});

it('keeps newlines but collapses spaces for white-space: pre-line', function () {
    expect(docx("<p style=\"white-space: pre-line\">a   b\nc</p>")->paragraphTexts())->toBe(["a b\nc"]);
});

it('preserves Unicode text', function () {
    $text = 'Привет, мир! Ünïcödé — 中文 — עברית — 😀👍🏽';

    expect(docx("<p>{$text}</p>")->paragraphTexts())->toBe([$text]);
});

it('decodes HTML entities', function () {
    expect(docx('<p>&lt;tag&gt; &amp; &quot;q&quot; &copy; &#8212; &#x1F600;</p>')->paragraphTexts())
        ->toBe(['<tag> & "q" © — 😀']);
});

it('applies text-transform', function () {
    $docx = docx('<p><span style="text-transform: uppercase">up</span> <span style="text-transform: lowercase">LOW</span> <span style="text-transform: capitalize">cap words</span></p>');

    expect($docx->paragraphTexts())->toBe(['up low Cap Words'])
        ->and($docx->first('w:rPr/w:caps', $docx->run('up')))->not->toBeNull();
});

it('renders <q> with quotation marks', function () {
    expect(docx('<p>He said <q>hi</q>.</p>')->paragraphTexts())->toBe(["He said \u{201C}hi\u{201D}."]);
});

it('writes checkboxes as ballot box characters', function () {
    expect(docx('<p><input type="checkbox" checked> done <input type="checkbox"> todo</p>')->paragraphTexts())
        ->toBe(["\u{2611} done \u{2610} todo"]);
});

it('skips non-content elements', function () {
    $docx = docx('<head><title>t</title></head><script>alert(1)</script><style>p{}</style><template><p>x</p></template><p>visible</p><p hidden>hidden</p><p style="display:none">none</p>');

    expect($docx->paragraphTexts())->toBe(['visible']);
});

it('writes KaTeX formulas from SunEditor as Office Math', function () {
    $html = '<p>Formula: <span class="__se__katex katex" data-exp="E=mc^2" data-font-size="1em"><span class="katex-mathml">garbage</span></span></p>';
    $docx = docx($html);

    expect($docx->paragraphTexts())->toBe(['Formula: '])
        ->and($docx->count('//m:oMath'))->toBe(1)
        // Word lays the expression out: E, then m with c squared.
        ->and($docx->count('//m:oMath//m:sSup'))->toBe(1)
        ->and($docx->first('//m:oMath//m:sup//m:t')?->textContent)->toBe('2');
});

it('gives a paragraph the room a browser grows its line by to hold a superscript', function () {
    // Word keeps the line and moves only the glyphs; the browser's taller line would push the rest down.
    $spacing = static fn(string $html, ?Kovami\HtmlDocx\Editor $editor = null): int => (int) Kovami\HtmlDocx\Tests\Support\Docx::attr(
        docx($html, $editor === null ? Kovami\HtmlDocx\HtmlDocx::plain(testOptions()) : Kovami\HtmlDocx\HtmlDocx::for($editor, testOptions()))->first('//w:p/w:pPr/w:spacing'),
        'before',
    );

    expect($spacing('<p style="margin: 0">E = mc<sup>2</sup></p>') - $spacing('<p style="margin: 0">E = mc2</p>'))->toBeGreaterThan(40)
        // SunEditor's stylesheet gives scripts line-height: 0, so its lines do not grow.
        ->and($spacing('<p style="margin: 0">E = mc<sup>2</sup></p>', Kovami\HtmlDocx\Editor::SunEditor))
        ->toBe($spacing('<p style="margin: 0">E = mc2</p>', Kovami\HtmlDocx\Editor::SunEditor));
});
