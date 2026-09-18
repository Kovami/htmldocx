<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/**
 * Character formatting. Null means "not specified"; {@see self::relativeTo()}
 * turns a fully resolved set into direct formatting on top of a style.
 */
final readonly class RunProperties
{
    /**
     * @param  int|null  $size  half-points
     * @param  string|null  $underline  ST_Underline value; "none" explicitly removes underline
     * @param  string|null  $verticalAlign  baseline, superscript or subscript
     * @param  int|null  $spacing  character spacing in twips
     */
    public function __construct(
        public ?string $fontFamily = null,
        public ?int $size = null,
        public ?bool $bold = null,
        public ?bool $italic = null,
        public ?string $underline = null,
        public ?bool $strike = null,
        public ?string $color = null,
        public ?string $shading = null,
        public ?string $verticalAlign = null,
        public ?bool $caps = null,
        public ?bool $smallCaps = null,
        public ?int $spacing = null,
        public ?bool $shadow = null,
        public ?bool $rtl = null,
    ) {}

    /** Keeps only the properties that differ from $base. */
    public function relativeTo(self $base): self
    {
        $differs = static fn (mixed $own, mixed $inherited): mixed => $own === $inherited ? null : $own;

        return new self(
            $differs($this->fontFamily, $base->fontFamily),
            $differs($this->size, $base->size),
            $differs($this->bold, $base->bold ?? false),
            $differs($this->italic, $base->italic ?? false),
            $differs($this->underline, $base->underline ?? 'none'),
            $differs($this->strike, $base->strike ?? false),
            $differs($this->color, $base->color),
            $differs($this->shading, $base->shading),
            $differs($this->verticalAlign, $base->verticalAlign ?? 'baseline'),
            $differs($this->caps, $base->caps ?? false),
            $differs($this->smallCaps, $base->smallCaps ?? false),
            $differs($this->spacing, $base->spacing ?? 0),
            $differs($this->shadow, $base->shadow ?? false),
            $differs($this->rtl, $base->rtl ?? false),
        );
    }

    public function isEmpty(): bool
    {
        return array_filter(get_object_vars($this), static fn (mixed $value): bool => $value !== null) === [];
    }

    public function equals(self $other): bool
    {
        return get_object_vars($this) === get_object_vars($other);
    }
}
