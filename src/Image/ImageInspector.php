<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Image;

use GdImage;
use Kovami\HtmlDocx\Model\ImageData;

/**
 * Identifies image bytes by content (never by file name or declared MIME
 * type) and keeps only formats Word renders. WebP is converted to PNG when
 * ext-gd supports it.
 */
final class ImageInspector
{
    private const array SUPPORTED = [
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_JPEG => ['jpeg', 'image/jpeg'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
        IMAGETYPE_BMP => ['bmp', 'image/bmp'],
        IMAGETYPE_TIFF_II => ['tiff', 'image/tiff'],
        IMAGETYPE_TIFF_MM => ['tiff', 'image/tiff'],
    ];

    private const array SIGNATURES = [
        "\x89PNG\r\n\x1a\n", "\xFF\xD8\xFF", 'GIF87a', 'GIF89a', 'BM', "II*\0", "MM\0*", 'RIFF',
    ];

    public function inspect(string $bytes): ?ImageData
    {
        if (strlen($bytes) < 16 || ! $this->hasKnownSignature($bytes)) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        if (isset(self::SUPPORTED[$info[2]])) {
            [$extension, $contentType] = self::SUPPORTED[$info[2]];

            return new ImageData($bytes, $extension, $contentType, $info[0], $info[1]);
        }

        if ($info[2] === IMAGETYPE_WEBP) {
            return $this->convertToPng($bytes);
        }

        return null;
    }

    private function hasKnownSignature(string $bytes): bool
    {
        foreach (self::SIGNATURES as $signature) {
            if (str_starts_with($bytes, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function convertToPng(string $bytes): ?ImageData
    {
        if (! function_exists('imagecreatefromstring') || (imagetypes() & IMG_WEBP) === 0) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return null;
        }

        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        return $png === '' ? null : new ImageData($png, 'png', 'image/png', imagesx($image), imagesy($image));
    }
}
