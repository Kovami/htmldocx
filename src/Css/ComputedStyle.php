<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

/**
 * The resolved style of one element. Inherited text properties are fully
 * computed; non-inherited box properties (margins, padding, borders, width,
 * background) stay as raw longhand declarations and are read on demand.
 */
final readonly class ComputedStyle
{
    public const array BLOCK_DISPLAYS = [
        'block', 'list-item', 'table', 'flex', 'grid', 'flow-root', 'table-caption',
        'table-row-group', 'table-header-group', 'table-footer-group', 'table-row', 'table-cell',
    ];

    /**
     * @param  string|null  $underline  CSS decoration style (solid, double, dotted, dashed, wavy) or null
     * @param  string|null  $inlineBackground  background painted behind inline text, propagated to inline descendants
     * @param  string  $verticalPosition  baseline, super or sub
     * @param  array<string, string>  $declarations  this element's own cascaded longhand declarations
     * @param  float  $relativeTopPt  how far relatively positioned blocks around it, itself included, move it down
     */
    public function __construct(
        public string $display,
        public string $fontFamily,
        public float $fontSizePt,
        public float $rootFontSizePt,
        public bool $bold,
        public bool $italic,
        public ?string $underline,
        public bool $strike,
        public string $color,
        public ?string $inlineBackground,
        public string $verticalPosition,
        public ?string $textAlign,
        public ?LineHeight $lineHeight,
        public ?float $textIndentPt,
        public string $textTransform,
        public bool $smallCaps,
        public float $letterSpacingPt,
        public bool $shadow,
        public string $whiteSpace,
        public string $direction,
        public string $listStyleType,
        public array $declarations,
        public float $relativeTopPt = 0.0,
        public bool $kerning = true,
        /** The font a browser (Chromium on macOS) draws where the style names a generic family that Word gets another font for. */
        public ?string $browserFamily = null,
    ) {}

    public static function root(string $fontFamily, float $fontSizePt, string $color): self
    {
        return new self(
            display: 'block',
            fontFamily: $fontFamily,
            fontSizePt: $fontSizePt,
            rootFontSizePt: $fontSizePt,
            bold: false,
            italic: false,
            underline: null,
            strike: false,
            color: $color,
            inlineBackground: null,
            verticalPosition: 'baseline',
            textAlign: null,
            lineHeight: null,
            textIndentPt: null,
            textTransform: 'none',
            smallCaps: false,
            letterSpacingPt: 0.0,
            shadow: false,
            whiteSpace: 'normal',
            direction: 'ltr',
            listStyleType: 'disc',
            declarations: [],
        );
    }

    public function value(string $property): ?string
    {
        $value = $this->declarations[$property] ?? null;

        return $value === null ? null : strtolower(trim($value));
    }

    public function isBlockLevel(): bool
    {
        return in_array($this->display, self::BLOCK_DISPLAYS, true);
    }

    public function preservesWhitespace(): bool
    {
        return in_array($this->whiteSpace, ['pre', 'pre-wrap', 'break-spaces'], true);
    }

    /**
     * Resolves a length property of this element to points. `auto`, missing
     * and unparsable values resolve to null.
     */
    public function lengthPt(string $property, ?float $percentBasePt = null): ?float
    {
        return Length::toPoints($this->declarations[$property] ?? null, $this->fontSizePt, $this->rootFontSizePt, $percentBasePt);
    }

    public function isAuto(string $property): bool
    {
        return $this->value($property) === 'auto';
    }

    public function backgroundColor(): ?string
    {
        return Color::toHex($this->declarations['background-color'] ?? null);
    }

    public function border(string $side): ?BorderEdge
    {
        $style = $this->value("border-{$side}-style") ?? 'none';

        if ($style === 'none' || $style === 'hidden') {
            return null;
        }

        $rawWidth = $this->value("border-{$side}-width") ?? 'medium';
        $width = match ($rawWidth) {
            'thin' => 0.75,
            'medium' => 2.25,
            'thick' => 3.75,
            default => Length::toPoints($rawWidth, $this->fontSizePt, $this->rootFontSizePt),
        };

        if ($width === null || $width <= 0) {
            return null;
        }

        $rawColor = $this->declarations["border-{$side}-color"] ?? 'currentcolor';
        $color = strtolower(trim($rawColor)) === 'currentcolor' ? $this->color : Color::toHex($rawColor);

        if ($color === null) {
            return null;
        }

        return new BorderEdge($style, $width, $color);
    }

    public function breaksPageBefore(): bool
    {
        return in_array($this->value('page-break-before') ?? $this->value('break-before'), ['always', 'page', 'left', 'right'], true);
    }

    public function breaksPageAfter(): bool
    {
        return in_array($this->value('page-break-after') ?? $this->value('break-after'), ['always', 'page', 'left', 'right'], true);
    }

    /** Whether a page may not break between this box and the next one. */
    public function keepsWithNext(): bool
    {
        return in_array($this->value('page-break-after') ?? $this->value('break-after'), ['avoid', 'avoid-page'], true);
    }

    /** Whether a page may not break inside this box. */
    public function keepsTogether(): bool
    {
        return in_array($this->value('page-break-inside') ?? $this->value('break-inside'), ['avoid', 'avoid-page'], true);
    }
}
