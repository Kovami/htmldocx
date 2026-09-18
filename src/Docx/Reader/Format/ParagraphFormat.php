<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader\Format;

use Kovami\HtmlDocx\Model\Border;

/** Paragraph formatting as written in one `w:pPr`; see {@see RunFormat} for layering. */
final readonly class ParagraphFormat
{
    /**
     * Lengths are twips; $firstLine is negative for a hanging indent.
     *
     * @param  array<string, Border>  $borders  side (top, left, bottom, right, between) => border; style "none" removes
     * @param  string|null  $numId  "0" removes numbering inherited from a style
     */
    public function __construct(
        public ?string $alignment = null,
        public ?int $indentLeft = null,
        public ?int $indentRight = null,
        public ?int $firstLine = null,
        public ?int $spacingBefore = null,
        public ?int $spacingAfter = null,
        public ?bool $beforeAutospacing = null,
        public ?bool $afterAutospacing = null,
        public ?int $lineSpacing = null,
        public ?string $lineRule = null,
        public ?bool $contextualSpacing = null,
        public ?bool $keepNext = null,
        public ?bool $keepLines = null,
        public ?bool $pageBreakBefore = null,
        public ?string $shading = null,
        public array $borders = [],
        public ?string $numId = null,
        public ?int $level = null,
        public ?int $outlineLevel = null,
        public ?bool $bidi = null,
        public RunFormat $mark = new RunFormat(),
    ) {}

    public function over(self $top): self
    {
        $values = get_object_vars($this);

        foreach (get_object_vars($top) as $property => $value) {
            $values[$property] = match ($property) {
                'borders' => [...$this->borders, ...$top->borders],
                'mark' => $this->mark->over($top->mark),
                default => $value ?? $values[$property],
            };
        }

        return new self(...$values);
    }
}
