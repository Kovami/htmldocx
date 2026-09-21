<?php

declare(strict_types=1);

// Converts one .docx with the library under test and prints JSON: the full
// HTML document and the page geometry the document asks for, in points.
// argv[3] "body": convert the body-only variant (body.php) instead.

use Kovami\HtmlDocx\Css\FontMetrics;
use Kovami\HtmlDocx\Editor;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Options;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/body.php';

// argv[2]: an editor name, or "plain" (the default).
$profile = $argv[2] ?? 'plain';
$options = new Options(fullHtmlDocument: true);
$converter = $profile === 'plain' ? HtmlDocx::plain($options) : HtmlDocx::for(Editor::fromName($profile), $options);
$warnings = [];
$converter = $converter->withWarningHandler(static function (string $message) use (&$warnings): void {
    $warnings[] = $message;
});

$file = $argv[1];

if (($argv[3] ?? '') === 'body') {
    $body = sys_get_temp_dir() . '/htmldocx-body-' . getmypid() . '.docx';
    register_shutdown_function(static fn() => @unlink($body));
    $file = bodyOnly($file, $body) ? $body : $file;
}

$conversion = $converter->fromDocxFile($file);
$document = $conversion->document();
$page = $document->pageLayout;
$points = static fn(int $twips): float => $twips / 20;

// Word keeps a line on the page when its single height fits (measured in
// Mac Word): what a multiple adds below the line may hang into the bottom
// margin. A browser needs the whole line box to fit, so the page lets the
// extra of the body's most common line spacing into the margin, as Word does.
$normal = $document->style('Normal');
$spacings = [];

foreach ($document->blocks as $block) {
    if ($block instanceof Kovami\HtmlDocx\Model\Paragraph) {
        $line = $block->properties->lineSpacing ?? $normal?->lineSpacing ?? $document->defaultParagraphProperties->lineSpacing;
        $rule = $block->properties->lineRule ?? $normal?->lineRule ?? $document->defaultParagraphProperties->lineRule ?? 'auto';
        $key = $rule === 'auto' && $line !== null ? $line : 240;
        $spacings[$key] = ($spacings[$key] ?? 0) + 1;
    }
}

arsort($spacings);
$line = array_key_first($spacings) ?? 240;
$single = FontMetrics::singleLine($normal?->run->fontFamily ?? $document->defaultRunProperties->fontFamily ?? '');
$size = ($normal?->run->size ?? $document->defaultRunProperties->size ?? 20) / 2;
$overhang = $line > 240 && $single !== null ? ($line / 240 - 1) * $single * $size : 0.0;

echo json_encode([
    'html' => $conversion->toHtml(),
    'page' => [
        'width' => $points($page->widthTwips),
        'height' => $points($page->heightTwips),
        'top' => $points($page->marginTopTwips),
        'right' => $points($page->marginRightTwips),
        'bottom' => $points($page->marginBottomTwips) - $overhang,
        'left' => $points($page->marginLeftTwips),
    ],
    'warnings' => $warnings,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
