<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Image;

/**
 * Turns an `<img src>` value into raw image bytes. Implement this to load
 * images from application storage, a CDN allowlist, etc.
 */
interface ImageSourceResolver
{
    public function resolve(string $source): ?string;
}
