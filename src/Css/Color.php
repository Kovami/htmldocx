<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

/**
 * Parses CSS colors into uppercase RRGGBB hex. Translucent colors are
 * composited over white, since DOCX has no alpha for text or shading.
 */
final class Color
{
    private const array NAMED = [
        'aliceblue' => 'F0F8FF', 'antiquewhite' => 'FAEBD7', 'aqua' => '00FFFF', 'aquamarine' => '7FFFD4',
        'azure' => 'F0FFFF', 'beige' => 'F5F5DC', 'bisque' => 'FFE4C4', 'black' => '000000',
        'blanchedalmond' => 'FFEBCD', 'blue' => '0000FF', 'blueviolet' => '8A2BE2', 'brown' => 'A52A2A',
        'burlywood' => 'DEB887', 'cadetblue' => '5F9EA0', 'chartreuse' => '7FFF00', 'chocolate' => 'D2691E',
        'coral' => 'FF7F50', 'cornflowerblue' => '6495ED', 'cornsilk' => 'FFF8DC', 'crimson' => 'DC143C',
        'cyan' => '00FFFF', 'darkblue' => '00008B', 'darkcyan' => '008B8B', 'darkgoldenrod' => 'B8860B',
        'darkgray' => 'A9A9A9', 'darkgreen' => '006400', 'darkgrey' => 'A9A9A9', 'darkkhaki' => 'BDB76B',
        'darkmagenta' => '8B008B', 'darkolivegreen' => '556B2F', 'darkorange' => 'FF8C00', 'darkorchid' => '9932CC',
        'darkred' => '8B0000', 'darksalmon' => 'E9967A', 'darkseagreen' => '8FBC8F', 'darkslateblue' => '483D8B',
        'darkslategray' => '2F4F4F', 'darkslategrey' => '2F4F4F', 'darkturquoise' => '00CED1', 'darkviolet' => '9400D3',
        'deeppink' => 'FF1493', 'deepskyblue' => '00BFFF', 'dimgray' => '696969', 'dimgrey' => '696969',
        'dodgerblue' => '1E90FF', 'firebrick' => 'B22222', 'floralwhite' => 'FFFAF0', 'forestgreen' => '228B22',
        'fuchsia' => 'FF00FF', 'gainsboro' => 'DCDCDC', 'ghostwhite' => 'F8F8FF', 'gold' => 'FFD700',
        'goldenrod' => 'DAA520', 'gray' => '808080', 'green' => '008000', 'greenyellow' => 'ADFF2F',
        'grey' => '808080', 'honeydew' => 'F0FFF0', 'hotpink' => 'FF69B4', 'indianred' => 'CD5C5C',
        'indigo' => '4B0082', 'ivory' => 'FFFFF0', 'khaki' => 'F0E68C', 'lavender' => 'E6E6FA',
        'lavenderblush' => 'FFF0F5', 'lawngreen' => '7CFC00', 'lemonchiffon' => 'FFFACD', 'lightblue' => 'ADD8E6',
        'lightcoral' => 'F08080', 'lightcyan' => 'E0FFFF', 'lightgoldenrodyellow' => 'FAFAD2', 'lightgray' => 'D3D3D3',
        'lightgreen' => '90EE90', 'lightgrey' => 'D3D3D3', 'lightpink' => 'FFB6C1', 'lightsalmon' => 'FFA07A',
        'lightseagreen' => '20B2AA', 'lightskyblue' => '87CEFA', 'lightslategray' => '778899', 'lightslategrey' => '778899',
        'lightsteelblue' => 'B0C4DE', 'lightyellow' => 'FFFFE0', 'lime' => '00FF00', 'limegreen' => '32CD32',
        'linen' => 'FAF0E6', 'magenta' => 'FF00FF', 'maroon' => '800000', 'mediumaquamarine' => '66CDAA',
        'mediumblue' => '0000CD', 'mediumorchid' => 'BA55D3', 'mediumpurple' => '9370DB', 'mediumseagreen' => '3CB371',
        'mediumslateblue' => '7B68EE', 'mediumspringgreen' => '00FA9A', 'mediumturquoise' => '48D1CC', 'mediumvioletred' => 'C71585',
        'midnightblue' => '191970', 'mintcream' => 'F5FFFA', 'mistyrose' => 'FFE4E1', 'moccasin' => 'FFE4B5',
        'navajowhite' => 'FFDEAD', 'navy' => '000080', 'oldlace' => 'FDF5E6', 'olive' => '808000',
        'olivedrab' => '6B8E23', 'orange' => 'FFA500', 'orangered' => 'FF4500', 'orchid' => 'DA70D6',
        'palegoldenrod' => 'EEE8AA', 'palegreen' => '98FB98', 'paleturquoise' => 'AFEEEE', 'palevioletred' => 'DB7093',
        'papayawhip' => 'FFEFD5', 'peachpuff' => 'FFDAB9', 'peru' => 'CD853F', 'pink' => 'FFC0CB',
        'plum' => 'DDA0DD', 'powderblue' => 'B0E0E6', 'purple' => '800080', 'rebeccapurple' => '663399',
        'red' => 'FF0000', 'rosybrown' => 'BC8F8F', 'royalblue' => '4169E1', 'saddlebrown' => '8B4513',
        'salmon' => 'FA8072', 'sandybrown' => 'F4A460', 'seagreen' => '2E8B57', 'seashell' => 'FFF5EE',
        'sienna' => 'A0522D', 'silver' => 'C0C0C0', 'skyblue' => '87CEEB', 'slateblue' => '6A5ACD',
        'slategray' => '708090', 'slategrey' => '708090', 'snow' => 'FFFAFA', 'springgreen' => '00FF7F',
        'steelblue' => '4682B4', 'tan' => 'D2B48C', 'teal' => '008080', 'thistle' => 'D8BFD8',
        'tomato' => 'FF6347', 'turquoise' => '40E0D0', 'violet' => 'EE82EE', 'wheat' => 'F5DEB3',
        'white' => 'FFFFFF', 'whitesmoke' => 'F5F5F5', 'yellow' => 'FFFF00', 'yellowgreen' => '9ACD32',
    ];

    /**
     * @return string|null RRGGBB, or null for transparent/unrecognized values
     */
    public static function toHex(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        if (isset(self::NAMED[$value])) {
            return self::NAMED[$value];
        }

        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value, $m)) {
            return self::fromHexDigits($m[1]);
        }

        if (preg_match('/^(rgba?|hsla?)\((.*)\)$/', $value, $m)) {
            $parts = preg_split('/[\s,\/]+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($parts) < 3) {
                return null;
            }

            $alpha = isset($parts[3]) ? self::alphaChannel($parts[3]) : 1.0;

            [$r, $g, $b] = str_starts_with($m[1], 'rgb')
                ? [self::rgbChannel($parts[0]), self::rgbChannel($parts[1]), self::rgbChannel($parts[2])]
                : self::hslToRgb((float) $parts[0], (float) $parts[1], (float) $parts[2]);

            return self::composite($r, $g, $b, $alpha);
        }

        return null;
    }

    private static function fromHexDigits(string $digits): ?string
    {
        if (strlen($digits) <= 4) {
            $digits = implode('', array_map(static fn (string $c): string => $c.$c, str_split($digits)));
        }

        $alpha = strlen($digits) === 8 ? hexdec(substr($digits, 6, 2)) / 255 : 1.0;

        return self::composite(
            (int) hexdec(substr($digits, 0, 2)),
            (int) hexdec(substr($digits, 2, 2)),
            (int) hexdec(substr($digits, 4, 2)),
            $alpha,
        );
    }

    private static function rgbChannel(string $value): int
    {
        $number = str_ends_with($value, '%') ? (float) $value / 100 * 255 : (float) $value;

        return (int) round(max(0, min(255, $number)));
    }

    private static function alphaChannel(string $value): float
    {
        $number = str_ends_with($value, '%') ? (float) $value / 100 : (float) $value;

        return max(0.0, min(1.0, $number));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hslToRgb(float $hue, float $saturation, float $lightness): array
    {
        $h = fmod(fmod($hue, 360) + 360, 360) / 360;
        $s = max(0, min(100, $saturation)) / 100;
        $l = max(0, min(100, $lightness)) / 100;

        if ($s === 0.0) {
            $gray = (int) round($l * 255);

            return [$gray, $gray, $gray];
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        $channel = static function (float $t) use ($p, $q): int {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);

            $value = match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };

            return (int) round($value * 255);
        };

        return [$channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3)];
    }

    private static function composite(int $r, int $g, int $b, float $alpha): ?string
    {
        if ($alpha <= 0.0) {
            return null;
        }

        $blend = static fn (int $channel): int => (int) round($channel * $alpha + 255 * (1 - $alpha));

        return sprintf('%02X%02X%02X', $blend($r), $blend($g), $blend($b));
    }
}
