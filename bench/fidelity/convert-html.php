<?php

declare(strict_types=1);

// Converts one HTML fragment (a file) to DOCX with the library under test,
// reading it with an editor's profile or as plain HTML, and prints the page
// geometry the DOCX gets, in points, as JSON. Images resolve next to the file.
//
// Usage: php convert-html.php <in.html> <plain|editor> <out.docx>

use Kovami\HtmlDocx\Editor;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Options;

require __DIR__ . '/../../vendor/autoload.php';

[, $in, $profile, $out] = $argv;
$options = new Options(createdAt: new DateTimeImmutable('2026-07-01T00:00:00Z'));
$converter = $profile === 'plain' ? HtmlDocx::plain($options) : HtmlDocx::for(Editor::fromName($profile), $options);
$converter->withLocalImageBaseDir(dirname($in))->fromHtmlFile($in)->saveDocx($out);

$page = $options->page();
$points = static fn(int $twips): float => $twips / 20;

echo json_encode([
    'width' => $points($page->widthTwips),
    'height' => $points($page->heightTwips),
    'top' => $points($page->marginTopTwips),
    'right' => $points($page->marginRightTwips),
    'bottom' => $points($page->marginBottomTwips),
    'left' => $points($page->marginLeftTwips),
    'font' => ['family' => $options->baseFontFamily(), 'size' => $options->baseFontSizePt()],
], JSON_THROW_ON_ERROR);
