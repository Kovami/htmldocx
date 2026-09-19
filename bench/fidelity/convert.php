<?php

declare(strict_types=1);

// Converts one .docx with the library under test and prints JSON: the full
// HTML document and the page geometry the document asks for, in points.

use Kovami\HtmlDocx\Editor;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Options;

require __DIR__ . '/../../vendor/autoload.php';

// argv[2]: an editor name, or "plain" (the default).
$profile = $argv[2] ?? 'plain';
$options = new Options(fullHtmlDocument: true);
$converter = $profile === 'plain' ? HtmlDocx::plain($options) : HtmlDocx::for(Editor::fromName($profile), $options);
$warnings = [];
$converter = $converter->withWarningHandler(static function (string $message) use (&$warnings): void {
    $warnings[] = $message;
});

$conversion = $converter->fromDocxFile($argv[1]);
$document = $conversion->document();
$page = $document->pageLayout;
$points = static fn(int $twips): float => $twips / 20;

echo json_encode([
    'html' => $conversion->toHtml(),
    'page' => [
        'width' => $points($page->widthTwips),
        'height' => $points($page->heightTwips),
        'top' => $points($page->marginTopTwips),
        'right' => $points($page->marginRightTwips),
        'bottom' => $points($page->marginBottomTwips),
        'left' => $points($page->marginLeftTwips),
    ],
    'warnings' => $warnings,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
