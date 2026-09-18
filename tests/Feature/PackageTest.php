<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\Exceptions\HtmlDocxException;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Tests\Support\Docx;
use Kovami\HtmlDocx\Tests\Support\TestImage;

it('produces a sound package for empty input', function (string $html) {
    $docx = docx($html);

    expect($docx->count('/w:document/w:body/w:p'))->toBeGreaterThanOrEqual(1);
})->with(['empty string' => '', 'whitespace' => "  \n\t ", 'only comments' => '<!-- nothing -->']);

it('writes only the parts it needs', function () {
    expect(array_keys(docx('<p>text</p>')->parts))->toBe([
        '[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'docProps/app.xml',
        'word/document.xml', 'word/styles.xml', 'word/settings.xml', 'word/_rels/document.xml.rels',
    ]);

    expect(docx('<ul><li>a</li></ul>')->has('word/numbering.xml'))->toBeTrue();
});

it('is deterministic for a fixed creation time', function () {
    $converter = new HtmlDocx(testOptions());
    $html = '<h1>Title</h1><p>Body <img src="'.TestImage::pngDataUri(4, 4).'"></p><ol><li>x</li></ol>';

    expect($converter->htmlToDocx($html))->toBe($converter->htmlToDocx($html));
});

it('writes the same bytes to a string, a file and a stream', function () {
    $converter = new HtmlDocx(testOptions());
    $html = '<p>Same everywhere</p>';
    $path = tempnam(sys_get_temp_dir(), 'kovami');
    $stream = fopen('php://temp', 'w+b');

    try {
        expect($converter->htmlToDocxFile($html, $path))->toBe($path);
        $converter->htmlToDocxStream($html, $stream);
        rewind($stream);

        expect(file_get_contents($path))
            ->toBe($converter->htmlToDocx($html))
            ->toBe(stream_get_contents($stream));
    } finally {
        @unlink($path);
        fclose($stream);
    }
});

it('reports an unwritable destination', function () {
    (new HtmlDocx)->htmlToDocxFile('<p>x</p>', '/nonexistent-dir/out.docx');
})->throws(HtmlDocxException::class);

it('writes document metadata', function () {
    $docx = docx('<title>From HTML</title><p>x</p>', new HtmlDocx(testOptions(['author' => 'Jane', 'language' => 'ru-RU'])));

    expect($docx->first('//dc:title', null, 'docProps/core.xml')->textContent)->toBe('From HTML')
        ->and($docx->first('//dc:creator', null, 'docProps/core.xml')->textContent)->toBe('Jane')
        ->and($docx->first('//dcterms:created', null, 'docProps/core.xml')->textContent)->toBe('2026-01-02T03:04:05Z')
        ->and($docx->val('//w:docDefaults//w:lang', null, 'word/styles.xml'))->toBe('ru-RU');

    $titled = docx('<title>From HTML</title><p>x</p>', new HtmlDocx(testOptions(['title' => 'Explicit'])));
    expect($titled->first('//dc:title', null, 'docProps/core.xml')->textContent)->toBe('Explicit');
});

it('applies the page layout to the section', function () {
    $docx = docx('<p>x</p>', null, PageLayout::a4Landscape()->withMargins(1, 2, 3, 4));
    $size = $docx->first('//w:sectPr/w:pgSz');
    $margins = $docx->first('//w:sectPr/w:pgMar');

    expect(Docx::attr($size, 'w'))->toBe('16838')
        ->and(Docx::attr($size, 'h'))->toBe('11906')
        ->and(Docx::attr($size, 'orient'))->toBe('landscape')
        ->and(Docx::attr($margins, 'top'))->toBe('567')
        ->and(Docx::attr($margins, 'right'))->toBe('1134')
        ->and(Docx::attr($margins, 'bottom'))->toBe('1701')
        ->and(Docx::attr($margins, 'left'))->toBe('2268');
});

it('uses default typography from the options', function () {
    $docx = docx('<p>x</p>', new HtmlDocx(testOptions(['fontFamily' => 'Arial', 'fontSizePt' => 12.5, 'textColor' => '#333333'])));

    expect(Docx::attr($docx->first('//w:docDefaults//w:rFonts', null, 'word/styles.xml'), 'ascii'))->toBe('Arial')
        ->and($docx->val('//w:docDefaults//w:sz', null, 'word/styles.xml'))->toBe('25')
        ->and($docx->val('//w:docDefaults//w:color', null, 'word/styles.xml'))->toBe('333333')
        ->and($docx->first('//w:r/w:rPr/w:rFonts'))->toBeNull();
});

it('keeps a converter reusable without leaking state between documents', function () {
    $converter = new HtmlDocx(testOptions());

    docx('<ol><li>a</li></ol><p id="x"><a href="#x">self</a></p>', $converter);
    $second = docx('<ol><li>b</li></ol>', $converter);

    expect($second->count('//w:num', null, 'word/numbering.xml'))->toBe(1)
        ->and($second->count('//w:bookmarkStart'))->toBe(0);
});

it('survives malformed markup the way browsers do', function () {
    $docx = docx('<p>open <b>bold <i>both</p><div>after</span></div><table><td>cell</table>');

    expect($docx->paragraphTexts())->toContain('open bold both', 'after')
        ->and($docx->count('//w:tbl'))->toBe(1);
});

it('removes characters XML cannot carry', function () {
    $docx = docx("<p>a\u{0001}b\u{000B}c</p>");

    expect($docx->paragraphTexts())->toBe(['abc']);
});

it('converts a large document', function () {
    $html = str_repeat('<p>Paragraph with <b>bold</b> text.</p><ul><li>item</li></ul>', 500);

    expect(docx($html)->count('/w:document/w:body/w:p'))->toBe(1000);
});
