<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class ImageRun implements Inline
{
    /**
     * @param  int  $width  EMU
     * @param  int  $height  EMU
     * @param  string|null  $float  left or right when text wraps around the image
     */
    public function __construct(
        public ImageData $image,
        public int $width,
        public int $height,
        public string $description = '',
        public RunProperties $properties = new RunProperties(),
        public ?string $float = null,
    ) {}
}
