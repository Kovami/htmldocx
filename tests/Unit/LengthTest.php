<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Css\Length;

it('converts absolute units to points', function (string $value, float $points) {
    expect(Length::toPoints($value, 12, 11))->toEqualWithDelta($points, 0.001);
})->with([
    ['12pt', 12], ['16px', 12], ['1in', 72], ['2.54cm', 72], ['25.4mm', 72], ['1pc', 12], ['101.6q', 72],
    ['0', 0], ['-4pt', -4], ['.5in', 36], ['1e1pt', 10], [' 10PX ', 7.5],
]);

it('resolves relative units against the given bases', function () {
    expect(Length::toPoints('2em', 10, 11))->toBe(20.0)
        ->and(Length::toPoints('2rem', 10, 11))->toBe(22.0)
        ->and(Length::toPoints('2ex', 10, 11))->toBe(10.0)
        ->and(Length::toPoints('50%', 10, 11, 300))->toBe(150.0)
        ->and(Length::toPoints('50%', 10, 11))->toBeNull();
});

it('rejects values that are not lengths', function (?string $value) {
    expect(Length::toPoints($value, 12, 12))->toBeNull();
})->with([null, '', 'auto', '12', 'px', '12 px', '1vw', 'calc(1px + 2px)']);

it('converts between OOXML units', function () {
    expect(Length::pointsToTwips(12))->toBe(240)
        ->and(Length::twipsFromMillimeters(210))->toBe(11906)
        ->and(Length::twipsFromInches(8.5))->toBe(12240)
        ->and(Length::pixelsToEmu(96))->toBe(914400)
        ->and(Length::twipsToPixels(1440))->toBe(96.0);
});
