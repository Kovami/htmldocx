<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader\Format;

use Kovami\HtmlDocx\Model\Border;

/** Table-level formatting (`w:tblPr`); see {@see RunFormat} for layering. */
final readonly class TableFormat
{
    /**
     * @param  array{0: int, 1: string}|null  $width  value and ST_TblWidth type (dxa, pct, auto, nil)
     * @param  array<string, Border>  $borders  top, left, bottom, right, insideH, insideV
     * @param  array<string, int>  $cellMargins  top, left, bottom, right (twips)
     * @param  int|null  $indent  twips
     */
    public function __construct(
        public ?string $styleId = null,
        public ?array $width = null,
        public ?string $alignment = null,
        public ?int $indent = null,
        public array $borders = [],
        public array $cellMargins = [],
        public ?string $shading = null,
        public ?bool $bidi = null,
        public ?int $rowBandSize = null,
        public ?int $columnBandSize = null,
    ) {}

    public function over(self $top): self
    {
        $values = get_object_vars($this);

        foreach (get_object_vars($top) as $property => $value) {
            $values[$property] = match ($property) {
                'borders' => [...$this->borders, ...$top->borders],
                'cellMargins' => [...$this->cellMargins, ...$top->cellMargins],
                default => $value ?? $values[$property],
            };
        }

        return new self(...$values);
    }
}
