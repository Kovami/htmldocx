<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Config;

use InvalidArgumentException;
use Kovami\HtmlDocx\Css\Length;

/**
 * The physical page of the generated document, in twips. Kept apart from
 * the HTML on purpose: editor output has no notion of paper, so size,
 * orientation and margins come from application configuration.
 */
final readonly class PageLayout
{
    private function __construct(
        public int $widthTwips,
        public int $heightTwips,
        public int $marginTopTwips,
        public int $marginRightTwips,
        public int $marginBottomTwips,
        public int $marginLeftTwips,
    ) {
        if ($widthTwips <= $marginLeftTwips + $marginRightTwips || $heightTwips <= $marginTopTwips + $marginBottomTwips) {
            throw new InvalidArgumentException('Page margins leave no room for content.');
        }
    }

    public static function fromTwips(int $width, int $height, int $marginTop, int $marginRight, int $marginBottom, int $marginLeft): self
    {
        return new self($width, $height, $marginTop, $marginRight, $marginBottom, $marginLeft);
    }

    public static function a4Portrait(float $marginCm = 2.0): self
    {
        return self::fromMillimeters(210, 297, $marginCm * 10);
    }

    public static function a4Landscape(float $marginCm = 2.0): self
    {
        return self::fromMillimeters(297, 210, $marginCm * 10);
    }

    public static function letterPortrait(float $marginInches = 1.0): self
    {
        return self::fromInches(8.5, 11, $marginInches);
    }

    public static function letterLandscape(float $marginInches = 1.0): self
    {
        return self::fromInches(11, 8.5, $marginInches);
    }

    public static function fromMillimeters(float $widthMm, float $heightMm, float $marginMm = 20.0): self
    {
        $margin = Length::twipsFromMillimeters($marginMm);

        return new self(
            Length::twipsFromMillimeters($widthMm),
            Length::twipsFromMillimeters($heightMm),
            $margin, $margin, $margin, $margin,
        );
    }

    public static function fromInches(float $widthInches, float $heightInches, float $marginInches = 1.0): self
    {
        $margin = Length::twipsFromInches($marginInches);

        return new self(
            Length::twipsFromInches($widthInches),
            Length::twipsFromInches($heightInches),
            $margin, $margin, $margin, $margin,
        );
    }

    /**
     * @param  array{size?: string, orientation?: string, margin_cm?: float|int}  $config
     */
    public static function fromArray(array $config): self
    {
        $marginCm = (float) ($config['margin_cm'] ?? 2.0);
        $landscape = strtolower((string) ($config['orientation'] ?? 'portrait')) === 'landscape';

        return match (strtolower((string) ($config['size'] ?? 'a4'))) {
            'letter' => $landscape ? self::letterLandscape($marginCm / 2.54) : self::letterPortrait($marginCm / 2.54),
            default => $landscape ? self::a4Landscape($marginCm) : self::a4Portrait($marginCm),
        };
    }

    public function withMargins(float $topCm, float $rightCm, float $bottomCm, float $leftCm): self
    {
        return new self(
            $this->widthTwips,
            $this->heightTwips,
            Length::twipsFromMillimeters($topCm * 10),
            Length::twipsFromMillimeters($rightCm * 10),
            Length::twipsFromMillimeters($bottomCm * 10),
            Length::twipsFromMillimeters($leftCm * 10),
        );
    }

    public function isLandscape(): bool
    {
        return $this->widthTwips > $this->heightTwips;
    }

    public function contentWidthTwips(): int
    {
        return $this->widthTwips - $this->marginLeftTwips - $this->marginRightTwips;
    }
}
