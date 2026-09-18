<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

final readonly class BorderEdge
{
    public function __construct(
        public string $style,
        public float $widthPt,
        public string $color,
    ) {}
}
