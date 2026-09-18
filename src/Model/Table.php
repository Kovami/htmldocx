<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class Table implements Block
{
    /**
     * @param  list<int>  $gridColumns  column widths in twips
     * @param  list<TableRow>  $rows  every row spans exactly count($gridColumns) grid columns
     * @param  int  $marginTop  twips; Word tables have no vertical spacing, so it is moved onto the neighbouring paragraphs
     * @param  int  $marginBottom  twips; likewise
     */
    public function __construct(
        public TableProperties $properties,
        public array $gridColumns,
        public array $rows,
        public int $marginTop = 0,
        public int $marginBottom = 0,
    ) {}

    public function withMargins(int $top, int $bottom): self
    {
        return new self($this->properties, $this->gridColumns, $this->rows, $top, $bottom);
    }
}
