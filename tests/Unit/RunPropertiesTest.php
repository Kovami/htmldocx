<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Model\RunProperties;

it('keeps only properties that differ from the base', function () {
    $base = new RunProperties(fontFamily: 'Calibri', size: 22, bold: true, color: '000000');
    $run = new RunProperties(fontFamily: 'Calibri', size: 24, bold: false, italic: false, underline: 'none', color: '000000', verticalAlign: 'baseline');

    expect($run->relativeTo($base))->toEqual(new RunProperties(size: 24, bold: false));
});

it('is empty when nothing differs', function () {
    $properties = new RunProperties(fontFamily: 'Arial', size: 20);

    expect($properties->relativeTo($properties)->isEmpty())->toBeTrue()
        ->and($properties->isEmpty())->toBeFalse()
        ->and($properties->equals(new RunProperties(fontFamily: 'Arial', size: 20)))->toBeTrue();
});
