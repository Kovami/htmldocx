<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Css\DeclarationParser;

function declarations(string $block): array
{
    return array_map(static fn ($d) => $d->value, DeclarationParser::parse($block));
}

it('parses declarations and flags !important', function () {
    $parsed = DeclarationParser::parse('color: red; FONT-WEIGHT : bold !important ; ; broken; --custom: 1; width:');

    expect(array_keys($parsed))->toBe(['color', 'font-weight'])
        ->and($parsed['font-weight']->value)->toBe('bold')
        ->and($parsed['font-weight']->important)->toBeTrue()
        ->and($parsed['color']->important)->toBeFalse();
});

it('does not split on semicolons inside strings and functions', function () {
    expect(declarations('background: url("a;b.png") #fff; content: "x;y"; color: rgb(1, 2, 3)'))
        ->toMatchArray(['background-color' => '#fff', 'content' => '"x;y"', 'color' => 'rgb(1, 2, 3)']);
});

it('expands box shorthands', function (string $value, array $expected) {
    expect(declarations("margin: {$value}"))->toBe(array_combine(['margin-top', 'margin-right', 'margin-bottom', 'margin-left'], $expected));
})->with([
    ['1px', ['1px', '1px', '1px', '1px']],
    ['1px 2px', ['1px', '2px', '1px', '2px']],
    ['1px 2px 3px', ['1px', '2px', '3px', '2px']],
    ['1px 2px 3px 4px', ['1px', '2px', '3px', '4px']],
]);

it('maps logical properties to physical ones', function () {
    expect(declarations('padding-inline-start: 40px; margin-block-end: 1em; margin-inline: 1px 2px'))
        ->toBe(['padding-left' => '40px', 'margin-bottom' => '1em', 'margin-left' => '1px', 'margin-right' => '2px']);
});

it('expands border shorthands in any token order', function () {
    expect(declarations('border-left: #ccc dashed 3px'))->toBe([
        'border-left-width' => '3px', 'border-left-style' => 'dashed', 'border-left-color' => '#ccc',
    ]);

    expect(declarations('border: 0; border-top: 1px solid'))->toMatchArray([
        'border-top-style' => 'solid', 'border-top-color' => 'currentcolor', 'border-bottom-style' => 'none',
    ]);

    expect(declarations('border-width: 1px 2px'))->toBe([
        'border-top-width' => '1px', 'border-right-width' => '2px', 'border-bottom-width' => '1px', 'border-left-width' => '2px',
    ]);
});

it('lets later declarations override earlier shorthand parts', function () {
    expect(declarations('border: 1px solid red; border-color: blue'))
        ->toMatchArray(['border-top-color' => 'blue', 'border-top-style' => 'solid']);
});

it('expands text-decoration, background, list-style and font', function () {
    expect(declarations('text-decoration: underline wavy red'))
        ->toBe(['text-decoration-style' => 'wavy', 'text-decoration-color' => 'red', 'text-decoration-line' => 'underline'])
        ->and(declarations('background: no-repeat rgba(0,0,0,.5) center'))->toBe(['background-color' => 'rgba(0,0,0,.5)'])
        ->and(declarations('list-style: inside upper-roman'))->toBe(['list-style-type' => 'upper-roman'])
        ->and(declarations('font: italic bold 12px/1.5 "Open Sans", sans-serif'))->toBe([
            'font-style' => 'italic', 'font-variant' => 'normal', 'font-weight' => 'bold',
            'font-size' => '12px', 'line-height' => '1.5', 'font-family' => '"Open Sans", sans-serif',
        ]);
});
