<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class Border
{
    /**
     * @param  string  $style  ST_Border value (single, dotted, dashed, double, ...)
     * @param  int  $size  eighths of a point
     * @param  int  $space  distance from content, in points
     */
    public function __construct(
        public string $style,
        public int $size,
        public string $color,
        public int $space = 0,
    ) {}
}
