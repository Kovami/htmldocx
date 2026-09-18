<?php

declare(strict_types=1);

use Dom\HTMLDocument;
use Kovami\HtmlDocx\Css\Selector;

it('computes specificity', function (string $selector, array $specificity) {
    expect(Selector::parse($selector)?->specificity)->toBe($specificity);
})->with([
    ['*', [0, 0, 0]],
    ['p', [0, 0, 1]],
    ['div p', [0, 0, 2]],
    ['.a', [0, 1, 0]],
    ['p.a.b', [0, 2, 1]],
    ['#x', [1, 0, 0]],
    ['#x .y p', [1, 1, 1]],
    ['a[href^="http"]', [0, 1, 1]],
    ['li:nth-child(2n+1)', [0, 1, 1]],
    ['ul > li + li ~ li', [0, 0, 4]],
    [':where(#x, .y) p', [0, 0, 1]],
    [':not(.x) p', [0, 1, 1]],
    ['[data-x="#not-an-id .nor-class"]', [0, 1, 0]],
]);

it('rejects selectors that can never match document content', function (string $selector) {
    expect(Selector::parse($selector))->toBeNull();
})->with(['', 'p::before', 'p:after', 'p:first-line', 'p[', '>>', 'p:unknown-pseudo(']);

it('matches elements through the native DOM engine', function () {
    $document = HTMLDocument::createFromString('<div class="a"><p id="x">t</p></div>', LIBXML_NOERROR);
    $paragraph = $document->getElementById('x');

    expect(Selector::parse('.a > #x')->matches($paragraph))->toBeTrue()
        ->and(Selector::parse('span #x')->matches($paragraph))->toBeFalse()
        ->and(Selector::parse('p:hover')->matches($paragraph))->toBeFalse();
});
