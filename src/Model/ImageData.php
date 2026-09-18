<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/** An embeddable raster image in a format Word can display. */
final readonly class ImageData
{
    public function __construct(
        public string $bytes,
        public string $extension,
        public string $contentType,
        public int $widthPx,
        public int $heightPx,
    ) {}

    public function hash(): string
    {
        return sha1($this->bytes);
    }
}
