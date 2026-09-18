<?php

declare(strict_types=1);

// Converts one .docx with the library under test and prints JSON: the full
// HTML document and the page geometry the document asks for, in points.

use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Options;

require __DIR__ . '/../../vendor/autoload.php';

$bytes = (string) file_get_contents($argv[1]);
$converter = new HtmlDocx(new Options(fullHtmlDocument: true));
$warnings = [];
$converter = $converter->withWarningHandler(static function (string $message) use (&$warnings): void {
    $warnings[] = $message;
});

$document = $converter->readDocx($bytes);
$page = $document->pageLayout;
$points = static fn(int $twips): float => $twips / 20;

echo json_encode([
    'html' => $converter->writeHtml($document),
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
