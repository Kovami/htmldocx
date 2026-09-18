<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class TableRow
{
    /**
     * @param  list<TableCell>  $cells
     * @param  int|null  $minHeight  twips
     */
    public function __construct(
        public array $cells,
        public bool $isHeader = false,
        public ?int $minHeight = null,
    ) {}
}
