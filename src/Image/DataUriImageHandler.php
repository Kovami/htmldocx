<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Image;

use Kovami\HtmlDocx\Model\ImageData;

/** Embeds pictures into the HTML as base64 data URIs. */
final readonly class DataUriImageHandler implements ImageHandler
{
    public function source(ImageData $image, string $description): string
    {
        return 'data:' . $image->contentType . ';base64,' . base64_encode($image->bytes);
    }
}
