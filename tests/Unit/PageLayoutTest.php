<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Config\PageLayout;

it('provides standard paper sizes in twips', function (PageLayout $layout, int $width, int $height, bool $landscape) {
    expect($layout->widthTwips)->toBe($width)
        ->and($layout->heightTwips)->toBe($height)
        ->and($layout->isLandscape())->toBe($landscape);
})->with([
    'A4 portrait' => [PageLayout::a4Portrait(), 11906, 16838, false],
    'A4 landscape' => [PageLayout::a4Landscape(), 16838, 11906, true],
    'Letter portrait' => [PageLayout::letterPortrait(), 12240, 15840, false],
    'Letter landscape' => [PageLayout::letterLandscape(), 15840, 12240, true],
]);

it('computes the content width from the margins', function () {
    expect(PageLayout::a4Portrait(2.0)->contentWidthTwips())->toBe(11906 - 2 * 1134)
        ->and(PageLayout::letterPortrait(1.0)->contentWidthTwips())->toBe(12240 - 2 * 1440);
});

it('sets individual margins', function () {
    $layout = PageLayout::a4Portrait()->withMargins(1, 1.5, 2, 3);

    expect([$layout->marginTopTwips, $layout->marginRightTwips, $layout->marginBottomTwips, $layout->marginLeftTwips])
        ->toBe([567, 850, 1134, 1701]);
});

it('builds layouts from configuration arrays', function () {
    expect(PageLayout::fromArray([]))->toEqual(PageLayout::a4Portrait())
        ->and(PageLayout::fromArray(['size' => 'LETTER', 'orientation' => 'landscape', 'margin_cm' => 2.54]))->toEqual(PageLayout::letterLandscape())
        ->and(PageLayout::fromArray(['orientation' => 'landscape', 'margin_cm' => 1]))->toEqual(PageLayout::a4Landscape(1));
});

it('rejects margins that leave no content area', function () {
    PageLayout::a4Portrait(11);
})->throws(InvalidArgumentException::class);
