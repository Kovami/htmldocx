<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Image;

use Closure;
use Kovami\HtmlDocx\Model\ImageData;

/** Adapts a closure `fn (ImageData $image, string $description): ?string` to {@see ImageHandler}. */
final readonly class CallbackImageHandler implements ImageHandler
{
    /**
     * @param  Closure(ImageData, string): ?string  $callback
     */
    public function __construct(private Closure $callback) {}

    public function source(ImageData $image, string $description): ?string
    {
        return ($this->callback)($image, $description);
    }
}
