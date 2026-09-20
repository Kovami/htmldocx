<?php

declare(strict_types=1);

/*
 * Converts the examples with the library, the way the README shows:
 *
 *   showcase.docx → showcase.html            (plain HTML)
 *   showcase.docx → showcase.suneditor.html  (SunEditor profile)
 *   showcase.docx → SunEditor HTML → roundtrip.docx
 *   editor.html   → editor.docx
 *
 * The output is reproducible, so CI runs this and fails when the committed
 * examples no longer match what the library writes.
 *
 * Usage: php examples/build.php
 */

use Kovami\HtmlDocx\Editor;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Options;

require __DIR__ . '/../vendor/autoload.php';

$here = __DIR__;
$options = new Options(fullHtmlDocument: true, createdAt: new DateTimeImmutable('2026-07-01T00:00:00Z'));
$source = "{$here}/showcase.docx";

HtmlDocx::plain($options->with(['title' => 'Quarterly report (plain HTML)']))->fromDocxFile($source)->saveHtml("{$here}/showcase.html");

$sunEditor = HtmlDocx::for(Editor::SunEditor, $options->with(['title' => 'Quarterly report (SunEditor profile)']));
$conversion = $sunEditor->fromDocxFile($source);
$conversion->saveHtml("{$here}/showcase.suneditor.html");

// Back to DOCX on the document's own page, as an app that keeps it would.
$sunEditor->fromHtml($conversion->toHtml(), $conversion->document()->pageLayout)->saveDocx("{$here}/roundtrip.docx");

HtmlDocx::plain($options->with(['title' => 'Meeting notes']))
    ->withLocalImageBaseDir($here)
    ->fromHtmlFile("{$here}/editor.html")
    ->saveDocx("{$here}/editor.docx");

// The page that lists them, published with the examples on GitHub Pages.
file_put_contents("{$here}/index.html", index());

foreach (['showcase.html', 'showcase.suneditor.html', 'roundtrip.docx', 'editor.docx', 'index.html'] as $file) {
    echo "examples/{$file}\n";
}

function index(): string
{
    $sections = [
        ['A report Word wrote', 'showcase.docx', [
            ['showcase.html', 'the plain HTML the library writes from it'],
            ['showcase.suneditor.html', 'the same document in the SunEditor profile'],
            ['showcase.pdf', 'Word\'s own print of the document, to compare with'],
            ['images/showcase-p1.png', 'page 1 side by side: Word, plain HTML, SunEditor'],
            ['images/showcase-p2.png', 'page 2 side by side'],
        ]],
        ['There and back again', 'roundtrip.docx', [
            ['roundtrip.pdf', 'the document after DOCX → HTML → DOCX, printed by Word'],
            ['images/roundtrip-p1.png', 'page 1 next to the original'],
            ['images/roundtrip-p2.png', 'page 2 next to the original'],
        ]],
        ['From an editor to Word', 'editor.html', [
            ['editor.docx', 'the DOCX the library writes from that HTML'],
            ['editor.pdf', 'Word\'s print of it'],
            ['images/editor-p1.png', 'the HTML in a browser next to the DOCX in Word'],
        ]],
    ];
    $html = [];

    foreach ($sections as [$title, $source, $links]) {
        $items = array_map(
            static fn(array $link): string => sprintf('<li><a href="%s">%s</a> — %s</li>', $link[0], $link[0], $link[1]),
            $links,
        );
        $html[] = sprintf(
            "<section>\n<h2>%s</h2>\n<p>Source: <a href=\"%s\">%s</a></p>\n<ul>\n%s\n</ul>\n</section>",
            $title,
            $source,
            $source,
            implode("\n", $items),
        );
    }

    $sections_html = implode("\n", $html);

    return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>kovami/htmldocx — examples</title>
        <style>
        body { font-family: system-ui, sans-serif; line-height: 1.5; margin: 2rem auto; max-width: 48rem; padding: 0 1rem; color: #1b1b1b; }
        h1 { margin-bottom: 0.25rem; }
        p.lead { margin-top: 0; color: #555; }
        section { margin-top: 2rem; }
        ul { padding-left: 1.25rem; }
        li { margin: 0.25rem 0; }
        a { color: #2f5496; }
        footer { margin-top: 3rem; color: #555; font-size: 0.9rem; }
        </style>
        </head>
        <body>
        <h1>kovami/htmldocx</h1>
        <p class="lead">Every file here is written by <code>examples/build-word.sh</code>: Word writes and prints the documents, the library converts them.</p>
        {$sections_html}
        <footer><a href="https://github.com/kovami/htmldocx">The library on GitHub</a></footer>
        </body>
        </html>
        HTML;
}
