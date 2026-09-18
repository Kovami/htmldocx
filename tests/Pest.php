<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Options;
use Kovami\HtmlDocx\Package\ZipWriter;
use Kovami\HtmlDocx\Tests\Support\Docx;
use Kovami\HtmlDocx\Tests\Support\DocxIntegrity;

/**
 * Converts HTML through the public API, re-opens the package with ext-zip
 * and ext-dom, and fails the test if the package is structurally unsound.
 */
function docx(string $html, ?HtmlDocx $converter = null, ?PageLayout $pageLayout = null): Docx
{
    $converter ??= new HtmlDocx(testOptions());
    $docx = Docx::fromBytes($converter->htmlToDocx($html, $pageLayout));

    expect(DocxIntegrity::violations($docx))->toBe([]);

    return $docx;
}

function testOptions(array $overrides = []): Options
{
    return (new Options(createdAt: new DateTimeImmutable('2026-01-02T03:04:05Z')))->with($overrides);
}

/** HTML → model → HTML: what the HTML writer makes of editor content. */
function html(string $source, ?Options $options = null): string
{
    $converter = new HtmlDocx($options ?? testOptions());

    return $converter->writeHtml($converter->readHtml($source));
}

/** HTML → DOCX → HTML: everything both directions do, through a real package. */
function roundTrip(string $source, ?Options $options = null): string
{
    $converter = new HtmlDocx($options ?? testOptions());

    return $converter->docxToHtml($converter->htmlToDocx($source));
}

/**
 * @param  array<string, array{0: string, 1: bool}>  $files  name => [data, compress]
 */
function zipBytes(array $files, string $time = '2026-03-04 05:06:08'): string
{
    $stream = fopen('php://memory', 'w+b');
    $zip = new ZipWriter($stream, new DateTimeImmutable($time));

    foreach ($files as $name => [$data, $compress]) {
        $zip->addFile($name, $data, $compress);
    }

    $zip->finish();
    rewind($stream);

    return (string) stream_get_contents($stream);
}
