<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/** Twips. */
final readonly class CellMargins
{
    public function __construct(
        public int $top,
        public int $left,
        public int $bottom,
        public int $right,
    ) {}
}
