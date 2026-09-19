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

foreach (['showcase.html', 'showcase.suneditor.html', 'roundtrip.docx', 'editor.docx'] as $file) {
    echo "examples/{$file}\n";
}
