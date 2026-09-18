<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class TableCell
{
    /**
     * @param  list<Block>  $blocks  never empty, always ends with a Paragraph
     */
    public function __construct(
        public CellProperties $properties,
        public array $blocks,
    ) {}
}
