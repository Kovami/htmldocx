<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Tests\Support\Docx;

function numberingLevel(Docx $docx, string $text): array
{
    $paragraph = $docx->paragraph($text);
    $numId = $docx->val('w:pPr/w:numPr/w:numId', $paragraph);
    $level = $docx->val('w:pPr/w:numPr/w:ilvl', $paragraph);

    if ($numId === null) {
        return ['numId' => null, 'level' => null, 'format' => null, 'text' => null, 'start' => null];
    }

    $abstractId = $docx->val("//w:num[@w:numId='{$numId}']/w:abstractNumId", null, 'word/numbering.xml');
    $lvl = $docx->first("//w:abstractNum[@w:abstractNumId='{$abstractId}']/w:lvl[@w:ilvl='{$level}']", null, 'word/numbering.xml');

    return [
        'numId' => $numId,
        'level' => (int) $level,
        'format' => $docx->val('w:numFmt', $lvl, 'word/numbering.xml'),
        'text' => $docx->val('w:lvlText', $lvl, 'word/numbering.xml'),
        'start' => $docx->val("//w:num[@w:numId='{$numId}']/w:lvlOverride/w:startOverride", null, 'word/numbering.xml'),
    ];
}

it('renders unordered and ordered lists', function () {
    $docx = docx('<ul><li>bullet one</li><li>bullet two</li></ul><ol><li>number one</li><li>number two</li></ol>');

    expect(numberingLevel($docx, 'bullet one'))->toMatchArray(['level' => 0, 'format' => 'bullet', 'text' => "\u{2022}"])
        ->and(numberingLevel($docx, 'number two'))->toMatchArray(['level' => 0, 'format' => 'decimal', 'text' => '%1.', 'start' => '1'])
        ->and(numberingLevel($docx, 'bullet one')['numId'])->toBe(numberingLevel($docx, 'bullet two')['numId']);
});

it('nests lists one level deeper per nesting depth', function () {
    $docx = docx('<ol><li>L0<ul><li>L1<ol><li>L2</li></ol></li></ul></li><li>back to L0</li></ol>');

    expect(numberingLevel($docx, 'L0'))->toMatchArray(['level' => 0, 'format' => 'decimal'])
        ->and(numberingLevel($docx, 'L1'))->toMatchArray(['level' => 1, 'format' => 'bullet', 'text' => "\u{25E6}"])
        ->and(numberingLevel($docx, 'L2'))->toMatchArray(['level' => 2, 'format' => 'decimal', 'text' => '%3.'])
        ->and(numberingLevel($docx, 'back to L0')['numId'])->toBe(numberingLevel($docx, 'L0')['numId']);

    $indents = array_map(
        static fn (string $text): int => (int) Docx::attr($docx->first('w:pPr/w:ind', $docx->paragraph($text)), 'left'),
        ['L0', 'L1', 'L2'],
    );

    expect($indents[0])->toBeLessThan($indents[1])
        ->and($indents[1])->toBeLessThan($indents[2]);
});

it('uses the browser bullet sequence for nested unordered lists', function () {
    $docx = docx('<ul><li>disc<ul><li>circle<ul><li>square</li></ul></li></ul></li></ul>');

    expect(numberingLevel($docx, 'disc')['text'])->toBe("\u{2022}")
        ->and(numberingLevel($docx, 'circle')['text'])->toBe("\u{25E6}")
        ->and(numberingLevel($docx, 'square')['text'])->toBe("\u{25AA}");
});

it('maps list-style-type', function (string $type, string $format) {
    $docx = docx("<ol style=\"list-style-type: {$type}\"><li>item</li></ol>");

    expect(numberingLevel($docx, 'item')['format'])->toBe($format);
})->with([
    ['decimal', 'decimal'], ['decimal-leading-zero', 'decimalZero'], ['lower-alpha', 'lowerLetter'],
    ['upper-alpha', 'upperLetter'], ['lower-roman', 'lowerRoman'], ['upper-roman', 'upperRoman'],
    ['lower-latin', 'lowerLetter'], ['disc', 'bullet'], ['square', 'bullet'], ['none', 'none'],
]);

it('honours the start attribute', function () {
    expect(numberingLevel(docx('<ol start="7"><li>seven</li></ol>'), 'seven')['start'])->toBe('7');
});

it('restarts numbering for every separate list', function () {
    $docx = docx('<ol><li>first list</li></ol><p>between</p><ol><li>second list</li></ol>');

    $first = numberingLevel($docx, 'first list');
    $second = numberingLevel($docx, 'second list');

    expect($first['numId'])->not->toBe($second['numId'])
        ->and($second['start'])->toBe('1')
        ->and($docx->val("//w:num[@w:numId='{$first['numId']}']/w:abstractNumId", null, 'word/numbering.xml'))
        ->not->toBe($docx->val("//w:num[@w:numId='{$second['numId']}']/w:abstractNumId", null, 'word/numbering.xml'));
});

it('numbers only the first paragraph of a multi-paragraph item', function () {
    $docx = docx('<ol><li><p>first para</p><p>second para</p></li></ol>');

    expect(numberingLevel($docx, 'first para')['numId'])->not->toBeNull()
        ->and(numberingLevel($docx, 'second para')['numId'])->toBeNull()
        ->and(Docx::attr($docx->first('w:pPr/w:ind', $docx->paragraph('second para')), 'left'))
        ->toBe(Docx::attr($docx->first('w:pPr/w:ind', $docx->paragraph('first para')), 'left'));
});

it('keeps a marker for empty list items', function () {
    $docx = docx('<ul><li></li><li>filled</li></ul>');

    expect($docx->count('//w:p[w:pPr/w:numPr]'))->toBe(2);
});

it('keeps inline formatting and line breaks inside list items', function () {
    $docx = docx('<ul><li>plain <b>bold</b><br>next line</li></ul>');

    expect($docx->first('w:rPr/w:b', $docx->run('bold')))->not->toBeNull()
        ->and($docx->paragraphTexts())->toBe(["plain bold\nnext line"]);
});

it('gives the marker a hanging indent', function () {
    $ind = docx('<ul><li>item</li></ul>')->first('//w:p/w:pPr/w:ind');

    expect(Docx::attr($ind, 'left'))->toBe('600')
        ->and(Docx::attr($ind, 'hanging'))->toBe('360');
});

it('formats the marker like the item text', function () {
    $docx = docx('<ol><li style="color: #ff0000; font-size: 16pt">red</li></ol>');
    $mark = $docx->first('//w:p/w:pPr/w:rPr');

    expect($docx->val('w:color', $mark))->toBe('FF0000')
        ->and($docx->val('w:sz', $mark))->toBe('32');
});

it('treats stray list items outside a list as plain blocks', function () {
    $docx = docx('<div><li>orphan</li></div>');

    expect($docx->first('w:pPr/w:numPr', $docx->paragraph('orphan')))->toBeNull();
});

it('maps the type attribute of lists and items', function (string $html, string $format, string $text) {
    expect(numberingLevel(docx($html), 'item'))->toMatchArray(['format' => $format, 'text' => $text]);
})->with([
    ['<ol type="1"><li>item</li></ol>', 'decimal', '%1.'],
    ['<ol type="a"><li>item</li></ol>', 'lowerLetter', '%1.'],
    ['<ol type="A"><li>item</li></ol>', 'upperLetter', '%1.'],
    ['<ol type="i"><li>item</li></ol>', 'lowerRoman', '%1.'],
    ['<ol type="I"><li>item</li></ol>', 'upperRoman', '%1.'],
    ['<ul type="square"><li>item</li></ul>', 'bullet', "\u{25AA}"],
    ['<ol><li type="I">item</li></ol>', 'upperRoman', '%1.'],
    ['<ol type="I" style="list-style-type: lower-alpha"><li>item</li></ol>', 'lowerLetter', '%1.'],
]);

it('continues numbering from an item value', function () {
    $docx = docx('<ol><li>one</li><li value="10">ten</li><li>eleven</li></ol>');

    expect(numberingLevel($docx, 'one')['start'])->toBe('1')
        ->and(numberingLevel($docx, 'ten')['start'])->toBe('10')
        ->and(numberingLevel($docx, 'eleven')['numId'])->toBe(numberingLevel($docx, 'ten')['numId'])
        ->and(numberingLevel($docx, 'one')['numId'])->not->toBe(numberingLevel($docx, 'ten')['numId']);
});

it('keeps one definition while items follow the sequence', function () {
    $docx = docx('<ol start="3"><li value="3">three</li><li value="4">four</li></ol>');

    expect(numberingLevel($docx, 'three')['numId'])->toBe(numberingLevel($docx, 'four')['numId'])
        ->and($docx->count('//w:num', null, 'word/numbering.xml'))->toBe(1);
});

it('counts down in reversed lists', function (string $html, array $expected) {
    $docx = docx($html);

    expect(array_map(static fn (string $text): ?string => numberingLevel($docx, $text)['start'], array_keys($expected)))
        ->toBe(array_values($expected));
})->with([
    'from the item count' => ['<ol reversed><li>a</li><li>b</li><li>c</li></ol>', ['a' => '3', 'b' => '2', 'c' => '1']],
    'from start' => ['<ol reversed start="10"><li>a</li><li>b</li></ol>', ['a' => '10', 'b' => '9']],
    'from an item value' => ['<ol reversed><li>a</li><li value="7">b</li><li>c</li></ol>', ['a' => '3', 'b' => '7', 'c' => '6']],
]);

it('writes lower-greek markers Word cannot count as literal text', function () {
    $items = implode('', array_map(static fn (int $i): string => "<li>[{$i}]</li>", range(1, 25)));
    $docx = docx("<ol style=\"list-style-type: lower-greek\">{$items}</ol>");

    expect(numberingLevel($docx, '[1]'))->toMatchArray(['format' => 'none', 'text' => 'α.'])
        ->and(numberingLevel($docx, '[2]')['text'])->toBe('β.')
        ->and(numberingLevel($docx, '[24]')['text'])->toBe('ω.')
        ->and(numberingLevel($docx, '[25]')['text'])->toBe('αα.');
});

it('honours list-style-type set on a single item', function () {
    $docx = docx('<ol><li>one</li><li style="list-style-type: upper-roman">two</li><li>three</li></ol>');

    expect(numberingLevel($docx, 'one')['format'])->toBe('decimal')
        ->and(numberingLevel($docx, 'two'))->toMatchArray(['format' => 'upperRoman', 'start' => '2'])
        ->and(numberingLevel($docx, 'three'))->toMatchArray(['format' => 'decimal', 'start' => '3']);
});

it('places the marker before content Word cannot number', function (string $html) {
    $docx = docx($html);
    $body = $docx->query('/w:document/w:body/*');

    expect($body[0]->localName)->toBe('p')
        ->and($docx->val('w:pPr/w:numPr/w:ilvl', $body[0]))->toBe('0')
        ->and($docx->count('/w:document/w:body/w:p[w:pPr/w:numPr/w:ilvl[@w:val="0"]]'))->toBe(1);
})->with([
    'table' => '<ul><li><table><tr><td>cell</td></tr></table></li></ul>',
    'rule' => '<ul><li><hr></li></ul>',
    'nested list' => '<ul><li><ol><li>inner</li></ol></li></ul>',
    'wrapped table' => '<ul><li><div><table><tr><td>cell</td></tr></table></div></li></ul>',
]);

it('does not carry a list marker into table cells', function () {
    $docx = docx('<ul><li><table><tr><td>cell</td></tr></table></li></ul>');

    expect($docx->count('//w:tc//w:numPr'))->toBe(0);
});
