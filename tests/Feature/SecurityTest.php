<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Editor;
use Kovami\HtmlDocx\Exceptions\HtmlDocxException;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Html\Writer\CssFormatter;
use Kovami\HtmlDocx\Tests\Support\DocxBuilder;

/** Every style attribute of the HTML, parsed into its declarations' property names. */
function styleProperties(string $html): array
{
    preg_match_all('/style="([^"]*)"/', $html, $matches);
    $properties = [];

    foreach ($matches[1] as $style) {
        // The same split a browser makes: a declaration ends at `;` outside a string.
        $style = html_entity_decode($style, ENT_QUOTES | ENT_HTML5);
        $style = preg_replace('/"(?:[^"\\\\\n\r\f]|\\\\.)*"/s', '""', $style);

        foreach (explode(';', $style) as $declaration) {
            if (str_contains($declaration, ':')) {
                $properties[] = trim(explode(':', $declaration, 2)[0]);
            }
        }
    }

    return $properties;
}

$picture = '<w:p><w:r><w:drawing><wp:inline><wp:extent cx="952500" cy="952500"/><wp:docPr id="1" name="Picture"/>'
    . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
    . '<pic:nvPicPr><pic:cNvPr id="1" name="Picture"/><pic:cNvPicPr/></pic:nvPicPr>'
    . '<pic:blipFill><a:blip r:embed="rIdImage"/></pic:blipFill><pic:spPr/></pic:pic>'
    . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

it('keeps a font name with a line break inside its CSS string', function (string $break) {
    $html = HtmlDocx::for(Editor::SunEditor)->fromDocx(DocxBuilder::make()->body(
        '<w:p><w:r><w:rPr><w:rFonts w:ascii="Evil' . $break . '; background: url(https://attacker.test/x)" w:hAnsi="Evil' . $break . '; background: url(https://attacker.test/x)"/></w:rPr><w:t>text</w:t></w:r></w:p>',
    )->toBytes())->toHtml();

    // What is left of the name stays inside the quoted family, where it is only text.
    expect(styleProperties($html))->not->toContain('background');
})->with(['LF' => '&#10;', 'CR' => '&#13;', 'CRLF' => '&#13;&#10;']);

it('keeps a list marker with a line break inside its CSS string', function (string $break) {
    $html = HtmlDocx::for(Editor::SunEditor)->fromDocx(DocxBuilder::make()
        ->numbering(
            '<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/>'
            . '<w:lvlText w:val="%1' . $break . '; background: url(https://attacker.test/x); x: &quot;"/></w:lvl></w:abstractNum>'
            . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>',
        )
        ->body('<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>item</w:t></w:r></w:p>')
        ->toBytes())->toHtml();

    expect(styleProperties($html))->not->toContain('background');
})->with(['LF' => '&#10;', 'CR' => '&#13;', 'CRLF' => '&#13;&#10;']);

it('escapes every character that could end a CSS string', function () {
    expect(CssFormatter::string("a\nb\rc\fd\\e\"f\0g"))->toBe('"a\A b\D c\C d\\\\e\"f\\0 g"')
        ->and(CssFormatter::fontFamily("Evil\r; x: y"))->toBe('"Evil\D ; x: y"');
});

it('refuses a declaration value that carries a raw line break', function () {
    expect(fn() => CssFormatter::declarations(['color' => "red\n; background: blue"]))->toThrow(LogicException::class);
});

it('skips an SVG picture instead of passing its bytes on', function () use ($picture) {
    $warnings = [];
    $conversion = HtmlDocx::for(Editor::SunEditor)
        ->withWarningHandler(static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        })
        ->fromDocx(DocxBuilder::make()
            ->part('media/image1.svg', '<html><script>alert(document.domain)</script></html>', 'image/svg+xml')
            ->relationship('rIdImage', 'image', 'media/image1.svg')
            ->body($picture)
            ->toBytes());

    $html = $conversion->toHtml();

    expect($html)->not->toContain('<img')
        ->and($html)->not->toContain('script')
        ->and(implode("\n", $warnings))->toContain('SVG');
});

$utf16 = static fn(string $body, string $doctype = ''): string => "\xFF\xFE" . mb_convert_encoding(
    '<?xml version="1.0" encoding="UTF-16" standalone="yes"?>' . $doctype
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>',
    'UTF-16LE',
    'UTF-8',
);

it('reads a part written in UTF-16', function () use ($utf16) {
    $html = HtmlDocx::for(Editor::SunEditor)->fromDocx(DocxBuilder::make()->part('document.xml', $utf16('<w:p><w:r><w:t>привет</w:t></w:r></w:p>'))->toBytes())->toHtml();

    expect($html)->toContain('привет');
});

it('refuses a DOCTYPE in any encoding', function (string $part) {
    expect(fn() => DocxBuilder::make()->part('document.xml', $part)->read())->toThrow(HtmlDocxException::class);
})->with([
    'UTF-16LE with BOM' => $utf16('<w:p><w:r><w:t>&x;</w:t></w:r></w:p>', '<!DOCTYPE w:document [<!ENTITY x "leaked">]>'),
    'UTF-16BE without BOM' => mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE d [<!ENTITY x "y">]><d>&x;</d>', 'UTF-16BE', 'UTF-8'),
    'UTF-32' => mb_convert_encoding('<?xml version="1.0" encoding="UTF-32"?><!DOCTYPE d [<!ENTITY x "y">]><d>&x;</d>', 'UTF-32LE', 'UTF-8'),
]);

it('drops a script link whatever whitespace hides its scheme', function (string $href) {
    $docx = docx('<p><a href="' . $href . '">text</a></p>');

    expect($docx->count('//w:hyperlink'))->toBe(0);
})->with(['java&#9;script:alert(1)', 'java&#10;script:alert(1)', "\u{0001}javascript:alert(1)"]);
