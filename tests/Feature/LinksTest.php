<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Tests\Support\Docx;

function hyperlinkTarget(Docx $docx, DOMElement $hyperlink): ?string
{
    $id = $hyperlink->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');

    return $docx->first("//rel:Relationship[@Id='{$id}']", null, 'word/_rels/document.xml.rels')?->getAttribute('Target');
}

it('creates external hyperlinks', function () {
    $docx = docx('<p>Visit <a href="https://example.com/path?q=1&amp;x=2">our site</a>.</p>');
    $hyperlink = $docx->first('//w:hyperlink');

    expect(hyperlinkTarget($docx, $hyperlink))->toBe('https://example.com/path?q=1&x=2')
        ->and($docx->text($hyperlink))->toBe('our site')
        ->and($docx->val('w:rPr/w:color', $docx->run('our site')))->toBe('004CFF')
        ->and($docx->val('w:rPr/w:u', $docx->run('our site')))->toBe('single')
        ->and($docx->paragraphTexts())->toBe(['Visit our site.']);
});

it('keeps formatting inside links as separate runs of one hyperlink', function () {
    $docx = docx('<p><a href="https://example.com">plain <b>bold</b> <i>italic</i></a></p>');

    expect($docx->count('//w:hyperlink'))->toBe(1)
        ->and($docx->count('//w:hyperlink/w:r'))->toBeGreaterThanOrEqual(3)
        ->and($docx->first('w:rPr/w:b', $docx->run('bold')))->not->toBeNull();
});

it('reuses one relationship per URL', function () {
    $docx = docx('<p><a href="https://a.test">one</a> <a href="https://a.test">two</a> <a href="https://b.test">three</a></p>');

    expect($docx->count("//rel:Relationship[contains(@Type, '/hyperlink')]", null, 'word/_rels/document.xml.rels'))->toBe(2);
});

it('percent-encodes characters that are not valid in a URI', function () {
    $docx = docx('<p><a href="https://example.com/a b/путь">link</a></p>');

    expect(hyperlinkTarget($docx, $docx->first('//w:hyperlink')))->toBe('https://example.com/a%20b/%D0%BF%D1%83%D1%82%D1%8C');
});

it('supports mailto and tel links', function () {
    $docx = docx('<p><a href="mailto:a@b.test">mail</a> <a href="tel:+100">call</a></p>');
    $targets = array_map(static fn (DOMElement $h): ?string => hyperlinkTarget($docx, $h), $docx->query('//w:hyperlink'));

    expect($targets)->toBe(['mailto:a@b.test', 'tel:+100']);
});

it('drops dangerous link targets but keeps the text', function (string $href) {
    $docx = docx("<p><a href=\"{$href}\">text</a></p>");

    expect($docx->count('//w:hyperlink'))->toBe(0)
        ->and($docx->paragraphTexts())->toBe(['text']);
})->with(['javascript:alert(1)', ' JavaScript:void(0)', 'vbscript:x', 'data:text/html,x', 'file:///etc/passwd']);

it('renders anchors without href as plain text', function () {
    $docx = docx('<p><a name="x">named</a> and <a>bare</a></p>');

    expect($docx->count('//w:hyperlink'))->toBe(0)
        ->and($docx->paragraphTexts())->toBe(['named and bare']);
});

it('links to bookmarks for in-document anchors', function () {
    $docx = docx('<p><a href="#details">jump</a></p><h2 id="details">Details</h2><p><a href="#legacy">old</a></p><p><a name="legacy"></a>Legacy target</p>');

    $toDetails = $docx->first("//w:hyperlink[.//w:t='jump']");
    $bookmark = $docx->first('w:bookmarkStart', $docx->paragraph('Details'));

    expect(Docx::attr($toDetails, 'anchor'))->toBe(Docx::attr($bookmark, 'name'))
        ->and(Docx::attr($bookmark, 'name'))->toStartWith('_')
        ->and($docx->first('w:bookmarkStart', $docx->paragraph('Legacy target')))->not->toBeNull();
});

it('creates bookmarks only for ids that links point to', function () {
    expect(docx('<p id="unused">x</p><p id="used">y</p><p><a href="#used">go</a></p>')->count('//w:bookmarkStart'))->toBe(1);
});

it('sanitizes bookmark names to what Word accepts', function () {
    $id = 'section 1: "introduction" — with a very long identifier that exceeds forty characters';
    $docx = docx('<p><a href="#'.rawurlencode($id).'">go</a></p><p id="'.htmlspecialchars($id).'">target</p>');
    $name = Docx::attr($docx->first('//w:bookmarkStart'), 'name');

    expect($name)->toMatch('/^_\w{1,39}$/');
});

it('keeps a link that spans a block break as a hyperlink on both sides', function () {
    $docx = docx('<a href="https://example.com">before<div>inside</div>after</a>');

    expect($docx->count('//w:hyperlink'))->toBe(2)
        ->and($docx->paragraphTexts())->toBe(['before', 'inside', 'after']);
});
