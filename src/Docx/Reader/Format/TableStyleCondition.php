<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader\Format;

/** Formatting a table style applies to the cells matching one condition. */
final readonly class TableStyleCondition
{
    public function __construct(
        public TableFormat $table = new TableFormat(),
        public CellFormat $cell = new CellFormat(),
        public ParagraphFormat $paragraph = new ParagraphFormat(),
        public RunFormat $run = new RunFormat(),
    ) {}

    public function over(self $top): self
    {
        return new self(
            $this->table->over($top->table),
            $this->cell->over($top->cell),
            $this->paragraph->over($top->paragraph),
            $this->run->over($top->run),
        );
    }
}
