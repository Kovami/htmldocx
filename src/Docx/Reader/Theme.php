<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Dom\Element;
use Dom\XMLDocument;
use Kovami\HtmlDocx\Docx\Namespaces;

/** The fonts and colour scheme of word/theme/theme1.xml that styles refer to by role. */
final readonly class Theme
{
    /** System colour fallbacks for `a:sysClr` without a lastClr. */
    private const array SYSTEM_COLORS = ['windowText' => '000000', 'window' => 'FFFFFF'];

    /**
     * @param  array<string, string>  $fonts  e.g. "minorHAnsi" => "Calibri"
     * @param  array<string, string>  $colors  scheme slot ("dk1", "accent1", ...) => RRGGBB
     */
    public function __construct(
        private array $fonts = [],
        private array $colors = [],
    ) {}

    public static function fromXml(XMLDocument $document): self
    {
        $elements = Xml::child($document->documentElement, 'themeElements', Namespaces::A);
        $fonts = [];
        $colors = [];

        foreach (['major' => 'majorFont', 'minor' => 'minorFont'] as $role => $name) {
            $font = Xml::child(Xml::child($elements, 'fontScheme', Namespaces::A), $name, Namespaces::A);
            $latin = self::typeface(Xml::child($font, 'latin', Namespaces::A));

            $fonts[$role.'HAnsi'] = $latin;
            $fonts[$role.'Ascii'] = $latin;
            $fonts[$role.'EastAsia'] = self::typeface(Xml::child($font, 'ea', Namespaces::A)) ?: $latin;
            $fonts[$role.'Bidi'] = self::typeface(Xml::child($font, 'cs', Namespaces::A)) ?: $latin;
        }

        foreach (Xml::children(Xml::child($elements, 'clrScheme', Namespaces::A), null) as $slot) {
            $color = $slot->firstElementChild;

            $value = match (true) {
                Xml::is($color, 'srgbClr', Namespaces::A) => $color?->getAttribute('val'),
                Xml::is($color, 'sysClr', Namespaces::A) => $color?->getAttribute('lastClr') ?: (self::SYSTEM_COLORS[(string) $color?->getAttribute('val')] ?? null),
                default => null,
            };

            if ($value !== null && preg_match('/^[0-9a-f]{6}$/i', $value) === 1) {
                $colors[(string) $slot->localName] = strtoupper($value);
            }
        }

        return new self(array_filter($fonts), $colors);
    }

    /** Resolves `w:asciiTheme` etc. values such as "minorHAnsi". */
    public function font(?string $themeFont): ?string
    {
        return $themeFont === null ? null : ($this->fonts[$themeFont] ?? null);
    }

    /**
     * Resolves a `w:themeColor` (with optional `w:themeTint`/`w:themeShade`
     * hex bytes) to RRGGBB.
     */
    public function color(?string $themeColor, ?string $tint = null, ?string $shade = null): ?string
    {
        $slot = match ($themeColor) {
            'text1', 'dark1' => 'dk1',
            'background1', 'light1' => 'lt1',
            'text2', 'dark2' => 'dk2',
            'background2', 'light2' => 'lt2',
            'hyperlink' => 'hlink',
            'followedHyperlink' => 'folHlink',
            default => $themeColor,
        };

        $color = $slot === null ? null : ($this->colors[$slot] ?? null);

        if ($color === null) {
            return null;
        }

        [$r, $g, $b] = array_map(hexdec(...), str_split($color, 2));

        if ($tint !== null && preg_match('/^[0-9a-f]{2}$/i', $tint) === 1) {
            $factor = hexdec($tint) / 255;
            [$r, $g, $b] = array_map(static fn (int|float $c): float => $c * $factor + 255 * (1 - $factor), [$r, $g, $b]);
        }

        if ($shade !== null && preg_match('/^[0-9a-f]{2}$/i', $shade) === 1) {
            $factor = hexdec($shade) / 255;
            [$r, $g, $b] = array_map(static fn (int|float $c): float => $c * $factor, [$r, $g, $b]);
        }

        return sprintf('%02X%02X%02X', (int) round($r), (int) round($g), (int) round($b));
    }

    private static function typeface(?Element $element): string
    {
        return trim((string) $element?->getAttribute('typeface'));
    }
}
