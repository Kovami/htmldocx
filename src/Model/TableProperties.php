<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class TableProperties
{
    /**
     * @param  int  $width  twips
     * @param  string|null  $alignment  left, center or right
     * @param  int  $indentLeft  twips
     */
    public function __construct(
        public int $width,
        public ?string $alignment = null,
        public int $indentLeft = 0,
        public BorderSet $borders = new BorderSet,
        public ?string $shading = null,
        public bool $bidi = false,
    ) {}
}
