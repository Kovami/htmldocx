<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Tests\Support\Docx;
use Kovami\HtmlDocx\Tests\Support\TestImage;

/**
 * @return list<list<string>> text of every cell, row by row
 */
function tableMatrix(Docx $docx, int $index = 0): array
{
    $table = $docx->query('//w:tbl')[$index];

    return array_map(
        static fn(DOMElement $row): array => array_map($docx->text(...), $docx->query('w:tc', $row)),
        $docx->query('w:tr', $table),
    );
}

it('renders a simple table', function () {
    $docx = docx('<table><tr><td>a1</td><td>b1</td></tr><tr><td>a2</td><td>b2</td></tr></table>');

    expect(tableMatrix($docx))->toBe([['a1', 'b1'], ['a2', 'b2']])
        ->and($docx->count('//w:tblGrid/w:gridCol'))->toBe(2);
});

it('spans the content width by default with equal columns', function () {
    $docx = docx('<table><tr><td>a</td><td>b</td><td>c</td></tr></table>');
    $widths = array_map(static fn(DOMElement $col): int => (int) Docx::attr($col, 'w'), $docx->query('//w:gridCol'));

    expect(array_sum($widths))->toBe(9638)
        ->and(max($widths) - min($widths))->toBeLessThanOrEqual(2)
        ->and(Docx::attr($docx->first('//w:tblW'), 'w'))->toBe('9638')
        ->and(Docx::attr($docx->first('//w:tblLayout'), 'type'))->toBe('fixed');
});

it('orders thead, tbody and tfoot like the browser and repeats header rows', function () {
    $docx = docx('<table><tfoot><tr><td>foot</td></tr></tfoot><tbody><tr><td>body</td></tr></tbody><thead><tr><th>head</th></tr></thead></table>');

    expect(tableMatrix($docx))->toBe([['head'], ['body'], ['foot']])
        ->and($docx->count('//w:tr[1]/w:trPr/w:tblHeader'))->toBe(1)
        ->and($docx->count('//w:tblHeader'))->toBe(1);
});

it('styles header cells bold, centered and shaded', function () {
    $docx = docx('<table><tr><th>Header</th></tr></table>');

    expect($docx->first('w:rPr/w:b', $docx->run('Header')))->not->toBeNull()
        ->and($docx->val('w:pPr/w:jc', $docx->paragraph('Header')))->toBe('center')
        ->and(Docx::attr($docx->first('//w:tc/w:tcPr/w:shd'), 'fill'))->toBe('F3F3F3');
});

it('maps colspan to gridSpan', function () {
    $docx = docx('<table><tr><td colspan="2">wide</td><td>c</td></tr><tr><td>a</td><td>b</td><td>c</td></tr></table>');

    expect($docx->count('//w:tblGrid/w:gridCol'))->toBe(3)
        ->and($docx->val('//w:tr[1]/w:tc[1]/w:tcPr/w:gridSpan'))->toBe('2')
        ->and($docx->count('//w:tr[1]/w:tc'))->toBe(2);
});

it('maps rowspan to vertical merges', function () {
    $docx = docx('<table><tr><td rowspan="3">tall</td><td>r1</td></tr><tr><td>r2</td></tr><tr><td>r3</td></tr></table>');

    expect(tableMatrix($docx))->toBe([['tall', 'r1'], ['', 'r2'], ['', 'r3']])
        ->and($docx->val('//w:tr[1]/w:tc[1]/w:tcPr/w:vMerge'))->toBe('restart')
        ->and($docx->val('//w:tr[2]/w:tc[1]/w:tcPr/w:vMerge'))->toBe('continue')
        ->and($docx->val('//w:tr[3]/w:tc[1]/w:tcPr/w:vMerge'))->toBe('continue');
});

it('allocates slots for combined and overlapping spans', function () {
    $html = <<<'HTML'
        <table>
          <tr><td rowspan="2" colspan="2">A</td><td>B</td><td rowspan="3">C</td></tr>
          <tr><td>D</td></tr>
          <tr><td>E</td><td colspan="2">F</td></tr>
          <tr><td colspan="4">G</td></tr>
        </table>
        HTML;

    $docx = docx($html);

    expect(tableMatrix($docx))->toBe([['A', 'B', 'C'], ['', 'D', ''], ['E', 'F', ''], ['G']])
        ->and($docx->count('//w:tblGrid/w:gridCol'))->toBe(4);
});

it('clamps rowspan to the rows that exist and supports rowspan="0"', function () {
    $docx = docx('<table><tr><td rowspan="9">a</td><td>b</td></tr><tr><td>c</td></tr></table><table><tr><td rowspan="0">x</td><td>1</td></tr><tr><td>2</td></tr></table>');

    expect(tableMatrix($docx, 0))->toBe([['a', 'b'], ['', 'c']])
        ->and(tableMatrix($docx, 1))->toBe([['x', '1'], ['', '2']]);
});

it('pads ragged rows to a rectangular grid', function () {
    expect(tableMatrix(docx('<table><tr><td>1</td><td>2</td><td>3</td></tr><tr><td>only</td></tr></table>')))
        ->toBe([['1', '2', '3'], ['only', '', '']]);
});

it('uses colgroup widths', function () {
    $docx = docx('<table><colgroup><col style="width: 25%"><col style="width: 75%"></colgroup><tr><td>a</td><td>b</td></tr></table>');
    $widths = array_map(static fn(DOMElement $col): int => (int) Docx::attr($col, 'w'), $docx->query('//w:gridCol'));

    expect(array_sum($widths))->toBe(9638)
        ->and(abs($widths[1] - 3 * $widths[0]))->toBeLessThanOrEqual(3);
});

it('uses cell widths and shares the remaining space', function () {
    $docx = docx('<table style="width: 400pt"><tr><td style="width: 100pt">fixed</td><td>rest</td><td>rest</td></tr></table>');
    $widths = array_map(static fn(DOMElement $col): int => (int) Docx::attr($col, 'w'), $docx->query('//w:gridCol'));

    expect($widths)->toBe([2000, 3000, 3000]);
});

it('never makes a table wider than the page', function () {
    $docx = docx('<table style="width: 3000px"><tr><td style="width: 2000px">a</td><td style="width: 2000px">b</td></tr></table>');
    $widths = array_map(static fn(DOMElement $col): int => (int) Docx::attr($col, 'w'), $docx->query('//w:gridCol'));

    expect(array_sum($widths))->toBe(9638)
        ->and($widths[0])->toBe($widths[1]);
});

it('maps cell background, vertical alignment, padding and borders', function () {
    $docx = docx('<table><tr><td style="background-color: #e2f0d9; vertical-align: bottom; padding: 10pt 5pt; border: 2px dotted #00f">cell</td><td valign="top" bgcolor="yellow">legacy</td></tr></table>');
    $first = $docx->first('//w:tc[1]/w:tcPr');
    $second = $docx->first('//w:tc[2]/w:tcPr');

    expect(Docx::attr($docx->first('w:shd', $first), 'fill'))->toBe('E2F0D9')
        ->and($docx->val('w:vAlign', $first))->toBe('bottom')
        ->and(Docx::attr($docx->first('w:tcMar/w:top', $first), 'w'))->toBe('200')
        ->and(Docx::attr($docx->first('w:tcMar/w:left', $first), 'w'))->toBe('100')
        ->and($docx->val('w:tcBorders/w:left', $first))->toBe('dotted')
        ->and(Docx::attr($docx->first('w:tcBorders/w:left', $first), 'color'))->toBe('0000FF')
        ->and($docx->val('w:vAlign', $second))->toBe('top')
        ->and(Docx::attr($docx->first('w:shd', $second), 'fill'))->toBe('FFFF00');
});

it('draws SunEditor cell borders by default', function () {
    expect(docx('<table><tr><td>x</td></tr></table>')->count('//w:tcBorders/*'))->toBe(4);
});

it('renders block content inside cells', function () {
    $docx = docx('<table><tr><td><div>line one</div><div><b>line two</b></div><ul><li>listed</li></ul></td></tr></table>');

    expect($docx->count('//w:tc/w:p'))->toBe(3)
        ->and($docx->first('w:pPr/w:numPr', $docx->paragraph('listed')))->not->toBeNull();
});

it('supports nested tables', function () {
    $docx = docx('<table><tr><td><table><tr><td>inner</td></tr></table></td><td>outer</td></tr></table>');

    expect($docx->count('//w:tbl'))->toBe(2)
        ->and($docx->count('//w:tc/w:tbl'))->toBe(1)
        ->and($docx->paragraph('inner'))->not->toBeNull();
});

it('keeps consecutive tables apart', function () {
    $docx = docx('<table><tr><td>one</td></tr></table><table><tr><td>two</td></tr></table>');

    expect($docx->count('/w:document/w:body/w:tbl'))->toBe(2)
        ->and($docx->count('/w:document/w:body/w:tbl/following-sibling::*[1][self::w:p]'))->toBe(2);
});

it('renders the caption above the table', function () {
    $docx = docx('<table><caption>Table 1</caption><tr><td>x</td></tr></table>');
    $caption = $docx->first('/w:document/w:body/*[1]');

    expect($caption->localName)->toBe('p')
        ->and($docx->text($caption))->toBe('Table 1')
        ->and($docx->val('w:pPr/w:pStyle', $caption))->toBe('Caption');
});

it('centers tables with auto margins or align="center"', function (string $html) {
    expect(docx($html)->val('//w:tblPr/w:jc'))->toBe('center');
})->with([
    '<table style="width: 50%; margin: 0 auto"><tr><td>x</td></tr></table>',
    '<table align="center" width="50%"><tr><td>x</td></tr></table>',
]);

it('ignores tables without cells', function () {
    expect(docx('<table></table><p>after</p>')->count('//w:tbl'))->toBe(0);
});

it('indents tables inside indented containers', function () {
    $docx = docx('<div style="margin-left: 1in"><table><tr><td>x</td></tr></table></div>');

    expect(Docx::attr($docx->first('//w:tblInd'), 'w'))->toBe('1440')
        ->and(Docx::attr($docx->first('//w:tblW'), 'w'))->toBe('8198');
});

it('moves table margins onto the neighbouring paragraphs', function () {
    $docx = docx('<p style="margin: 0">before</p><table style="margin: 30pt 0 20pt"><tr><td>x</td></tr></table><p style="margin: 0">after</p>');

    expect(Docx::attr($docx->first('w:pPr/w:spacing', $docx->paragraph('before')), 'after'))->toBe('600')
        ->and(Docx::attr($docx->first('w:pPr/w:spacing', $docx->paragraph('after')), 'before'))->toBe('400');
});

it('separates SunEditor tables from following content by their margin', function () {
    $docx = docx('<table><tr><td>x</td></tr></table><div class="se-component"><img src="' . TestImage::pngDataUri(10, 10) . '"></div>');
    $next = $docx->first('/w:document/w:body/w:tbl/following-sibling::w:p[1]');

    expect(Docx::attr($docx->first('w:pPr/w:spacing', $next), 'before'))->toBe('150');
});

it('keeps the larger margin between consecutive tables', function () {
    $docx = docx('<table style="margin-bottom: 10pt"><tr><td>a</td></tr></table><table style="margin-top: 25pt"><tr><td>b</td></tr></table>');
    $separator = $docx->first('/w:document/w:body/w:tbl[1]/following-sibling::w:p[1]');

    expect(Docx::attr($docx->first('w:pPr/w:spacing', $separator), 'before'))->toBe('500');
});

it('applies the margins of a box wrapping a table', function () {
    $docx = docx('<div style="margin-bottom: 30pt"><table style="margin: 0"><tr><td>x</td></tr></table></div><p style="margin: 0">after</p>');

    expect(Docx::attr($docx->first('w:pPr/w:spacing', $docx->paragraph('after')), 'before'))->toBe('600');
});

it('puts the top margin of a captioned table above its caption', function () {
    $docx = docx('<table style="margin-top: 20pt"><caption>Table 1</caption><tr><td>x</td></tr></table>');

    expect(Docx::attr($docx->first('w:pPr/w:spacing', $docx->paragraph('Table 1')), 'before'))->toBe('400');
});

it('shares a table among columns nothing sizes as a browser does, by their content', function () {
    $table = Kovami\HtmlDocx\HtmlDocx::plain(testOptions())
        ->fromHtml('<table style="width: 100%"><tr><th>Item</th><th>Amount, k$</th></tr><tr><td>Online platform</td><td>135</td></tr></table>')
        ->document()->blocks[0];
    [$first, $second] = array_map(static fn($cell): int => $cell->properties->width, $table->rows[0]->cells);

    // Chromium gives these columns 55% and 45% of the table.
    expect($first / ($first + $second))->toBeGreaterThan(0.52)->toBeLessThan(0.6);
});

it('keeps a table without a width as narrow as its content', function () {
    $table = Kovami\HtmlDocx\HtmlDocx::plain(testOptions())
        ->fromHtml('<table><tr><td>a</td><td>b</td></tr></table><table><tr><td>' . str_repeat('long words ', 80) . '</td></tr></table>')
        ->document()->blocks;
    $table = array_values(array_filter($table, static fn($block): bool => $block instanceof Kovami\HtmlDocx\Model\Table));
    $width = static fn($table): int => array_sum(array_map(static fn($cell): int => $cell->properties->width, $table->rows[0]->cells));

    expect($width($table[0]))->toBeLessThan(1000)
        ->and($width($table[1]))->toBe(testOptions()->page()->contentWidthTwips());
});

it('centres cells vertically as a browser does, unless a cell says otherwise', function () {
    $table = Kovami\HtmlDocx\HtmlDocx::plain(testOptions())
        ->fromHtml('<table><tr><td>middle</td><td style="vertical-align: top">top</td></tr></table>')
        ->document()->blocks[0];

    expect($table->rows[0]->cells[0]->properties->verticalAlign)->toBe('center')
        ->and($table->rows[0]->cells[1]->properties->verticalAlign)->toBe('top');
});
