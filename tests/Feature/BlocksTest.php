<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Tests\Support\Docx;

it('maps headings to Word heading styles with outline levels', function (int $level) {
    $docx = docx("<h{$level}>Heading {$level}</h{$level}>");

    expect($docx->val('w:pPr/w:pStyle', $docx->paragraph("Heading {$level}")))->toBe("Heading{$level}");

    $style = $docx->first("//w:style[@w:styleId='Heading{$level}']", null, 'word/styles.xml');

    expect($docx->val('w:name', $style, 'word/styles.xml'))->toBe("heading {$level}")
        ->and($docx->val('w:pPr/w:outlineLvl', $style, 'word/styles.xml'))->toBe((string) ($level - 1))
        ->and($docx->first('w:pPr/w:keepNext', $style, 'word/styles.xml'))->not->toBeNull()
        ->and($docx->first('w:rPr/w:b', $style, 'word/styles.xml'))->not->toBeNull();
})->with([1, 2, 3, 4, 5, 6]);

it('sizes headings like the browser defaults', function () {
    $docx = docx('<h1>x</h1>');

    expect($docx->val("//w:style[@w:styleId='Heading1']/w:rPr/w:sz", null, 'word/styles.xml'))->toBe('44');
});

it('lets author CSS override heading styles as direct formatting', function () {
    $docx = docx('<style>h2 { color: navy; }</style><h2>Styled</h2>');

    expect($docx->val('w:rPr/w:color', $docx->run('Styled')))->toBe('000080');
});

it('maps text-align', function (string $css, string $expected) {
    $docx = docx("<p style=\"text-align: {$css}\">aligned</p>");

    expect($docx->val('w:pPr/w:jc', $docx->paragraph('aligned')))->toBe($expected);
})->with([['left', 'left'], ['center', 'center'], ['right', 'right'], ['justify', 'both'], ['end', 'right']]);

it('inherits text-align from containers and the legacy align attribute', function () {
    $docx = docx('<div style="text-align: right"><p>inherited</p></div><p align="center">attribute</p><center>center tag</center>');

    expect($docx->val('w:pPr/w:jc', $docx->paragraph('inherited')))->toBe('right')
        ->and($docx->val('w:pPr/w:jc', $docx->paragraph('attribute')))->toBe('center')
        ->and($docx->val('w:pPr/w:jc', $docx->paragraph('center tag')))->toBe('center');
});

it('turns SunEditor indentation into paragraph indentation', function () {
    $docx = docx('<p style="margin-left: 25px;">one</p><p style="margin-left: 50px; margin-right: 1cm">two</p>');

    expect(Docx::attr($docx->first('w:pPr/w:ind', $docx->paragraph('one')), 'left'))->toBe('375')
        ->and(Docx::attr($docx->first('w:pPr/w:ind', $docx->paragraph('two')), 'left'))->toBe('750')
        ->and(Docx::attr($docx->first('w:pPr/w:ind', $docx->paragraph('two')), 'right'))->toBe('567');
});

it('accumulates indentation of nested containers', function () {
    $docx = docx('<div style="margin-left: 10pt"><div style="padding-left: 10pt"><p style="margin-left: 10pt">deep</p></div></div>');

    expect(Docx::attr($docx->first('w:pPr/w:ind', $docx->paragraph('deep')), 'left'))->toBe('600');
});

it('maps text-indent to first-line and hanging indentation', function () {
    $docx = docx('<p style="text-indent: 1cm">first</p><p style="text-indent: -18pt; margin-left: 36pt">hanging</p>');

    expect(Docx::attr($docx->first('w:pPr/w:ind', $docx->paragraph('first')), 'firstLine'))->toBe('567')
        ->and(Docx::attr($docx->first('w:pPr/w:ind', $docx->paragraph('hanging')), 'hanging'))->toBe('360');
});

it('maps line-height to the line Word draws, a multiple of the font\'s own single line', function (string $css, string $line, string $rule) {
    $spacing = docx("<p style=\"{$css}\">lh</p>")->first('//w:p/w:pPr/w:spacing');

    expect(Docx::attr($spacing, 'line'))->toBe($line)
        ->and(Docx::attr($spacing, 'lineRule'))->toBe($rule);
})->with([
    // Calibri's single line is 1.2207 times its size: 2 × size is 1.64 lines.
    'unitless' => ['line-height: 2', '393', 'auto'],
    'percent' => ['line-height: 150%', '295', 'auto'],
    'a font without metrics' => ['font-family: Fancy; line-height: 2', '480', 'auto'],
    'length' => ['line-height: 20pt', '400', 'atLeast'],
]);

it('maps vertical margins to spacing, which Word collapses the way CSS does', function () {
    $docx = docx('<p style="margin: 0 0 20pt">a</p><p style="margin: 30pt 0 0">b</p><p style="margin-top: 10pt">c</p>');

    expect(Docx::attr($docx->first('w:pPr/w:spacing', $docx->paragraph('a')), 'after'))->toBe('400')
        ->and(Docx::attr($docx->first('w:pPr/w:spacing', $docx->paragraph('b')), 'before'))->toBe('600')
        ->and(Docx::attr($docx->first('w:pPr/w:spacing', $docx->paragraph('c')), 'before'))->toBe('200');
});

it('uses SunEditor paragraph spacing by default', function () {
    // Some of it is spent above the text, to set it where SunEditor shows it; the paragraph is as tall.
    $spacing = docx('<p>p</p>')->first('//w:p/w:pPr/w:spacing');

    expect((int) Docx::attr($spacing, 'before') + (int) Docx::attr($spacing, 'after'))->toBe(150);
});

it('renders blockquotes with a left border and indentation', function () {
    $docx = docx('<blockquote><p>quoted one</p><p>quoted two</p></blockquote>');

    foreach (['quoted one', 'quoted two'] as $text) {
        $paragraph = $docx->paragraph($text);
        $border = $docx->first('w:pPr/w:pBdr/w:left', $paragraph);

        expect(Docx::attr($border, 'val'))->toBe('single')
            ->and(Docx::attr($border, 'color'))->toBe('B1B1B1')
            ->and((int) Docx::attr($docx->first('w:pPr/w:ind', $paragraph), 'left'))->toBeGreaterThan(0)
            ->and($docx->val('w:rPr/w:color', $docx->run($text)))->toBe('999999');
    }
});

it('renders <pre> as a shaded, bordered box in a monospace font', function () {
    $docx = docx('<pre>code</pre>');
    $paragraph = $docx->paragraph('code');

    expect(Docx::attr($docx->first('w:pPr/w:shd', $paragraph), 'fill'))->toBe('F9F9F9')
        ->and($docx->count('w:pPr/w:pBdr/*', $paragraph))->toBe(4)
        ->and(Docx::attr($docx->first('w:rPr/w:rFonts', $docx->run('code')), 'ascii'))->toBe('Courier New');
});

it('maps CSS borders on paragraphs', function () {
    $docx = docx('<p class="__se__p-bordered">bordered</p><p style="border-bottom: 3px dashed red; padding-bottom: 4pt">dashed</p>');

    $sunEditor = $docx->first('w:pPr/w:pBdr', $docx->paragraph('bordered'));
    $dashed = $docx->first('w:pPr/w:pBdr/w:bottom', $docx->paragraph('dashed'));

    expect($docx->first('w:top', $sunEditor))->not->toBeNull()
        ->and($docx->first('w:bottom', $sunEditor))->not->toBeNull()
        ->and(Docx::attr($dashed, 'val'))->toBe('dashed')
        ->and(Docx::attr($dashed, 'sz'))->toBe('18')
        ->and(Docx::attr($dashed, 'color'))->toBe('FF0000')
        ->and(Docx::attr($dashed, 'space'))->toBe('4');
});

it('renders horizontal rules as a bottom-bordered paragraph', function (string $class, string $style) {
    $docx = docx("<p>above</p><hr class=\"{$class}\"><p>below</p>");

    expect($docx->count('/w:document/w:body/w:p'))->toBe(3)
        ->and(Docx::attr($docx->first('//w:pBdr/w:bottom'), 'val'))->toBe($style);
})->with([['__se__solid', 'single'], ['__se__dotted', 'dotted'], ['__se__dashed', 'dashed']]);

it('keeps the room a horizontal rule\'s height leaves under its line', function () {
    // SunEditor's hr is 20px tall with no margin below: 15pt under the line.
    $docx = docx('<p>above</p><hr><p>below</p>');

    $spacing = $docx->first('//w:pBdr/w:bottom/../../w:spacing');

    // The rule is its border alone, as in a browser: its line is one twip, exactly.
    expect(Docx::attr($spacing, 'after'))->toBe('300')
        ->and(Docx::attr($spacing, 'line'))->toBe('1')
        ->and(Docx::attr($spacing, 'lineRule'))->toBe('exact');
});

it('spaces a generic monospace block by the font a browser draws for it, not the one Word gets', function () {
    // Chromium on macOS draws monospace in Courier, whose normal line is 1.15em; Word gets Courier New, 1.1328em.
    $docx = docx('<p style="font-family: monospace">code</p>', HtmlDocx::plain(testOptions()));
    $spacing = $docx->first('w:pPr/w:spacing', $docx->paragraph('code'));

    expect(Docx::attr($docx->first('w:rPr/w:rFonts', $docx->run('code')), 'ascii'))->toBe('Courier New')
        ->and(Docx::attr($spacing, 'line'))->toBe('244');
});

it('honours page breaks', function () {
    $docx = docx('<p>one</p><p style="page-break-before: always">two</p><p style="break-after: page">three</p><p>four</p>');

    expect($docx->first('w:pPr/w:pageBreakBefore', $docx->paragraph('two')))->not->toBeNull()
        ->and($docx->first('w:pPr/w:pageBreakBefore', $docx->paragraph('four')))->not->toBeNull()
        ->and($docx->first('w:pPr/w:pageBreakBefore', $docx->paragraph('three')))->toBeNull();
});

it('splits mixed inline and block content into separate paragraphs', function () {
    expect(docx('<div>before<p>inside</p>after <b>bold</b></div>')->paragraphTexts())
        ->toBe(['before', 'inside', 'after bold']);
});

it('splits inline elements that contain blocks', function () {
    expect(docx('<span>a<div>b</div>c</span>')->paragraphTexts())->toBe(['a', 'b', 'c']);
});

it('renders figure captions with the Caption style', function () {
    $docx = docx('<figure><figcaption>A caption</figcaption></figure>');

    expect($docx->val('w:pPr/w:pStyle', $docx->paragraph('A caption')))->toBe('Caption')
        ->and($docx->val('w:pPr/w:jc', $docx->paragraph('A caption')))->toBe('center');
});

it('renders definition lists', function () {
    $docx = docx('<dl><dt>Term</dt><dd>Definition</dd></dl>');

    expect($docx->first('w:rPr/w:b', $docx->run('Term')))->not->toBeNull()
        ->and(Docx::attr($docx->first('w:pPr/w:ind', $docx->paragraph('Definition')), 'left'))->toBe('600');
});

it('keeps a usable text width inside deeply nested boxes', function () {
    $html = str_repeat('<blockquote>', 40) . 'deep' . str_repeat('</blockquote>', 40);
    $docx = docx($html);
    $ind = $docx->first('w:pPr/w:ind', $docx->paragraph('deep'));
    $contentWidth = PageLayout::a4Portrait()->contentWidthTwips();

    expect($contentWidth - (int) Docx::attr($ind, 'left') - (int) Docx::attr($ind, 'right'))->toBeGreaterThanOrEqual(1440)
        ->and((int) Docx::attr($ind, 'left'))->toBeGreaterThan(3000);
});

it('leaves moderate nesting indents untouched', function () {
    $html = str_repeat('<div style="margin-left: 1in">', 5) . 'five' . str_repeat('</div>', 5);

    expect(Docx::attr(docx($html)->first('//w:p/w:pPr/w:ind'), 'left'))->toBe('7200');
});

it('indents a quotation the way the editor showing it does', function (?Kovami\HtmlDocx\Editor $editor, int $indent, bool $rule, bool $italic) {
    $converter = $editor === null ? Kovami\HtmlDocx\HtmlDocx::plain(testOptions()) : Kovami\HtmlDocx\HtmlDocx::for($editor, testOptions());
    $paragraph = $converter->fromHtml('<blockquote><p>quoted</p></blockquote>')->document()->blocks[0];

    expect($paragraph->properties->indentLeft)->toBe($indent)
        ->and($paragraph->properties->borders->left !== null)->toBe($rule)
        ->and($paragraph->children[0]->properties->italic)->toBe($italic);
})->with([
    'a browser: 40px on both sides' => [null, 600, false, false],
    'CKEditor: a rule and 1.5em' => [Kovami\HtmlDocx\Editor::CKEditor, 405, true, true],
]);

it('leaves the padding under a border to the border, which takes that room in Word', function () {
    // Measured in Word: a border's space adds to the paragraph's height above
    // and below the text, as CSS padding does, on top of its spacing.
    $bordered = docx('<p style="margin: 0; border: 1px solid #000; padding: 8px">boxed</p>', Kovami\HtmlDocx\HtmlDocx::plain(testOptions()));
    $padded = docx('<p style="margin: 0; padding: 8px 0">padded</p>', Kovami\HtmlDocx\HtmlDocx::plain(testOptions()));
    $html = Kovami\HtmlDocx\HtmlDocx::plain(testOptions())->fromDocx($bordered->bytes)->toHtml();

    expect(Docx::attr($bordered->first('//w:pBdr/w:top'), 'space'))->toBe('6')
        ->and((int) Docx::attr($bordered->first('//w:p/w:pPr/w:spacing'), 'before'))->toBeLessThan(40)
        ->and((int) Docx::attr($padded->first('//w:p/w:pPr/w:spacing'), 'before'))->toBeGreaterThanOrEqual(120)
        ->and($html)->toContain('padding-top: 8px;');
});

it('spaces bold Helvetica Neue by its own single line, taller than the regular face\'s', function () {
    // SunEditor's h1 is 24pt bold at line-height 1.5, 36pt: Word's single line is 1.221em in the bold face, 1.193em in the regular.
    $docx = docx('<h1>Bold</h1><p>Regular</p>', converter(['fontFamily' => 'Helvetica Neue', 'fontSizePt' => 12.0]));

    expect(Docx::attr($docx->first('w:pPr/w:spacing', $docx->paragraph('Bold')), 'line'))->toBe('295')
        ->and(Docx::attr($docx->first('w:pPr/w:spacing', $docx->paragraph('Regular')), 'line'))->toBe('302');
});
