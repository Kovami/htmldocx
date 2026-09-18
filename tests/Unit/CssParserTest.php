<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Css\CssParser;
use Kovami\HtmlDocx\Css\CssRule;
use Kovami\HtmlDocx\Css\Origin;

function parseCss(string $css): array
{
    $order = 0;

    return array_map(
        static fn(CssRule $rule): string => $rule->selector->text . ' => ' . implode(';', array_map(static fn($d) => "{$d->property}:{$d->value}", $rule->declarations)),
        CssParser::parse($css, Origin::Author, $order),
    );
}

it('splits selector lists into rules sharing declarations', function () {
    expect(parseCss('h1, h2 , :is(p, div) { color: red }'))->toBe([
        'h1 => color:red', 'h2 => color:red', ':is(p, div) => color:red',
    ]);
});

it('assigns increasing source order across stylesheets', function () {
    $order = 0;
    $first = CssParser::parse('a { color: red } b { color: red }', Origin::Author, $order);
    $second = CssParser::parse('c { color: red }', Origin::Base, $order);

    expect(array_map(static fn(CssRule $r): int => $r->sourceOrder, [...$first, ...$second]))->toBe([0, 1, 2])
        ->and($second[0]->origin)->toBe(Origin::Base);
});

it('keeps print media and supports blocks, drops other at-rules', function () {
    $css = <<<'CSS'
        @charset "utf-8";
        @import url("x.css");
        @font-face { font-family: X; src: url(x.woff) }
        @media screen { .screen { color: red } }
        @media print { .print { color: red } @media (min-width: 1px) { .nested { color: red } } }
        @media not print { .notprint { color: red } }
        @supports (display: grid) { .supports { color: red } }
        @page { margin: 1cm }
        @keyframes spin { from { color: red } to { color: blue } }
        .after { color: red }
        CSS;

    expect(parseCss($css))->toBe(['.print => color:red', '.supports => color:red', '.after => color:red']);
});

it('ignores comments, HTML comment markers and unbalanced garbage', function () {
    expect(parseCss('<!-- /* .a { color: red } */ .b { color: red } --> } .c { color: blue'))->toBe([
        '.b => color:red', '.c => color:blue',
    ]);
});

it('drops rules with pseudo-elements or invalid selectors', function () {
    expect(parseCss('p::before { color: red } p:after { color: red } p[ { color: red } p >> a { color: red } .ok { color: red }'))
        ->toBe(['.ok => color:red']);
});

it('drops rules without declarations', function () {
    expect(parseCss('.empty {} .comment { /* x */ }'))->toBe([]);
});
