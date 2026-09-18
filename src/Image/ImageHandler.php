<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Image;

use Kovami\HtmlDocx\Model\ImageData;

/**
 * Decides where a picture extracted from a DOCX document lives in the HTML:
 * return the `<img src>` (a data URI, a URL of stored bytes, ...) or null to
 * leave the picture out.
 */
interface ImageHandler
{
    public function source(ImageData $image, string $description): ?string;
}
