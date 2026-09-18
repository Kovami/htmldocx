<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader\Format;

use Kovami\HtmlDocx\Model\Border;

/** Cell formatting (`w:tcPr`), also the cell part of table style conditions. */
final readonly class CellFormat
{
    /**
     * @param  array{0: int, 1: string}|null  $width
     * @param  array<string, Border>  $borders  top, left, bottom, right, insideH, insideV
     * @param  array<string, int>  $margins  top, left, bottom, right (twips)
     * @param  string|null  $verticalMerge  restart or continue
     */
    public function __construct(
        public ?array $width = null,
        public ?int $gridSpan = null,
        public ?string $verticalMerge = null,
        public ?string $horizontalMerge = null,
        public array $borders = [],
        public ?string $shading = null,
        public ?string $verticalAlign = null,
        public array $margins = [],
        public ?bool $noWrap = null,
    ) {}

    public function over(self $top): self
    {
        $values = get_object_vars($this);

        foreach (get_object_vars($top) as $property => $value) {
            $values[$property] = match ($property) {
                'borders' => [...$this->borders, ...$top->borders],
                'margins' => [...$this->margins, ...$top->margins],
                default => $value ?? $values[$property],
            };
        }

        return new self(...$values);
    }
}
