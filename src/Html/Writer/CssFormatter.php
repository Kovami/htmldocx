<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Writer;

use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\Length;
use Kovami\HtmlDocx\Model\Border;
use Kovami\HtmlDocx\Model\BorderSet;

/** Serializes model values as CSS in the unit the output is configured for. */
final readonly class CssFormatter
{
    /**
     * @param  string  $unit  "px" or "pt"
     */
    public function __construct(private string $unit = 'px') {}

    public function points(float $points): string
    {
        if (abs($points) < 0.005) {
            return '0';
        }

        $value = $this->unit === 'pt' ? $points : $points / Length::POINTS_PER_PIXEL;

        return self::number($value) . $this->unit;
    }

    public function twips(int $twips): string
    {
        return $this->points($twips / Length::TWIPS_PER_POINT);
    }

    /**
     * A length declaration, or null when the editor's stylesheet already
     * places the edge there. Word stores lengths in twips and the editor's
     * own values are computed from font sizes, so a twip of rounding is not
     * a difference worth writing.
     *
     * @param  float|null  $editorPt  what the editor's stylesheet gives the element, in points
     */
    public function length(int $twips, ?float $editorPt): ?string
    {
        return abs($twips - Length::pointsToTwips($editorPt ?? 0.0)) <= 1 ? null : $this->twips($twips);
    }

    public static function color(string $hex): string
    {
        return '#' . strtolower($hex);
    }

    public function border(?Border $border): string
    {
        if ($border === null) {
            return 'none';
        }

        $style = match ($border->style) {
            'double', 'triple', 'thinThickSmallGap', 'thickThinSmallGap', 'thinThickMediumGap', 'thickThinMediumGap',
            'thinThickLargeGap', 'thickThinLargeGap', 'thinThickThinSmallGap', 'thinThickThinMediumGap', 'thinThickThinLargeGap' => 'double',
            'dotted' => 'dotted',
            'dashed', 'dashSmallGap', 'dotDash', 'dotDotDash', 'dashDotStroked' => 'dashed',
            'threeDEmboss', 'ridge' => 'ridge',
            'threeDEngrave', 'groove' => 'groove',
            'inset' => 'inset',
            'outset' => 'outset',
            default => 'solid',
        };

        return $this->points($border->size / 8) . ' ' . $style . ' ' . self::color($border->color === 'auto' ? '000000' : $border->color);
    }

    /**
     * The border declarations a box needs to look like $borders, given what
     * the editor's stylesheet already draws around it: a shorthand when all
     * four sides agree, nothing at all when they already match.
     *
     * @return array<string, string>
     */
    public function sides(BorderSet $borders, ComputedStyle $baseline): array
    {
        $sides = [];

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $wanted = $this->border($borders->{$side});
            $edge = $baseline->border($side);
            $current = $edge === null ? 'none' : $this->points($edge->widthPt) . ' ' . $edge->style . ' ' . self::color($edge->color);
            $sides[$side] = [$wanted, $wanted !== $current];
        }

        $changed = array_filter($sides, static fn(array $side): bool => $side[1]);

        if ($changed === []) {
            return [];
        }

        $values = array_unique(array_column($sides, 0));

        if (count($values) === 1) {
            return ['border' => $values[0]];
        }

        $css = [];

        foreach ($changed as $side => [$value]) {
            $css["border-{$side}"] = $value;
        }

        return $css;
    }

    public static function fontFamily(string $family): string
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $family) === 1 && ! in_array(strtolower($family), ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'inherit', 'initial'], true)
            ? $family
            : '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $family) . '"';
    }

    /** A CSS string, e.g. for `list-style-type: "1.2. "`. */
    public static function string(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\A '], $value) . '"';
    }

    public static function number(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * @param  array<string, string>  $declarations
     */
    public static function declarations(array $declarations): string
    {
        return implode(' ', array_map(
            static fn(string $property, string $value): string => "{$property}: {$value};",
            array_keys($declarations),
            $declarations,
        ));
    }
}
