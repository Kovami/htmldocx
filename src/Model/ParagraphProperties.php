<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/**
 * Paragraph formatting. Mutable on purpose: the builder adjusts spacing
 * after the fact (collapsing vertical margins between neighbours).
 * Lengths are twips; a negative $firstLine is a hanging indent.
 */
final class ParagraphProperties
{
    public function __construct(
        public ?string $styleId = null,
        public ?string $alignment = null,
        public int $indentLeft = 0,
        public int $indentRight = 0,
        public int $firstLine = 0,
        public ?int $spacingBefore = null,
        public ?int $spacingAfter = null,
        public ?int $lineSpacing = null,
        public ?string $lineRule = null,
        public bool $keepNext = false,
        public bool $keepLines = false,
        public bool $pageBreakBefore = false,
        public ?string $shading = null,
        public BorderSet $borders = new BorderSet,
        public ?NumberingReference $numbering = null,
        public bool $bidi = false,
        public ?int $outlineLevel = null,
        public ?RunProperties $markRunProperties = null,
    ) {}
}
