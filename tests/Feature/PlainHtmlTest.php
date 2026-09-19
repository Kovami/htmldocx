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
        '<p style="font-family: &quot;Times New Roman&quot;, &quot;Liberation Serif&quot;, Tinos, serif; font-size: 13.33px; color: #000000; margin: 0 0 10.67px 0; line-height: 1.322;">'
        . '<span style="font-family: Aptos, sans-serif; font-size: 16px;">Body</span></p>',
    );
});

it('scales Word\'s line spacing by the font\'s own single line', function (string $font, string $spacing, string $lineHeight) {
    $html = plainHtml(DocxBuilder::make()->body(
        "<w:p><w:pPr>{$spacing}<w:rPr><w:rFonts w:ascii=\"{$font}\"/></w:rPr></w:pPr><w:r><w:t>x</w:t></w:r></w:p>",
    ));

    expect($html)->toContain("line-height: {$lineHeight};");
})->with([
    'Calibri, single' => ['Calibri', '', '1.221'],
    'Calibri, 1.16' => ['Calibri', '<w:spacing w:line="278" w:lineRule="auto"/>', '1.414'],
    'an unmeasured font, single' => ['Fancy Script', '', 'normal'],
    'an unmeasured font, double' => ['Fancy Script', '<w:spacing w:line="480" w:lineRule="auto"/>', '2'],
    'exactly 18 pt' => ['Calibri', '<w:spacing w:line="360" w:lineRule="exact"/>', '24px'],
]);

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

it('narrows the spaces of justified text, as Word squeezes them to fit a word', function () {
    $html = plainHtml(DocxBuilder::make()->body(
        '<w:p><w:pPr><w:jc w:val="both"/></w:pPr><w:r><w:t>Justified</w:t></w:r></w:p>'
        . '<w:p><w:r><w:t>Left</w:t></w:r></w:p>',
    ));

    expect($html)->toStartWith('<p style="text-align: justify; word-spacing: -0.065em;')
        ->and(substr_count($html, 'word-spacing'))->toBe(1);
});

it('draws table cells itself, over whatever borders an editor gives them', function () {
    $html = plainHtml(DocxBuilder::make()->body(
        '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/></w:tblPr><w:tblGrid><w:gridCol w:w="9355"/></w:tblGrid>'
        . '<w:tr><w:tc><w:p><w:r><w:t>cell</w:t></w:r></w:p></w:tc></w:tr></w:tbl>',
    ));

    expect($html)->toContain('<table style="width: 100%; border: none; border-collapse: collapse; table-layout: fixed;')
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

it('leaves out headers, footers and comments, and says so', function () {
    $warnings = [];
    $html = plainHtml(
        DocxBuilder::make()
            ->headerFooter('header', 'rIdH1', 'header1.xml', '<w:p><w:r><w:t>Running head</w:t></w:r></w:p>')
            ->comments('<w:comment w:id="1" w:author="Ann"><w:p><w:r><w:t>Rephrase this.</w:t></w:r></w:p></w:comment>')
            ->body(
                '<w:p><w:commentRangeStart w:id="1"/><w:r><w:t>Body</w:t></w:r><w:commentRangeEnd w:id="1"/>'
                . '<w:r><w:commentReference w:id="1"/></w:r></w:p>',
            )
            ->section('<w:headerReference w:type="default" r:id="rIdH1"/><w:pgSz w:w="11906" w:h="16838"/>'),
        $warnings,
    );

    expect($html)->toContain('>Body</p>')
        ->and($html)->not->toContain('Running head')
        ->and($html)->not->toContain('Rephrase')
        ->and($html)->not->toContain('data-comment')
        ->and($warnings)->toBe([
            '1 page header(s) and footer(s) were left out: plain HTML has no pages',
            '1 comment(s) were left out: plain HTML has no place for them',
        ]);
});

it('keeps the value a page field last showed, as text', function () {
    $warnings = [];
    $html = plainHtml(DocxBuilder::make()->body(
        '<w:p><w:r><w:t xml:space="preserve">Page </w:t></w:r><w:fldSimple w:instr=" PAGE "><w:r><w:t>4</w:t></w:r></w:fldSimple>'
        . '<w:r><w:t xml:space="preserve"> and </w:t></w:r><w:fldSimple w:instr=" PAGE "><w:r><w:t>4</w:t></w:r></w:fldSimple></w:p>',
    ), $warnings);

    expect($html)->toContain('>Page 4 and 4</p>')
        ->and($warnings)->toBe(['The PAGE field became the text "4": plain HTML has no pages']);
});

it('marks notes the way DPUB-ARIA does and reads them back as notes', function () {
    $converter = HtmlDocx::plain(testOptions());
    $html = plainHtml(
        DocxBuilder::make()
            ->notes('footnote', '<w:footnote w:id="1"><w:p><w:r><w:t>A note.</w:t></w:r></w:p></w:footnote>')
            ->body('<w:p><w:r><w:t>Text</w:t></w:r><w:r><w:rPr><w:vertAlign w:val="superscript"/></w:rPr><w:footnoteReference w:id="1"/></w:r></w:p>'),
    );

    expect($html)->toContain('<a href="#footnote-1" id="footnote-ref-1" role="doc-noteref">1</a>')
        ->and($html)->toContain('<section class="footnotes" role="doc-endnotes"><hr')
        ->and($html)->toContain('<li id="footnote-1" role="doc-endnote">')
        ->and($html)->toContain('<a href="#footnote-ref-1" role="doc-backlink"> ↩</a>')
        ->and($html)->not->toContain('se-footnotes');

    $document = $converter->fromHtml($html)->document();

    expect($document->notes)->toHaveCount(1)
        ->and($document->notes[0]->type)->toBe('footnote');
});

it('writes formulas as MathML with their LaTeX, and reads the LaTeX back', function () {
    $converter = HtmlDocx::plain(testOptions());
    $html = $converter->fromHtml('<p><span class="__se__katex katex" data-exp="x^2 + \frac{1}{2} = 3.14">x</span></p>')->toHtml();

    expect($html)->toContain(
        '<math><semantics><mrow><msup><mrow><mi>x</mi></mrow><mrow><mn>2</mn></mrow></msup><mo>+</mo>'
        . '<mfrac><mrow><mn>1</mn></mrow><mrow><mn>2</mn></mrow></mfrac><mo>=</mo><mn>3.14</mn></mrow>'
        . '<annotation encoding="application/x-tex">x^2 + \frac{1}{2} = 3.14</annotation></semantics></math>',
    )->and($html)->not->toContain('katex');

    $formula = $converter->fromHtml($html)->document()->blocks[0]->children[0];

    expect($formula)->toBeInstanceOf(Kovami\HtmlDocx\Model\Formula::class)
        ->and($formula->latex)->toBe('x^2 + \frac{1}{2} = 3.14');
});

it('writes each kind of formula node as MathML', function (string $latex, string $mathMl) {
    $html = HtmlDocx::plain(testOptions())
        ->fromHtml('<p><span class="__se__katex katex" data-exp="' . htmlspecialchars($latex) . '">x</span></p>')
        ->toHtml();

    preg_match('~<semantics>(.*)<annotation~', $html, $match);

    expect($match[1])->toBe("<mrow>{$mathMl}</mrow>");
})->with([
    'root' => ['\sqrt[3]{y}', '<mroot><mrow><mi>y</mi></mrow><mrow><mn>3</mn></mrow></mroot>'],
    'sum' => ['\sum_{i=1}^{n} i', '<munderover><mrow><mo>∑</mo></mrow><mrow><mi>i</mi><mo>=</mo><mn>1</mn></mrow><mrow><mi>n</mi></mrow></munderover><mi>i</mi>'],
    'function' => ['\sin x', '<mi>sin</mi><mo>⁡</mo><mi>x</mi>'],
    'accent' => ['\hat{a}', '<mover accent="true"><mrow><mi>a</mi></mrow><mrow><mo>^</mo></mrow></mover>'],
    'text' => ['\text{if } x', '<mtext>if </mtext><mi>x</mi>'],
    'matrix' => ['\begin{pmatrix} 1 & 0 \end{pmatrix}', '<mo>(</mo><mrow><mtable><mtr><mtd><mrow><mn>1</mn></mrow></mtd><mtd><mrow><mn>0</mn></mrow></mtd></mtr></mtable></mrow><mo>)</mo>'],
]);

it('names free fonts with the same metrics, for systems without Word\'s', function () {
    $html = plainHtml(DocxBuilder::make()->body(
        '<w:p><w:pPr><w:rPr><w:rFonts w:ascii="Calibri"/></w:rPr></w:pPr><w:r><w:rPr><w:rFonts w:ascii="Calibri"/></w:rPr><w:t>a</w:t></w:r>'
        . '<w:r><w:rPr><w:rFonts w:ascii="Cambria"/></w:rPr><w:t>b</w:t></w:r><w:r><w:rPr><w:rFonts w:ascii="Fancy Script"/></w:rPr><w:t>c</w:t></w:r></w:p>',
    ));

    expect($html)->toContain('font-family: Calibri, Carlito, sans-serif;')
        ->and($html)->toContain('<span style="font-family: Cambria, Caladea, serif;">b</span>')
        ->and($html)->toContain('<span style="font-family: &quot;Fancy Script&quot;;">c</span>');
});

it('keeps Word\'s tab stops in text that needs its tabs', function () {
    $html = plainHtml(DocxBuilder::make()->body('<w:p><w:r><w:t>a</w:t></w:r><w:r><w:tab/></w:r><w:r><w:t>b</w:t></w:r></w:p>'));

    expect($html)->toContain('white-space: pre-wrap; tab-size: 48px;');
});

it('keeps Word\'s page-break rules and reads them back', function () {
    $converter = HtmlDocx::plain(testOptions());
    $html = plainHtml(DocxBuilder::make()->body(
        '<w:p><w:pPr><w:keepNext/><w:keepLines/></w:pPr><w:r><w:t>Caption</w:t></w:r></w:p><w:p><w:r><w:t>Body</w:t></w:r></w:p>',
    ));
    $caption = $converter->fromHtml($html)->document()->blocks[0]->properties;

    expect($html)->toContain('break-after: avoid; break-inside: avoid;')
        ->and($caption->keepNext)->toBeTrue()
        ->and($caption->keepLines)->toBeTrue();
});

it('reads plain HTML of a Word document back to the same HTML', function (string $path) {
    $converter = HtmlDocx::plain(testOptions());
    $conversion = $converter->fromDocxFile($path);
    $html = $conversion->toHtml();
    // HTML has no page, so the way back is given the document's own.
    $again = $converter->fromDocx($converter->fromHtml($html, $conversion->document()->pageLayout)->toDocx())->toHtml();

    expect($again)->toBe($html);
})->with(fn(): array => glob(dirname(__DIR__, 2) . '/bench/fidelity/corpus/*.docx') ?: []);
