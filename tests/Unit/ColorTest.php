<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Css\Color;

it('parses CSS colors', function (string $value, ?string $hex) {
    expect(Color::toHex($value))->toBe($hex);
})->with([
    'named' => ['rebeccapurple', '663399'],
    'named case-insensitive' => [' NavajoWhite ', 'FFDEAD'],
    'short hex' => ['#f0a', 'FF00AA'],
    'long hex' => ['#1a2B3c', '1A2B3C'],
    'hex with alpha' => ['#00000080', '7F7F7F'],
    'short hex with alpha' => ['#0000', null],
    'rgb' => ['rgb(255, 128, 0)', 'FF8000'],
    'rgb space syntax' => ['rgb(255 128 0)', 'FF8000'],
    'rgb percentages' => ['rgb(100%, 0%, 50%)', 'FF0080'],
    'rgba translucent' => ['rgba(0, 0, 255, 0.5)', '8080FF'],
    'rgb slash alpha' => ['rgb(0 0 0 / 25%)', 'BFBFBF'],
    'clamped channels' => ['rgb(300, -5, 0)', 'FF0000'],
    'hsl' => ['hsl(120, 100%, 50%)', '00FF00'],
    'hsl gray' => ['hsl(0, 0%, 50%)', '808080'],
    'hsla' => ['hsla(240, 100%, 50%, 1)', '0000FF'],
    'transparent' => ['transparent', null],
    'fully transparent rgba' => ['rgba(10, 10, 10, 0)', null],
    'unknown' => ['not-a-color', null],
    'currentColor' => ['currentColor', null],
]);
