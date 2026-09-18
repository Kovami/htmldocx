<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

/**
 * CSS length parsing and the unit conversions OOXML needs: twips (1/20 pt),
 * half-points (font sizes), eighths of a point (borders) and EMUs (drawings).
 */
final class Length
{
    public const float POINTS_PER_PIXEL = 0.75;

    public const int TWIPS_PER_POINT = 20;

    public const int EMU_PER_PIXEL = 9525;

    /**
     * @param  float  $emBasePt  font size `em`/`ex`/`ch` resolve against
     * @param  float|null  $percentBasePt  what `%` refers to; null makes `%` unresolvable
     */
    public static function toPoints(?string $value, float $emBasePt, float $remBasePt, ?float $percentBasePt = null): ?float
    {
        $parsed = self::parse($value);

        if ($parsed === null) {
            return null;
        }

        [$number, $unit] = $parsed;

        return match ($unit) {
            '', 'pt' => $number,
            'px' => $number * self::POINTS_PER_PIXEL,
            'pc' => $number * 12,
            'in' => $number * 72,
            'cm' => $number / 2.54 * 72,
            'mm' => $number / 25.4 * 72,
            'q' => $number / 101.6 * 72,
            'em' => $number * $emBasePt,
            'rem' => $number * $remBasePt,
            'ex', 'ch' => $number * $emBasePt / 2,
            '%' => $percentBasePt === null ? null : $number / 100 * $percentBasePt,
            default => null,
        };
    }

    /**
     * @return array{0: float, 1: string}|null number and lowercase unit ('' only for a bare zero)
     */
    public static function parse(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        if (! preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)(px|pt|pc|in|cm|mm|q|em|rem|ex|ch|%)?$/', $value, $matches)) {
            return null;
        }

        $number = (float) $matches[1];
        $unit = $matches[2] ?? '';

        if ($unit === '' && $number !== 0.0) {
            return null;
        }

        return [$number, $unit];
    }

    public static function pointsToTwips(float $points): int
    {
        return (int) round($points * self::TWIPS_PER_POINT);
    }

    public static function twipsFromMillimeters(float $millimeters): int
    {
        return (int) round($millimeters / 25.4 * 1440);
    }

    public static function twipsFromInches(float $inches): int
    {
        return (int) round($inches * 1440);
    }

    public static function pixelsToEmu(float $pixels): int
    {
        return (int) round($pixels * self::EMU_PER_PIXEL);
    }

    public static function twipsToPixels(int $twips): float
    {
        return $twips / self::TWIPS_PER_POINT / self::POINTS_PER_PIXEL;
    }
}
