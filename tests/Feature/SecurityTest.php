<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Editor;
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
