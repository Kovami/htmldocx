<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

/**
 * A resolved `line-height`: either a multiple of the font size (unitless or
 * percentage values, which Word expresses as "auto" spacing) or an absolute
 * length in points.
 */
final readonly class LineHeight
{
    private function __construct(
        public ?float $multiple,
        public ?float $points,
    ) {}

    public static function multiple(float $multiple): self
    {
        return new self($multiple, null);
    }

    public static function points(float $points): self
    {
        return new self(null, $points);
    }
}
