<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader\Format;

/**
 * A resolved table style: whole-table formatting plus the conditional
 * overrides (`w:tblStylePr`) for header rows, banding, corners and so on.
 */
final readonly class TableStyle
{
    /** Condition types in the order Word applies them, lowest priority first. */
    public const array CONDITION_ORDER = [
        'wholeTable', 'band1Vert', 'band2Vert', 'band1Horz', 'band2Horz',
        'firstCol', 'lastCol', 'firstRow', 'lastRow', 'neCell', 'nwCell', 'seCell', 'swCell',
    ];

    /**
     * @param  array<string, TableStyleCondition>  $conditions
     */
    public function __construct(
        public TableFormat $table = new TableFormat(),
        public TableStyleCondition $whole = new TableStyleCondition(),
        public array $conditions = [],
    ) {}

    public function over(self $top): self
    {
        $conditions = $this->conditions;

        foreach ($top->conditions as $type => $condition) {
            $conditions[$type] = isset($conditions[$type]) ? $conditions[$type]->over($condition) : $condition;
        }

        return new self($this->table->over($top->table), $this->whole->over($top->whole), $conditions);
    }
}
