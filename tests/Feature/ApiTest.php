<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Editor;
use Kovami\HtmlDocx\Exceptions\HtmlDocxException;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Options;

it('picks the editor by enum or by name, in any spelling', function (Editor|string $name, Editor $editor) {
    expect(HtmlDocx::for($name)->editor())->toBe($editor);
})->with([
    [Editor::TinyMce, Editor::TinyMce],
    ['SunEditor', Editor::SunEditor],
    ['suneditor', Editor::SunEditor],
    ['sun-editor', Editor::SunEditor],
    ['CKEditor 5', Editor::CKEditor],
    ['ckeditor5', Editor::CKEditor],
    ['TinyMCE', Editor::TinyMce],
    ['tip_tap', Editor::TipTap],
    ['ProseMirror', Editor::TipTap],
]);

it('writes plain HTML for no editor', function () {
    expect(HtmlDocx::plain()->editor())->toBeNull();
});

it('names the editors it knows when given one it does not', function () {
    expect(fn() => HtmlDocx::for('Quill'))
        ->toThrow(InvalidArgumentException::class, 'Unknown editor "Quill"; use one of SunEditor, CKEditor, TinyMce, TipTap.');
});

it('keeps the options it is given', function () {
    $html = HtmlDocx::for(Editor::SunEditor, testOptions(['fullHtmlDocument' => true]))->fromHtml('<p>x</p>')->toHtml();

    expect($html)->toStartWith('<!DOCTYPE html>');
});

it('reads and writes files, streams and bytes', function () {
    $directory = sys_get_temp_dir() . '/htmldocx-api-' . bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        $converter = converter();
        $docxPath = $converter->fromHtml('<p>Hello <strong>world</strong></p>')->saveDocx("{$directory}/in.docx");
        $fromFile = $converter->fromDocxFile($docxPath)->toHtml();

        $stream = fopen($docxPath, 'rb');
        $fromStream = $converter->fromDocxStream($stream)->toHtml();
        fclose($stream);

        $htmlPath = $converter->fromDocxFile($docxPath)->saveHtml("{$directory}/out.html");
        $out = fopen('php://memory', 'w+b');
        $converter->fromHtmlFile($htmlPath)->streamDocx($out);
        rewind($out);

        expect($fromFile)->toBe('<p>Hello <strong>world</strong></p>')
            ->and($fromStream)->toBe($fromFile)
            ->and(file_get_contents($htmlPath))->toBe($fromFile)
            ->and($converter->fromDocx((string) stream_get_contents($out))->toHtml())->toBe($fromFile);
    } finally {
        array_map('unlink', glob("{$directory}/*") ?: []);
        rmdir($directory);
    }
});

it('produces the same result every time a conversion is asked for it', function () {
    $conversion = converter()->fromHtml('<p>Once</p>');

    expect($conversion->toHtml())->toBe($conversion->toHtml())
        ->and($conversion->toDocx())->toBe($conversion->toDocx());
});

it('stays immutable when configured', function () {
    $base = HtmlDocx::plain(new Options());
    $warned = [];
    $configured = $base->withWarningHandler(function (string $message) use (&$warned): void {
        $warned[] = $message;
    });

    expect($configured)->not->toBe($base)
        ->and($configured->editor())->toBeNull();
});

it('reports a file it cannot read', function () {
    expect(fn() => HtmlDocx::plain()->fromDocxFile('/nonexistent-dir/in.docx'))
        ->toThrow(HtmlDocxException::class);
});
