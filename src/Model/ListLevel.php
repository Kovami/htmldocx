<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class ListLevel
{
    /**
     * @param  string  $format  ST_NumberFormat value (bullet, decimal, lowerLetter, ...)
     * @param  string  $text  level text, e.g. "%1." or "•"
     * @param  int  $indentLeft  twips
     * @param  int  $hanging  twips
     */
    public function __construct(
        public int $level,
        public string $format,
        public string $text,
        public int $start,
        public int $indentLeft,
        public int $hanging,
    ) {}
}
