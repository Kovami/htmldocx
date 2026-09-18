<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Dom\Element;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\Length;
use Kovami\HtmlDocx\Image\ImageInspector;
use Kovami\HtmlDocx\Image\ImageSourceResolver;
use Kovami\HtmlDocx\Model\ImageData;
use Kovami\HtmlDocx\Model\ImageRun;
use Kovami\HtmlDocx\Model\RunProperties;

/**
 * Resolves `<img>` elements into embeddable images sized like the browser
 * would size them (CSS width/height, then attributes, then intrinsic size,
 * keeping the aspect ratio), but never wider than the space available.
 */
final class ImageFactory
{
    /** @var array<string, ImageData|null> */
    private array $cache = [];

    public function __construct(
        private readonly ImageSourceResolver $resolver,
        private readonly ImageInspector $inspector,
    ) {}

    public function create(Element $element, ComputedStyle $style, int $availableWidthTwips, RunProperties $properties): ?ImageRun
    {
        $source = trim((string) $element->getAttribute('src'));

        if ($source === '') {
            return null;
        }

        if (! array_key_exists($source, $this->cache)) {
            $bytes = $this->resolver->resolve($source);
            $this->cache[$source] = $bytes === null ? null : $this->inspector->inspect($bytes);
        }

        $image = $this->cache[$source];

        if ($image === null) {
            return null;
        }

        $maxWidthPx = max(1.0, Length::twipsToPixels($availableWidthTwips));
        $ratio = $image->heightPx / $image->widthPx;

        $width = $this->pixels($style, 'width', $maxWidthPx);
        $height = $this->pixels($style, 'height', null);

        [$width, $height] = match (true) {
            $width !== null && $height !== null => [$width, $height],
            $width !== null => [$width, $width * $ratio],
            $height !== null => [$height / $ratio, $height],
            default => [(float) $image->widthPx, (float) $image->heightPx],
        };

        if ($width > $maxWidthPx) {
            $height *= $maxWidthPx / $width;
            $width = $maxWidthPx;
        }

        return new ImageRun(
            image: $image,
            width: Length::pixelsToEmu(max(1.0, $width)),
            height: Length::pixelsToEmu(max(1.0, $height)),
            description: trim((string) ($element->getAttribute('alt') ?? '')),
            properties: $properties,
        );
    }

    private function pixels(ComputedStyle $style, string $property, ?float $percentBasePx): ?float
    {
        $points = $style->lengthPt($property, $percentBasePx === null ? null : $percentBasePx * Length::POINTS_PER_PIXEL);

        return $points !== null && $points > 0 ? $points / Length::POINTS_PER_PIXEL : null;
    }
}
