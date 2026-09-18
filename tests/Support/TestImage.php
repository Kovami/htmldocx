<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Tests\Support;

/** Builds real PNG files of any size without ext-gd. */
final class TestImage
{
    public static function png(int $width, int $height, string $rgb = '1E90FF'): string
    {
        $pixel = hex2bin($rgb);
        $rows = str_repeat("\0" . str_repeat((string) $pixel, $width), $height);

        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . self::chunk('IDAT', (string) gzcompress($rows))
            . self::chunk('IEND', '');
    }

    public static function pngDataUri(int $width, int $height, string $rgb = '1E90FF'): string
    {
        return 'data:image/png;base64,' . base64_encode(self::png($width, $height, $rgb));
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
