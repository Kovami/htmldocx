<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Tests\Support\Docx;

it('maps semantic formatting tags', function (string $html, string $property, ?string $value) {
    $docx = docx("<p>plain {$html}</p>");
    $element = $docx->first("w:rPr/w:{$property}", $docx->run('marked'));

    expect($element)->not->toBeNull()
        ->and(Docx::attr($element, 'val'))->toBe($value)
        ->and($docx->first('w:rPr', $docx->run('plain')))->toBeNull();
})->with([
    'strong' => ['<strong>marked</strong>', 'b', null],
    'b' => ['<b>marked</b>', 'b', null],
    'em' => ['<em>marked</em>', 'i', null],
    'i' => ['<i>marked</i>', 'i', null],
    'u' => ['<u>marked</u>', 'u', 'single'],
    'ins' => ['<ins>marked</ins>', 'u', 'single'],
    'del' => ['<del>marked</del>', 'strike', null],
    's' => ['<s>marked</s>', 'strike', null],
    'strike' => ['<strike>marked</strike>', 'strike', null],
    'sup' => ['<sup>marked</sup>', 'vertAlign', 'superscript'],
    'sub' => ['<sub>marked</sub>', 'vertAlign', 'subscript'],
    'mark' => ['<mark>marked</mark>', 'shd', 'clear'],
]);

it('combines nested formatting', function () {
    $docx = docx('<p><strong><em><u><del>all</del></u></em></strong></p>');
    $run = $docx->run('all');

    foreach (['b', 'i', 'u', 'strike'] as $property) {
        expect($docx->first("w:rPr/w:{$property}", $run))->not->toBeNull();
    }
});

it('propagates text decoration into nested elements', function () {
    $docx = docx('<p><u>under <b>bold</b></u></p>');

    expect($docx->val('w:rPr/w:u', $docx->run('bold')))->toBe('single');
});

it('can switch inherited bold and italic off again', function () {
    $docx = docx('<p><b>bold <span style="font-weight: normal">normal</span></b></p><h2>Heading <span style="font-weight:400">light</span></h2>');

    expect($docx->first('w:rPr/w:b', $docx->run('normal')))->toBeNull()
        ->and($docx->val('w:rPr/w:b', $docx->run('light')))->toBe('0');
});

it('maps SunEditor inline styles', function () {
    $html = '<p><span style="color: rgb(255, 0, 0); background-color: #ffe400; font-family: \'Times New Roman\', serif; font-size: 18px;">styled</span></p>';
    $run = ($docx = docx($html))->run('styled');

    expect($docx->val('w:rPr/w:color', $run))->toBe('FF0000')
        ->and(Docx::attr($docx->first('w:rPr/w:shd', $run), 'fill'))->toBe('FFE400')
        ->and(Docx::attr($docx->first('w:rPr/w:rFonts', $run), 'ascii'))->toBe('Times New Roman')
        ->and(Docx::attr($docx->first('w:rPr/w:rFonts', $run), 'cs'))->toBe('Times New Roman')
        ->and($docx->val('w:rPr/w:sz', $run))->toBe('27');
});

it('resolves font sizes in every CSS unit', function (string $size, string $halfPoints) {
    $docx = docx("<p><span style=\"font-size: {$size}\">sized</span></p>");

    expect($docx->val('w:rPr/w:sz', $docx->run('sized')))->toBe($halfPoints);
})->with([
    'pt' => ['14pt', '28'],
    'px' => ['16px', '24'],
    'em relative to parent' => ['2em', '44'],
    'rem relative to root' => ['1.5rem', '33'],
    'percent' => ['50%', '11'],
    'keyword' => ['x-large', '36'],
    'larger' => ['larger', '26'],
]);

it('resolves em against the parent chain', function () {
    $docx = docx('<div style="font-size: 20pt"><div style="font-size: 0.5em"><span style="font-size: 1.5em">chain</span></div></div>');

    expect($docx->val('w:rPr/w:sz', $docx->run('chain')))->toBe('30');
});

it('maps generic font families to concrete fonts', function () {
    $docx = docx('<p><code>code</code> <span style="font-family: serif">serif</span></p>');

    expect(Docx::attr($docx->first('w:rPr/w:rFonts', $docx->run('code')), 'ascii'))->toBe('Courier New')
        ->and(Docx::attr($docx->first('w:rPr/w:rFonts', $docx->run('serif')), 'ascii'))->toBe('Times New Roman');
});

it('maps underline styles', function (string $css, string $expected) {
    $docx = docx("<p><span style=\"text-decoration: underline {$css}\">u</span></p>");

    expect($docx->val('//w:r/w:rPr/w:u'))->toBe($expected);
})->with([
    ['solid', 'single'], ['double', 'double'], ['dotted', 'dotted'], ['dashed', 'dash'], ['wavy', 'wave'],
]);

it('composites translucent colors over white', function () {
    $docx = docx('<p><span style="color: rgba(0, 0, 0, 0.5)">half</span></p>');

    expect($docx->val('w:rPr/w:color', $docx->run('half')))->toBe('808080');
});

it('maps letter spacing, small caps and text shadow', function () {
    $docx = docx('<p><span style="letter-spacing: 2pt; font-variant: small-caps; text-shadow: 1px 1px #000">fx</span></p>');
    $run = $docx->run('fx');

    expect($docx->val('w:rPr/w:spacing', $run))->toBe('40')
        ->and($docx->first('w:rPr/w:smallCaps', $run))->not->toBeNull()
        ->and($docx->first('w:rPr/w:shadow', $run))->not->toBeNull();
});

it('maps legacy <font> attributes', function () {
    $docx = docx('<p><font color="#00ff00" face="Verdana" size="5">legacy</font></p>');
    $run = $docx->run('legacy');

    expect($docx->val('w:rPr/w:color', $run))->toBe('00FF00')
        ->and(Docx::attr($docx->first('w:rPr/w:rFonts', $run), 'ascii'))->toBe('Verdana')
        ->and($docx->val('w:rPr/w:sz', $run))->toBe('36');
});

it('keeps inline background on inline content only', function () {
    $docx = docx('<p style="background-color: #eeeeee">para <span style="background-color: #ff0000">span</span></p>');

    expect($docx->first('w:rPr/w:shd', $docx->run('para')))->toBeNull()
        ->and(Docx::attr($docx->first('w:rPr/w:shd', $docx->run('span')), 'fill'))->toBe('FF0000')
        ->and(Docx::attr($docx->first('w:pPr/w:shd', $docx->paragraph('para')), 'fill'))->toBe('EEEEEE');
});

it('merges adjacent text with identical formatting into one run', function () {
    $docx = docx('<p><span>one </span><span>two</span> three</p>');

    expect($docx->count('//w:r'))->toBe(1);
});

it('does not repeat formatting that the paragraph style already provides', function () {
    $docx = docx('<h1>Heading <em>emphasis</em></h1>');

    expect($docx->first('w:rPr', $docx->run('Heading')))->toBeNull()
        ->and($docx->first('w:rPr/w:b', $docx->run('emphasis')))->toBeNull()
        ->and($docx->first('w:rPr/w:i', $docx->run('emphasis')))->not->toBeNull();
});

it('marks right-to-left text', function () {
    $docx = docx('<p dir="rtl">שלום</p>');

    expect($docx->first('w:pPr/w:bidi', $docx->paragraph('שלום')))->not->toBeNull()
        ->and($docx->first('w:rPr/w:rtl', $docx->run('שלום')))->not->toBeNull();
});
