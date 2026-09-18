<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader\Format;

use Kovami\HtmlDocx\Model\RunProperties;

/**
 * Character formatting as written in one `w:rPr`: every property is
 * optional, and layers combine through {@see self::over()} and
 * {@see self::resolve()}.
 */
final readonly class RunFormat
{
    /** Properties that toggle rather than override when several styles set them (ECMA-376 §17.7.3). */
    public const array TOGGLES = ['bold', 'italic', 'strike', 'caps', 'smallCaps', 'hidden', 'shadow'];

    /**
     * @param  int|null  $size  half-points
     * @param  string|null  $color  RRGGBB or "auto"
     * @param  string|null  $highlight  RRGGBB, or "none" to remove
     * @param  string|null  $shading  RRGGBB, or "auto" for no fill
     * @param  int|null  $spacing  twips
     */
    public function __construct(
        public ?string $fontFamily = null,
        public ?int $size = null,
        public ?bool $bold = null,
        public ?bool $italic = null,
        public ?string $underline = null,
        public ?bool $strike = null,
        public ?string $color = null,
        public ?string $highlight = null,
        public ?string $shading = null,
        public ?string $verticalAlign = null,
        public ?bool $caps = null,
        public ?bool $smallCaps = null,
        public ?int $spacing = null,
        public ?bool $shadow = null,
        public ?bool $rtl = null,
        public ?bool $hidden = null,
    ) {}

    /** This format with the properties $top sets taking precedence. */
    public function over(self $top): self
    {
        return self::overlay($this, $top);
    }

    /**
     * Combines formatting layers the way Word does: document defaults, then
     * each style (toggle properties flip when set true in a style, other
     * properties override), then direct formatting, which always overrides.
     *
     * @param  list<self>  $styles  resolved style layers, lowest priority first
     */
    public static function resolve(self $defaults, array $styles, self $direct): self
    {
        $values = get_object_vars($defaults);

        foreach ($styles as $style) {
            foreach (get_object_vars($style) as $property => $value) {
                if ($value === null) {
                    continue;
                }

                $values[$property] = in_array($property, self::TOGGLES, true)
                    ? (($values[$property] ?? false) xor $value)
                    : $value;
            }
        }

        return self::overlay(new self(...$values), $direct);
    }

    public function toProperties(): RunProperties
    {
        $highlight = $this->highlight === 'none' ? null : $this->highlight;
        $shading = $this->shading === 'auto' ? null : $this->shading;

        return new RunProperties(
            fontFamily: $this->fontFamily,
            size: $this->size ?? 20,
            bold: $this->bold ?? false,
            italic: $this->italic ?? false,
            underline: $this->underline ?? 'none',
            strike: $this->strike ?? false,
            color: $this->color === null || $this->color === 'auto' ? '000000' : $this->color,
            shading: $highlight ?? $shading,
            verticalAlign: $this->verticalAlign ?? 'baseline',
            caps: $this->caps ?? false,
            smallCaps: $this->smallCaps ?? false,
            spacing: $this->spacing ?? 0,
            shadow: $this->shadow ?? false,
            rtl: $this->rtl ?? false,
        );
    }

    private static function overlay(self $base, self $top): self
    {
        $values = get_object_vars($base);

        foreach (get_object_vars($top) as $property => $value) {
            if ($value !== null) {
                $values[$property] = $value;
            }
        }

        return new self(...$values);
    }
}
