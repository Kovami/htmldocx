<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class NumberingReference
{
    /**
     * @param  int|null  $ordinal  the item's counter value, when the source already numbered it (DOCX)
     * @param  string|null  $label  the marker text as displayed, e.g. "1.2." or "•"
     */
    public function __construct(
        public int $numId,
        public int $level,
        public ?int $ordinal = null,
        public ?string $label = null,
    ) {}
}
