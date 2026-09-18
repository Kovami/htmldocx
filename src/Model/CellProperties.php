<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class CellProperties
{
    public const string MERGE_RESTART = 'restart';

    public const string MERGE_CONTINUE = 'continue';

    /**
     * @param  int  $width  twips
     * @param  string|null  $verticalMerge  restart, continue or null
     * @param  string|null  $verticalAlign  top, center or bottom
     */
    public function __construct(
        public int $width,
        public int $gridSpan = 1,
        public ?string $verticalMerge = null,
        public ?string $shading = null,
        public ?string $verticalAlign = null,
        public BorderSet $borders = new BorderSet,
        public ?CellMargins $margins = null,
        public bool $noWrap = false,
    ) {}
}
