<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Html\Reader\BookmarkRegistry;

it('produces stable, unique, Word-compatible names', function () {
    $registry = new BookmarkRegistry();

    expect($registry->nameFor('intro'))->toBe('_intro')
        ->and($registry->nameFor('intro'))->toBe('_intro')
        ->and($registry->nameFor('intro!'))->toBe('_intro_')
        ->and($registry->nameFor('intro?'))->toBe('_intro__1')
        ->and($registry->nameFor(str_repeat('x', 100)))->toHaveLength(33)
        ->and($registry->nameFor('раздел'))->toMatch('/^_[A-Za-z0-9_]+$/');
});

it('hands out sequential ids', function () {
    $registry = new BookmarkRegistry();

    expect([$registry->nextId(), $registry->nextId(), $registry->nextId()])->toBe([0, 1, 2]);
});
