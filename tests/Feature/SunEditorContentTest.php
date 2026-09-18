<?php

declare(strict_types=1);

use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Tests\Support\Docx;
use Kovami\HtmlDocx\Tests\Support\TestImage;

function sunEditorFixture(): string
{
    return str_replace('{{IMAGE}}', TestImage::pngDataUri(240, 120), (string) file_get_contents(__DIR__ . '/../Fixtures/suneditor.html'));
}

it('converts a full SunEditor document into a sound package', function () {
    $docx = docx(sunEditorFixture());

    expect($docx->count('//w:pStyle[@w:val="Heading1"]'))->toBe(1)
        ->and($docx->count('//w:pStyle[@w:val="Heading2"]'))->toBe(2)
        ->and($docx->count('//w:p[w:pPr/w:numPr]'))->toBe(7)
        ->and($docx->count('//w:tbl'))->toBe(1)
        ->and($docx->count('//w:vMerge'))->toBe(2)
        ->and($docx->count('//w:gridSpan'))->toBe(2)
        ->and($docx->count('//w:drawing'))->toBe(1)
        ->and($docx->count('//w:hyperlink'))->toBe(3)
        ->and($docx->count('//w:bookmarkStart'))->toBe(1)
        ->and($docx->count('//m:oMath'))->toBe(1)
        ->and($docx->paragraphTexts())->toContain('Формула: ', 'Рисунок 1 — диаграмма');
});

it('is readable by an independent DOCX consumer', function () {
    $placeholder = tempnam(sys_get_temp_dir(), 'kovami');
    $path = $placeholder . '.docx';

    try {
        converter()->fromHtml(sunEditorFixture())->saveDocx($path);
        exec('textutil -convert txt -stdout ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        $text = implode("\n", $output);

        expect($exitCode)->toBe(0);

        foreach (['Квартальный отчёт', 'x2, H2O', 'Вложенный b', 'Цитата из редактора.', 'console.log(answer);', 'Итого: 2 500 ₽', 'Перейти к таблице'] as $expected) {
            expect($text)->toContain($expected);
        }
    } finally {
        @unlink($path);
        @unlink($placeholder);
    }
})->skip(PHP_OS_FAMILY !== 'Darwin' || ! is_executable('/usr/bin/textutil'), 'macOS textutil is not available');

it('renders SunEditor paragraph and text classes', function () {
    $docx = docx(sunEditorFixture());

    expect($docx->val('w:rPr/w:spacing', $docx->run('Разреженный')))->toBe('15')
        ->and($docx->count('w:pPr/w:pBdr/*', $docx->paragraph('Абзац с рамкой')))->toBe(2)
        ->and(Docx::attr($docx->first('w:pPr/w:shd', $docx->paragraph('Неоновый')), 'fill'))->toBe('000000')
        ->and($docx->val('w:rPr/w:color', $docx->run('Неоновый')))->toBe('FFFFFF')
        ->and($docx->first('w:rPr/w:shadow', $docx->run('Тень')))->not->toBeNull()
        ->and(Docx::attr($docx->first('w:rPr/w:shd', $docx->run('t-code')), 'fill'))->toBe('F4F4F4')
        ->and($docx->val('//w:pBdr/w:bottom'))->not->toBeNull();
});

it('lays out the SunEditor table with its colgroup widths and merged cells', function () {
    $docx = docx(sunEditorFixture());
    $widths = array_map(static fn(DOMElement $col): int => (int) Docx::attr($col, 'w'), $docx->query('//w:gridCol'));

    expect(array_sum($widths))->toBe(9638)
        ->and(abs($widths[0] - 3855))->toBeLessThanOrEqual(2)
        ->and($docx->val('w:pPr/w:jc', $docx->paragraph('1 000 ₽')))->toBe('right')
        ->and($docx->count('//w:tr[1]/w:trPr/w:tblHeader'))->toBe(1);
});
